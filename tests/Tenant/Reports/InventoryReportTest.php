<?php

use App\Http\Controllers\Reports\InventoryReportController;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Inventory Reports (Batch A).
 * Test: valuation (qty × cost_avg), min_stock alert, movements, permission, export.
 *
 * Test pakai warehouse dedicated WH-INVREP supaya tidak ngacauin baseline.
 */

function ownerForInvRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForInvRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->first()
        ?? TenantUser::create([
            'name' => 'Test Cashier InvRep', 'email' => 'cashier-invrep@test.local',
            'password' => bcrypt('test'), 'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ])->assignRole('cashier');
}

function invRepWh(): Warehouse
{
    return Warehouse::firstOrCreate(
        ['code' => 'WH-INVREP'],
        ['name' => 'InvRep Test WH', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
}

function callInvRep(string $method, array $params = [])
{
    $controller = app(InventoryReportController::class);
    $req = Request::create('/reports/inventory/'.$method, 'GET', $params);
    $req->setUserResolver(fn () => Auth::user());

    return $controller->{$method}($req);
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForInvRep());

    // Cleanup baseline
    $wh = invRepWh();
    StockMovementModel::query()->withoutGlobalScopes()->where('warehouse_id', $wh->id)->delete();
    Inventory::query()->withoutGlobalScopes()->where('warehouse_id', $wh->id)->delete();
});

afterEach(function () {
    $wh = invRepWh();
    StockMovementModel::query()->withoutGlobalScopes()->where('warehouse_id', $wh->id)->delete();
    Inventory::query()->withoutGlobalScopes()->where('warehouse_id', $wh->id)->delete();
});

it('VALUATION: nilai = qty × cost_avg per (produk, warehouse)', function () {
    $wh = invRepWh();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail();

    Inventory::create(['product_id' => $p1->id, 'warehouse_id' => $wh->id, 'qty' => 10, 'cost_avg' => 5000]);
    Inventory::create(['product_id' => $p2->id, 'warehouse_id' => $wh->id, 'qty' => 5, 'cost_avg' => 8000]);

    $props = callInvRep('valuation', ['warehouse_id' => $wh->id])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['totals']['qty'])->toBe(15.0);
    expect($props['totals']['nilai'])->toBe(90000.0); // 10*5k + 5*8k

    $r1 = collect($props['rows'])->firstWhere('product_id', $p1->id);
    expect($r1->nilai)->toBe(50000.0);
});

it('MIN_STOCK: hanya produk dengan qty ≤ min_stock', function () {
    $wh = invRepWh();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail();

    // Set min_stock & qty:
    // p1: qty=2, min=5 → ALERT
    // p2: qty=20, min=5 → safe (TIDAK muncul)
    Product::where('id', $p1->id)->update(['min_stock' => 5]);
    Product::where('id', $p2->id)->update(['min_stock' => 5]);

    Inventory::create(['product_id' => $p1->id, 'warehouse_id' => $wh->id, 'qty' => 2, 'cost_avg' => 1000]);
    Inventory::create(['product_id' => $p2->id, 'warehouse_id' => $wh->id, 'qty' => 20, 'cost_avg' => 1000]);

    $props = callInvRep('minStock', ['warehouse_id' => $wh->id])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect(count($props['rows']))->toBe(1);
    expect($props['rows'][0]->product_id)->toBe($p1->id);
    expect((float) $props['rows'][0]->shortage)->toBe(3.0);
});

it('MOVEMENTS: list stock_movements dalam periode + filter type', function () {
    $wh = invRepWh();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    // Buat 3 movements: 1 purchase, 1 sale, 1 adjustment_plus
    StockMovementModel::create([
        'product_id' => $p1->id, 'warehouse_id' => $wh->id, 'type' => 'purchase',
        'qty' => 10, 'cost' => 5000, 'balance_qty_after' => 10, 'balance_cost_after' => 5000,
        'created_at' => '2027-11-01 10:00:00',
    ]);
    StockMovementModel::create([
        'product_id' => $p1->id, 'warehouse_id' => $wh->id, 'type' => 'sale',
        'qty' => 2, 'cost' => 5000, 'balance_qty_after' => 8, 'balance_cost_after' => 5000,
        'created_at' => '2027-11-02 10:00:00',
    ]);
    StockMovementModel::create([
        'product_id' => $p1->id, 'warehouse_id' => $wh->id, 'type' => 'adjustment_plus',
        'qty' => 1, 'cost' => 5000, 'balance_qty_after' => 9, 'balance_cost_after' => 5000,
        'created_at' => '2027-11-03 10:00:00',
    ]);

    $props = callInvRep('movements', [
        'warehouse_id' => $wh->id,
        'from' => '2027-11-01',
        'to' => '2027-11-30',
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    // Inertia serialize paginator → array dgn key 'data', 'total', dst.
    // Query sudah where warehouse_id=$wh->id, semua items pasti WH ini.
    expect($props['movements']['total'])->toBe(3);
    expect(count($props['movements']['data']))->toBe(3);

    // Filter type=sale
    $props2 = callInvRep('movements', [
        'warehouse_id' => $wh->id,
        'from' => '2027-11-01',
        'to' => '2027-11-30',
        'type' => 'sale',
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props2['movements']['total'])->toBe(1);
    expect($props2['movements']['data'][0]->type)->toBe('sale');
});

it('PERM: cashier → 403', function () {
    Auth::login(cashierForInvRep());
    expect(fn () => callInvRep('valuation'))->toThrow(AuthorizationException::class);
    expect(fn () => callInvRep('minStock'))->toThrow(AuthorizationException::class);
    expect(fn () => callInvRep('movements'))->toThrow(AuthorizationException::class);
});

it('EXPORT: valuation & minStock & movements → xlsx', function () {
    $wh = invRepWh();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    Inventory::create(['product_id' => $p1->id, 'warehouse_id' => $wh->id, 'qty' => 5, 'cost_avg' => 1000]);

    expect(callInvRep('valuation', ['warehouse_id' => $wh->id, 'export' => '1']))
        ->toBeInstanceOf(BinaryFileResponse::class);
    expect(callInvRep('minStock', ['warehouse_id' => $wh->id, 'export' => '1']))
        ->toBeInstanceOf(BinaryFileResponse::class);
    expect(callInvRep('movements', ['warehouse_id' => $wh->id, 'export' => '1']))
        ->toBeInstanceOf(BinaryFileResponse::class);
});
