<?php

use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\ServiceBundle;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\ServiceBundleService;
use App\Services\StockMovement;
use App\Services\UnitConverter;
use Illuminate\Support\Facades\Auth;

function resetServiceState(): array
{
    $warehouse = Warehouse::query()->firstOrFail();
    // Pick the seeded Vaksin Rabies bundle by name to avoid grabbing
    // a stray bundle created in a previous test in this file.
    $bundle = ServiceBundle::with('items')->where('name', 'Vaksin Rabies')->firstOrFail();

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

    // Restore is_optional flags on the demo bundle (a sibling test may have flipped them).
    \DB::table('service_bundle_items')->where('bundle_id', $bundle->id)->update(['is_optional' => false]);

    // Clean up any extra bundles created by tests (e.g. "pure service").
    ServiceBundle::query()->where('id', '!=', $bundle->id)->delete();

    Auth::login(TenantUser::query()->first());

    return [$bundle->fresh('items'), $warehouse];
}

function makeServiceBundleService(): ServiceBundleService
{
    return new ServiceBundleService(
        new StockMovement(new HppCalculator, new UnitConverter),
        new UnitConverter,
    );
}

it('calculates cost: 1 vial 75000 + 1 spuit 2500 + 0.01 box 35000 = 77850', function () {
    [$bundle, $warehouse] = resetServiceState();

    $costing = makeServiceBundleService()->calculateCost($bundle, $warehouse);

    // 75000 + 2500 + 0.01*35000 = 77500 + 350 = 77850
    expect((float) $costing['cost_total'])->toBe(77850.0);
});

it('execute consumes all mandatory components and computes margin', function () {
    [$bundle, $warehouse] = resetServiceState();

    $result = makeServiceBundleService()->execute($bundle, $warehouse, Auth::user());

    expect((float) $result['cost_total'])->toBe(77850.0)
        ->and((float) $result['service_fee'])->toBe(250000.0)
        ->and((float) $result['margin'])->toBe(172150.0);

    // Vial berkurang 1.
    $vialQty = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', Product::where('sku', 'RAW-VAKRAB')->value('id'))
        ->where('warehouse_id', $warehouse->id)->value('qty');
    expect($vialQty)->toBe(29.0);

    // 3 service_consumption movements.
    expect(StockMovementModel::query()->withoutGlobalScopes()
        ->where('type', 'service_consumption')->count())->toBe(3);
});

it('skips optional items when not included', function () {
    [$bundle, $warehouse] = resetServiceState();

    // Mark kapas as optional in this run.
    $kapasId = (int) Product::where('sku', 'RAW-KAPAS')->value('id');
    \DB::table('service_bundle_items')->where('bundle_id', $bundle->id)
        ->where('component_product_id', $kapasId)->update(['is_optional' => true]);
    $bundle->load('items');

    // Execute WITHOUT including kapas.
    $result = makeServiceBundleService()->execute($bundle, $warehouse, Auth::user(), optionalIncluded: []);

    // Cost excludes kapas.
    expect((float) $result['cost_total'])->toBe(77500.0);

    // Only 2 consumption movements.
    expect(StockMovementModel::query()->withoutGlobalScopes()
        ->where('type', 'service_consumption')->count())->toBe(2);

    // Kapas inventory unchanged.
    $kapasQty = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $kapasId)
        ->where('warehouse_id', $warehouse->id)->value('qty');
    expect($kapasQty)->toBe(5.0);
});

it('handles a pure service (bundle with no items) without inventory movement', function () {
    [$bundle, $warehouse] = resetServiceState();

    $emptyBundle = ServiceBundle::create([
        'product_id' => Product::where('sku', 'SVC-VAKRAB')->value('id'),
        'name' => 'Konsultasi Dokter',
        'service_fee' => 50000,
        'is_active' => true,
    ]);

    $result = makeServiceBundleService()->execute($emptyBundle->fresh('items'), $warehouse, Auth::user());

    expect((float) $result['cost_total'])->toBe(0.0)
        ->and((float) $result['service_fee'])->toBe(50000.0)
        ->and((float) $result['margin'])->toBe(50000.0)
        ->and($result['movements'])->toBe([]);
});
