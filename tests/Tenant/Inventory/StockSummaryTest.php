<?php

use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Master\ProductController;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

function ownerForStockSummary(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function stockSummaryWarehouse(): Warehouse
{
    return Warehouse::firstOrCreate(
        ['code' => 'WHT-SS'],
        ['name' => 'Summary WH', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForStockSummary());

    $w = stockSummaryWarehouse();
    Inventory::query()->withoutGlobalScopes()
        ->where('warehouse_id', $w->id)->delete();
});

afterEach(function () {
    $w = Warehouse::where('code', 'WHT-SS')->first();
    if ($w) {
        Inventory::query()->withoutGlobalScopes()
            ->where('warehouse_id', $w->id)->delete();
    }
    Auth::logout();
});

it('Stock.index() mengembalikan summary akurat saat filter ke 1 gudang', function () {
    $w = stockSummaryWarehouse();
    $pA = Product::where('sku', 'SKU-001')->firstOrFail();
    $pA->update(['min_stock' => 10]);
    $pB = Product::query()->where('id', '!=', $pA->id)->where('is_active', true)->firstOrFail();
    $pB->update(['min_stock' => 0]); // tidak ikut hitung low_stock

    // A: qty=5 (di bawah min 10) cost=1000 → low_stock +1, total_value=5000
    Inventory::query()->withoutGlobalScopes()->create([
        'product_id' => $pA->id, 'warehouse_id' => $w->id,
        'qty' => 5, 'cost_avg' => 1000,
    ]);
    // B: qty=20 cost=2000 → SKU +1, total_value=40000
    Inventory::query()->withoutGlobalScopes()->create([
        'product_id' => $pB->id, 'warehouse_id' => $w->id,
        'qty' => 20, 'cost_avg' => 2000,
    ]);
    // Row qty=0 — tidak boleh kehitung di sku_count
    $pC = Product::query()
        ->where('id', '!=', $pA->id)->where('id', '!=', $pB->id)
        ->where('is_active', true)->firstOrFail();
    Inventory::query()->withoutGlobalScopes()->create([
        'product_id' => $pC->id, 'warehouse_id' => $w->id,
        'qty' => 0, 'cost_avg' => 9999,
    ]);

    $controller = app(StockController::class);
    $req = Request::create('/inventory/stock', 'GET', ['warehouse_id' => $w->id]);
    $req->setUserResolver(fn () => Auth::user());

    $response = $controller->index($req);
    $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['summary'])->not->toBeNull()
        ->and($props['summary']['sku_count'])->toBe(2) // A + B, C qty=0 dikecualikan
        ->and($props['summary']['total_value'])->toBe(45000.0) // 5*1000 + 20*2000
        ->and($props['summary']['low_stock_count'])->toBe(1) // hanya A
        ->and($props['summaryWarehouse']['code'])->toBe('WHT-SS');
});

it('Stock.index() summary = null saat tidak filter warehouse', function () {
    $controller = app(StockController::class);
    $req = Request::create('/inventory/stock', 'GET');
    $req->setUserResolver(fn () => Auth::user());

    $response = $controller->index($req);
    $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['summary'])->toBeNull()
        ->and($props['summaryWarehouse'])->toBeNull();
});

it('ProductController.show() memuat inventories beserta warehouse', function () {
    $w = stockSummaryWarehouse();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    Inventory::query()->withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p->id, 'warehouse_id' => $w->id],
        ['qty' => 12, 'cost_avg' => 3000, 'updated_at' => now(), 'created_at' => now()],
    );

    $controller = app(ProductController::class);
    $response = $controller->show($p);
    $data = json_decode($response->getContent(), true);

    $invs = collect($data['product']['inventories'] ?? []);
    $row = $invs->firstWhere('warehouse_id', $w->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['qty'])->toBe(12.0)
        ->and($row['warehouse']['code'])->toBe('WHT-SS')
        ->and($row['warehouse']['warehouse_type'])->toBe('petshop');
});
