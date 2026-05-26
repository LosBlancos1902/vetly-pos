<?php

use App\Http\Controllers\Inventory\StockCardController;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\StockCard;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * StockCard Excel export (extension Batch A).
 * Test: authorize, output xlsx, isi sesuai filter warehouse/periode.
 */

function ownerForScExp(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function userWithoutInvView(): TenantUser
{
    // Pakai user role yg TIDAK punya inventory.view. Apoteker punya inventory.view.
    // Buat user role pos.access saja → tidak punya inventory.view.
    $u = TenantUser::firstOrCreate(
        ['email' => 'noinv@test.local'],
        ['name' => 'No InvView User', 'password' => bcrypt('t'), 'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id],
    );
    // Role 'cashier' yg defined di seeder TIDAK punya inventory.view.
    if (! $u->hasRole('cashier')) {
        $u->assignRole('cashier');
    }

    return $u->fresh();
}

it('STOCKCARD EXPORT: authorize inventory.view + return xlsx', function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForScExp());

    $wh = Warehouse::firstOrCreate(
        ['code' => 'WH-SCEXP'],
        ['name' => 'StockCard Export WH', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    // Buat 1 movement supaya export ada row.
    StockMovementModel::query()->withoutGlobalScopes()->where('warehouse_id', $wh->id)->delete();
    StockMovementModel::create([
        'product_id' => $p1->id, 'warehouse_id' => $wh->id, 'type' => 'purchase',
        'qty' => 5, 'cost' => 1000, 'balance_qty_after' => 5, 'balance_cost_after' => 1000,
        'created_at' => '2027-09-01 10:00:00',
    ]);

    $controller = app(StockCardController::class);
    $req = Request::create('/inventory/stock-card/'.$p1->id.'/export', 'GET', [
        'warehouse_id' => $wh->id,
    ]);
    $req->setUserResolver(fn () => Auth::user());

    $response = $controller->export($req, $p1, app(StockCard::class));

    expect($response)->toBeInstanceOf(BinaryFileResponse::class);
    expect($response->headers->get('content-type'))->toContain('spreadsheetml.sheet');
    expect($response->getFile()->getSize())->toBeGreaterThan(0);

    StockMovementModel::query()->withoutGlobalScopes()->where('warehouse_id', $wh->id)->delete();
});

it('STOCKCARD EXPORT: user tanpa inventory.view → AuthorizationException', function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(userWithoutInvView());

    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    $wh = Warehouse::query()->firstOrFail();

    $controller = app(StockCardController::class);
    $req = Request::create('/inventory/stock-card/'.$p1->id.'/export', 'GET', [
        'warehouse_id' => $wh->id,
    ]);
    $req->setUserResolver(fn () => Auth::user());

    expect(fn () => $controller->export($req, $p1, app(StockCard::class)))
        ->toThrow(AuthorizationException::class);
});
