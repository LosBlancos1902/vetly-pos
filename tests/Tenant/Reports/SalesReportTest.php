<?php

use App\Http\Controllers\Reports\SalesReportController;
use App\Models\Tenant\Product;
use App\Models\Tenant\Sale;
use App\Models\Tenant\SaleItem;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sales Reports (Batch A).
 * Test fokus:
 *   - Multi-dim aggregation: total omzet match SUM(subtotal) per dimensi.
 *   - Void sale TIDAK masuk.
 *   - Margin: margin = omzet − qty×cost_snapshot.
 *   - Permission: cashier tanpa reports.sales.view → 403.
 *   - WarehouseScope: supervisor warehouse-X tidak lihat sale warehouse-Y.
 *   - Export Excel: jadi xlsx.
 *
 * Cleanup: invoice prefix SALREP-%.
 */

function ownerForSalRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForSalRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->first()
        ?? TenantUser::create([
            'name' => 'Test Cashier SalRep',
            'email' => 'cashier-salrep@test.local',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ])->assignRole('cashier');
}

function supervisorForSalRep(int $warehouseId): TenantUser
{
    $u = TenantUser::firstOrCreate(
        ['email' => 'sv-salrep@test.local'],
        ['name' => 'Test Supervisor SalRep', 'password' => bcrypt('t'), 'is_active' => true],
    );
    $u->update(['warehouse_id' => $warehouseId]);
    if (! $u->hasRole('supervisor')) {
        $u->assignRole('supervisor');
    }

    return $u->fresh();
}

function callSalesRep(string $method, array $params = [])
{
    $controller = app(SalesReportController::class);
    $req = Request::create('/reports/sales', 'GET', $params);
    $req->setUserResolver(fn () => Auth::user());

    return $controller->{$method}($req);
}

function seedSalesRep(array $rows): array
{
    $ids = [];
    foreach ($rows as $r) {
        $sale = Sale::create([
            'invoice_no' => 'SALREP-'.uniqid('', true),
            'date' => $r['date'],
            'warehouse_id' => $r['warehouse_id'],
            'cashier_id' => $r['cashier_id'],
            'subtotal' => $r['subtotal'],
            'total' => $r['subtotal'],
            'status' => $r['status'] ?? 'completed',
            'payment_status' => 'paid',
            'customer_id' => $r['customer_id'] ?? null,
        ]);
        foreach ($r['items'] as $it) {
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $it['product_id'],
                'unit_id' => $it['unit_id'],
                'qty' => $it['qty'],
                'price' => $it['price'],
                'cost_snapshot' => $it['cost_snapshot'] ?? 0,
                'subtotal' => $it['qty'] * $it['price'],
            ]);
        }
        $ids[] = $sale->id;
    }

    return $ids;
}

function cleanupSalRep(): void
{
    Sale::where('invoice_no', 'like', 'SALREP-%')->delete();
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForSalRep());
    cleanupSalRep();
});

afterEach(function () {
    cleanupSalRep();
});

it('MULTI-DIM produk: omzet per produk = SUM(subtotal) per product_id', function () {
    $wh = Warehouse::query()->firstOrFail();
    $owner = ownerForSalRep();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    seedSalesRep([
        [
            'date' => '2027-03-01 10:00:00',
            'warehouse_id' => $wh->id,
            'cashier_id' => $owner->id,
            'subtotal' => 100000,
            'items' => [[
                'product_id' => $p1->id,
                'unit_id' => $p1->base_unit_id,
                'qty' => 2,
                'price' => 50000,
                'cost_snapshot' => 30000,
            ]],
        ],
        [
            'date' => '2027-03-05 11:00:00',
            'warehouse_id' => $wh->id,
            'cashier_id' => $owner->id,
            'subtotal' => 75000,
            'items' => [[
                'product_id' => $p1->id,
                'unit_id' => $p1->base_unit_id,
                'qty' => 1,
                'price' => 75000,
                'cost_snapshot' => 50000,
            ]],
        ],
        // Void sale — TIDAK boleh masuk
        [
            'date' => '2027-03-10 11:00:00',
            'warehouse_id' => $wh->id,
            'cashier_id' => $owner->id,
            'subtotal' => 999000,
            'status' => 'void',
            'items' => [[
                'product_id' => $p1->id,
                'unit_id' => $p1->base_unit_id,
                'qty' => 1,
                'price' => 999000,
                'cost_snapshot' => 0,
            ]],
        ],
    ]);

    $props = callSalesRep('index', [
        'dim' => 'produk',
        'from' => '2027-03-01',
        'to' => '2027-03-31',
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $p1Row = collect($props['rows'])->firstWhere('key_id', $p1->id);
    expect($p1Row)->not->toBeNull();
    expect($p1Row['omzet'])->toBe(175000.0); // 100k+75k, void 999k excluded
    expect($p1Row['qty'])->toBe(3.0); // 2 + 1
    expect($p1Row['trx_count'])->toBe(2); // 2 sales (void excluded)
});

it('MULTI-DIM cabang: pisah per warehouse_id', function () {
    $wh1 = Warehouse::firstOrCreate(
        ['code' => 'WH-SALREP-1'],
        ['name' => 'SalRep WH1', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    $wh2 = Warehouse::firstOrCreate(
        ['code' => 'WH-SALREP-2'],
        ['name' => 'SalRep WH2', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    $owner = ownerForSalRep();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    seedSalesRep([
        [
            'date' => '2027-03-01 10:00:00', 'warehouse_id' => $wh1->id, 'cashier_id' => $owner->id,
            'subtotal' => 100000,
            'items' => [['product_id' => $p1->id, 'unit_id' => $p1->base_unit_id, 'qty' => 1, 'price' => 100000]],
        ],
        [
            'date' => '2027-03-02 10:00:00', 'warehouse_id' => $wh2->id, 'cashier_id' => $owner->id,
            'subtotal' => 250000,
            'items' => [['product_id' => $p1->id, 'unit_id' => $p1->base_unit_id, 'qty' => 1, 'price' => 250000]],
        ],
    ]);

    $props = callSalesRep('index', [
        'dim' => 'cabang',
        'from' => '2027-03-01',
        'to' => '2027-03-31',
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $r1 = collect($props['rows'])->firstWhere('key_id', $wh1->id);
    $r2 = collect($props['rows'])->firstWhere('key_id', $wh2->id);
    expect($r1['omzet'])->toBe(100000.0);
    expect($r2['omzet'])->toBe(250000.0);
});

it('MARGIN: margin = omzet − sum(qty × cost_snapshot)', function () {
    $wh = Warehouse::query()->firstOrFail();
    $owner = ownerForSalRep();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    // jual 5 @ 20k cost 12k → omzet 100k, hpp 60k, margin 40k = 40%
    seedSalesRep([
        [
            'date' => '2027-04-01 10:00:00', 'warehouse_id' => $wh->id, 'cashier_id' => $owner->id,
            'subtotal' => 100000,
            'items' => [[
                'product_id' => $p1->id, 'unit_id' => $p1->base_unit_id,
                'qty' => 5, 'price' => 20000, 'cost_snapshot' => 12000,
            ]],
        ],
    ]);

    $props = callSalesRep('margin', [
        'dim' => 'produk',
        'from' => '2027-04-01',
        'to' => '2027-04-30',
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $r = collect($props['rows'])->firstWhere('key_id', $p1->id);
    expect($r['omzet'])->toBe(100000.0);
    expect($r['hpp'])->toBe(60000.0);
    expect($r['margin'])->toBe(40000.0);
    expect($r['margin_pct'])->toBe(40.0);
});

it('PERM: cashier tanpa reports.sales.view → AuthorizationException', function () {
    Auth::login(cashierForSalRep());
    expect(fn () => callSalesRep('index'))->toThrow(AuthorizationException::class);
    expect(fn () => callSalesRep('margin'))->toThrow(AuthorizationException::class);
});

it('SCOPE: supervisor fixed-to-WH tidak bisa intip warehouse lain via query param', function () {
    $wh1 = Warehouse::firstOrCreate(
        ['code' => 'WH-SALREP-SV1'],
        ['name' => 'SalRep SV1', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    $wh2 = Warehouse::firstOrCreate(
        ['code' => 'WH-SALREP-SV2'],
        ['name' => 'SalRep SV2', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    $owner = ownerForSalRep();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    // sale 100k di WH1, 250k di WH2
    seedSalesRep([
        ['date' => '2027-05-01 10:00:00', 'warehouse_id' => $wh1->id, 'cashier_id' => $owner->id, 'subtotal' => 100000,
            'items' => [['product_id' => $p1->id, 'unit_id' => $p1->base_unit_id, 'qty' => 1, 'price' => 100000]]],
        ['date' => '2027-05-02 10:00:00', 'warehouse_id' => $wh2->id, 'cashier_id' => $owner->id, 'subtotal' => 250000,
            'items' => [['product_id' => $p1->id, 'unit_id' => $p1->base_unit_id, 'qty' => 1, 'price' => 250000]]],
    ]);

    // Login as supervisor fixed to WH1 → coba intip WH2
    Auth::login(supervisorForSalRep($wh1->id));

    $props = callSalesRep('index', [
        'dim' => 'cabang',
        'from' => '2027-05-01',
        'to' => '2027-05-31',
        'warehouse_id' => $wh2->id, // try bypass
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    // Hasil HARUS hanya WH1 (filter user.warehouse_id force, request param diabaikan)
    $rWh1 = collect($props['rows'])->firstWhere('key_id', $wh1->id);
    $rWh2 = collect($props['rows'])->firstWhere('key_id', $wh2->id);

    expect($rWh1)->not->toBeNull();
    expect($rWh1['omzet'])->toBe(100000.0);
    expect($rWh2)->toBeNull(); // WH2 BUKAN warehouse-nya, tidak boleh muncul
});

it('EXPORT: sales export=1 returns BinaryFileResponse xlsx', function () {
    $response = callSalesRep('index', [
        'dim' => 'produk',
        'from' => '2027-06-01',
        'to' => '2027-06-30',
        'export' => '1',
    ]);

    expect($response)->toBeInstanceOf(BinaryFileResponse::class);
    expect($response->headers->get('content-type'))->toContain('spreadsheetml.sheet');
});
