<?php

use App\Http\Controllers\POS\CashierController;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Journal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\Sale;
use App\Models\Tenant\ServiceBundle;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\JournalEngine;
use App\Services\ServiceBundleService;
use App\Services\StockMovement;
use App\Services\UnitConverter;
use App\Services\VetlySyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

function resetServiceSaleState(): Warehouse
{
    $warehouse = Warehouse::query()->firstOrFail();

    $stocks = [
        'RAW-VAKRAB' => ['qty' => 30, 'cost' => 75000],
        'RAW-SPUIT3' => ['qty' => 100, 'cost' => 2500],
        'RAW-KAPAS' => ['qty' => 5, 'cost' => 35000],
    ];
    foreach ($stocks as $sku => $s) {
        $pid = Product::where('sku', $sku)->value('id');
        Inventory::query()->withoutGlobalScopes()
            ->where('product_id', $pid)
            ->where('warehouse_id', $warehouse->id)
            ->update(['qty' => $s['qty'], 'cost_avg' => $s['cost']]);
    }
    StockMovementModel::query()->withoutGlobalScopes()->delete();
    Journal::query()->delete(); // JournalEntry cascades via FK

    // Restore is_optional flags + drop any sibling bundles a sister test may
    // have created against SVC-VAKRAB.
    $bundle = ServiceBundle::where('name', 'Vaksin Rabies')->firstOrFail();
    \DB::table('service_bundle_items')->where('bundle_id', $bundle->id)->update(['is_optional' => false]);
    ServiceBundle::query()->where('id', '!=', $bundle->id)->delete();

    Auth::login(TenantUser::query()->first());

    return $warehouse;
}

it('sells Vaksin Rabies via picker flow: consumes materials, posts balanced journal with 4103', function () {
    $warehouse = resetServiceSaleState();
    $svc = Product::where('sku', 'SVC-VAKRAB')->firstOrFail();

    $controller = new CashierController;
    $request = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [[
            'product_id' => $svc->id,
            'unit_id' => $svc->base_unit_id,
            'qty' => 1,
            'price' => 250000,
        ]],
        'payments' => [[
            'method' => 'cash',
            'amount' => 250000,
        ]],
    ]);
    $request->setUserResolver(fn () => Auth::user());

    $stock = new StockMovement(new HppCalculator, new UnitConverter);
    $journal = new JournalEngine;
    $bundles = new ServiceBundleService($stock, new UnitConverter);
    // VetlySyncService is harmless for customer-less sales (early-returns)
    // but we still instantiate it without hitting any real HTTP.
    $vetly = new VetlySyncService;

    $response = $controller->store($request, $stock, $journal, $bundles, $vetly);
    $body = json_decode($response->getContent(), true);

    expect($body['sale']['total'])->toEqual('250000.00');
    $sale = Sale::findOrFail($body['sale']['id']);

    // Raw materials consumed by ServiceBundleService::execute()
    $vialId = Product::where('sku', 'RAW-VAKRAB')->value('id');
    $spuitId = Product::where('sku', 'RAW-SPUIT3')->value('id');

    expect((float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $vialId)->where('warehouse_id', $warehouse->id)->value('qty'))->toBe(29.0);
    expect((float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $spuitId)->where('warehouse_id', $warehouse->id)->value('qty'))->toBe(99.0);

    // Service consumption movements were recorded (not retail 'sale' movements).
    expect(StockMovementModel::query()->withoutGlobalScopes()
        ->where('type', 'service_consumption')->count())->toBe(3);
    expect(StockMovementModel::query()->withoutGlobalScopes()
        ->where('type', 'sale')->count())->toBe(0);

    // Journal balanced and credited 4103 Pendapatan Jasa for 250k.
    $j = Journal::where('ref_type', Sale::class)->where('ref_id', $sale->id)->firstOrFail();
    $coa4103 = Coa::where('code', '4103')->value('id');
    $serviceRevenue = (float) JournalEntry::where('journal_id', $j->id)
        ->where('coa_id', $coa4103)->value('credit');
    expect($serviceRevenue)->toBe(250000.0);

    $debits = (float) JournalEntry::where('journal_id', $j->id)->sum('debit');
    $credits = (float) JournalEntry::where('journal_id', $j->id)->sum('credit');
    expect($debits)->toBe($credits);
});
