<?php

use App\Http\Controllers\Master\WarehouseController;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\StockMovement;
use App\Services\UnitConverter;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse CRUD test: pakai kode bertanda "WHT-" supaya cleanup deterministic.
 * Tidak menyentuh stok demo existing (TOKO-DEMO).
 */

function ownerForWarehouse(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function callWarehouse(string $method, array $payload = [], ?Warehouse $warehouse = null)
{
    $controller = app(WarehouseController::class);

    return match ($method) {
        'index' => (function () use ($controller, $payload) {
            $req = Request::create('/master/warehouses', 'GET', $payload);
            $req->setUserResolver(fn () => Auth::user());

            return $controller->index($req);
        })(),
        'store' => (function () use ($controller, $payload) {
            $req = Request::create('/master/warehouses', 'POST', $payload);
            $req->setUserResolver(fn () => Auth::user());

            return $controller->store($req);
        })(),
        'update' => (function () use ($controller, $payload, $warehouse) {
            $req = Request::create('/master/warehouses/'.$warehouse->id, 'PUT', $payload);
            $req->setUserResolver(fn () => Auth::user());

            return $controller->update($req, $warehouse);
        })(),
        'destroy' => $controller->destroy($warehouse),
    };
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForWarehouse());

    // Cleanup residual dari run sebelumnya (catch warehouses dari semua
    // test gudang: WHT-, WHT-TRF-, WHT-ADJ, dll).
    $whIds = Warehouse::where('code', 'like', 'WHT-%')->pluck('id');

    // FK dependencies: clean stock_transfers + items dulu (FK ke warehouses).
    $transferIds = \App\Models\Tenant\StockTransfer::query()
        ->whereIn('source_warehouse_id', $whIds)
        ->orWhereIn('dest_warehouse_id', $whIds)->pluck('id');
    if ($transferIds->isNotEmpty()) {
        \App\Models\Tenant\StockTransferItem::whereIn('transfer_id', $transferIds)->delete();
        \App\Models\Tenant\StockTransfer::whereIn('id', $transferIds)->delete();
    }

    StockMovementModel::query()->withoutGlobalScopes()
        ->whereIn('warehouse_id', $whIds)->delete();
    Inventory::query()->withoutGlobalScopes()
        ->whereIn('warehouse_id', $whIds)->delete();
    TenantUser::whereIn('warehouse_id', $whIds)->update(['warehouse_id' => null]);
    Warehouse::whereIn('id', $whIds)->delete();
});

afterEach(function () {
    Auth::logout();
});

it('owner bisa CREATE gudang baru', function () {
    $resp = callWarehouse('store', [
        'code' => 'WHT-NEW',
        'name' => 'Cabang Baru',
        'warehouse_type' => 'petshop',
        'address' => 'Jl. Test',
        'is_active' => true,
        'is_default' => false,
    ]);

    $w = Warehouse::where('code', 'WHT-NEW')->first();
    expect($w)->not->toBeNull()
        ->and($w->name)->toBe('Cabang Baru')
        ->and($w->warehouse_type)->toBe('petshop')
        ->and((bool) $w->is_active)->toBeTrue();
});

it('VALIDASI: code unik per tenant — duplikat ditolak', function () {
    Warehouse::create([
        'code' => 'WHT-DUP', 'name' => 'A',
        'warehouse_type' => 'petshop', 'is_active' => true,
    ]);

    expect(fn () => callWarehouse('store', [
        'code' => 'WHT-DUP', 'name' => 'B',
        'warehouse_type' => 'petshop',
        'is_active' => true, 'is_default' => false,
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('toggle is_default=true MENGOSONGKAN default gudang lain (single-default invariant)', function () {
    $old = Warehouse::create([
        'code' => 'WHT-OLDDEF', 'name' => 'Old Def',
        'warehouse_type' => 'petshop', 'is_active' => true, 'is_default' => true,
    ]);

    callWarehouse('store', [
        'code' => 'WHT-NEWDEF', 'name' => 'New Def',
        'warehouse_type' => 'petshop',
        'is_active' => true, 'is_default' => true,
    ]);

    $old->refresh();
    $new = Warehouse::where('code', 'WHT-NEWDEF')->first();

    expect((bool) $new->is_default)->toBeTrue()
        ->and((bool) $old->is_default)->toBeFalse();
});

it('GUARD DEACTIVATE: tidak bisa nonaktifin default kalau dia satu-satunya default aktif', function () {
    // Unset semua default existing (di demo tenant ada TOKO-DEMO is_default=true).
    Warehouse::where('is_default', true)->update(['is_default' => false]);

    $only = Warehouse::create([
        'code' => 'WHT-ONLYDEF', 'name' => 'Only',
        'warehouse_type' => 'petshop', 'is_active' => true, 'is_default' => true,
    ]);

    expect(fn () => callWarehouse('update', [
        'code' => 'WHT-ONLYDEF', 'name' => 'Only',
        'warehouse_type' => 'petshop',
        'is_active' => false, 'is_default' => true,
    ], $only))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('GUARD DESTROY: gudang dgn stok > 0 di-soft-deactivate, bukan hard-delete', function () {
    $w = Warehouse::create([
        'code' => 'WHT-WITHSTOCK', 'name' => 'With Stock',
        'warehouse_type' => 'petshop', 'is_active' => true,
    ]);
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    Inventory::query()->withoutGlobalScopes()->create([
        'product_id' => $p->id, 'warehouse_id' => $w->id,
        'qty' => 5, 'cost_avg' => 10000,
    ]);

    callWarehouse('destroy', warehouse: $w);

    $w->refresh();
    expect((bool) $w->is_active)->toBeFalse()
        ->and(Warehouse::find($w->id))->not->toBeNull(); // BELUM ke-hard-delete
});

it('GUARD DESTROY: gudang dgn stock_movement (histori) di-soft-deactivate', function () {
    $w = Warehouse::create([
        'code' => 'WHT-WITHHIST', 'name' => 'With History',
        'warehouse_type' => 'petshop', 'is_active' => true,
    ]);
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    // Catat 1 movement via engine asli (jangan bypass).
    Inventory::query()->withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p->id, 'warehouse_id' => $w->id],
        ['qty' => 100, 'cost_avg' => 5000, 'updated_at' => now(), 'created_at' => now()],
    );
    (new StockMovement(new HppCalculator, new UnitConverter))->record(
        product: $p, warehouse: $w, type: 'sale', qty: 1, cost: 5000,
    );
    // Hapus inventory rownya (qty diset 0) supaya guard fall through ke movements.
    Inventory::query()->withoutGlobalScopes()
        ->where('warehouse_id', $w->id)
        ->update(['qty' => 0]);

    callWarehouse('destroy', warehouse: $w);

    $w->refresh();
    expect((bool) $w->is_active)->toBeFalse();
    expect(Warehouse::find($w->id))->not->toBeNull();
    // Histori movements TIDAK ke-hapus.
    expect(StockMovementModel::query()->withoutGlobalScopes()
        ->where('warehouse_id', $w->id)->count())->toBeGreaterThan(0);
});

it('DESTROY HARD: gudang tanpa stok/movement/user bisa di-hard-delete', function () {
    $w = Warehouse::create([
        'code' => 'WHT-EMPTY', 'name' => 'Empty',
        'warehouse_type' => 'petshop', 'is_active' => true,
    ]);

    callWarehouse('destroy', warehouse: $w);

    expect(Warehouse::find($w->id))->toBeNull();
});

it('GUARD DESTROY: default warehouse tunggal tidak bisa dihapus', function () {
    Warehouse::where('is_default', true)->update(['is_default' => false]);

    $only = Warehouse::create([
        'code' => 'WHT-DEFONLY', 'name' => 'Only Default',
        'warehouse_type' => 'petshop', 'is_active' => true, 'is_default' => true,
    ]);

    expect(fn () => callWarehouse('destroy', warehouse: $only))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('index() menampilkan agregasi sku_count + user_count per gudang', function () {
    $w = Warehouse::create([
        'code' => 'WHT-SUMMARY', 'name' => 'Sum',
        'warehouse_type' => 'petshop', 'is_active' => true,
    ]);
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    Inventory::query()->withoutGlobalScopes()->create([
        'product_id' => $p->id, 'warehouse_id' => $w->id,
        'qty' => 7, 'cost_avg' => 5000,
    ]);

    $response = callWarehouse('index');
    $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $row = collect($props['warehouses']['data'])->firstWhere('code', 'WHT-SUMMARY');
    expect($row)->not->toBeNull()
        ->and($row['sku_count'])->toBe(1)
        ->and($row['user_count'])->toBe(0);
});
