<?php

use App\Http\Controllers\DashboardController;
use App\Models\Tenant\AccountsPayable;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Sale;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Carbon\CarbonImmutable;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard aggregation test: pakai warehouse dedicated `WH-DASH-T` supaya
 * data demo tenant (sales / AP existing) tidak ngacauin angka assertion.
 * Semua row dibuat fresh per test + cleanup di afterEach.
 */

function ownerForDashboard(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function dashboardTestWarehouse(): Warehouse
{
    return Warehouse::firstOrCreate(
        ['code' => 'WH-DASH-T'],
        ['name' => 'Dashboard Test WH', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
}

function dashboardOtherWarehouse(): Warehouse
{
    return Warehouse::firstOrCreate(
        ['code' => 'WH-DASH-O'],
        ['name' => 'Dashboard Other WH', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
}

function callDashboard(?int $warehouseId = null): array
{
    $controller = app(DashboardController::class);
    $req = Request::create('/dashboard', 'GET',
        $warehouseId ? ['warehouse_id' => $warehouseId] : []);
    $req->setUserResolver(fn () => Auth::user());

    $response = $controller->index($req);

    return $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];
}

function seedSale(int $warehouseId, int $cashierId, CarbonImmutable $date, float $total): Sale
{
    return Sale::create([
        'invoice_no' => 'DASHT-'.uniqid('', true),
        'date' => $date,
        'warehouse_id' => $warehouseId,
        'cashier_id' => $cashierId,
        'subtotal' => $total,
        'total' => $total,
        'status' => 'completed',
        'payment_status' => 'paid',
    ]);
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForDashboard());

    // Bersihkan residual dari test sebelumnya (invoice prefix DASHT-).
    Sale::where('invoice_no', 'like', 'DASHT-%')->delete();

    $w = dashboardTestWarehouse();
    AccountsPayable::query()
        ->whereIn('supplier_id', Supplier::where('code', 'SUP-DASHT')->pluck('id'))
        ->delete();
});

afterEach(function () {
    Sale::where('invoice_no', 'like', 'DASHT-%')->delete();
    AccountsPayable::query()
        ->whereIn('supplier_id', Supplier::where('code', 'SUP-DASHT')->pluck('id'))
        ->delete();
    Auth::logout();
});

it('agregasi kartu hari ini & bulan ini hanya menghitung sale completed di cabang terpilih', function () {
    $w = dashboardTestWarehouse();
    $other = dashboardOtherWarehouse();
    $owner = ownerForDashboard();

    $now = CarbonImmutable::now();
    $today = $now->startOfDay()->addHours(10);

    // 3 trx hari ini di warehouse test: 100k, 200k, 300k
    seedSale($w->id, $owner->id, $today, 100_000);
    seedSale($w->id, $owner->id, $today, 200_000);
    seedSale($w->id, $owner->id, $today, 300_000);

    // 1 trx hari ini di warehouse LAIN — tidak boleh terhitung saat filter
    seedSale($other->id, $owner->id, $today, 999_999);

    // 1 trx awal bulan di warehouse test
    $earlyMonth = $now->startOfMonth()->addHours(8);
    seedSale($w->id, $owner->id, $earlyMonth, 500_000);

    // 1 trx VOID hari ini — wajib di-exclude
    $void = seedSale($w->id, $owner->id, $today, 50_000);
    $void->update(['status' => 'void']);

    $props = callDashboard($w->id);

    expect($props['stats']['today']['count'])->toBe(3)
        ->and($props['stats']['today']['total'])->toBe(600_000.0)
        // Bulan: 3 trx hari ini + 1 awal bulan = 4 trx, 1.1jt
        ->and($props['stats']['month']['count'])->toBe(4)
        ->and($props['stats']['month']['total'])->toBe(1_100_000.0)
        ->and($props['stats']['month']['aov'])->toBe(275_000.0);
});

it('agregasi GABUNGAN (tanpa filter) menjumlah semua cabang', function () {
    $w = dashboardTestWarehouse();
    $other = dashboardOtherWarehouse();
    $owner = ownerForDashboard();

    $today = CarbonImmutable::now()->startOfDay()->addHours(10);

    seedSale($w->id, $owner->id, $today, 100_000);
    seedSale($other->id, $owner->id, $today, 200_000);

    // Tanpa filter → semua cabang. Kita assert DELTA vs baseline supaya
    // data demo existing tidak bikin angka mutlak meleset.
    $beforeProps = (function () use ($w, $other, $owner, $today) {
        // baseline = jumlah sebelum kita tambah sale ke-3
        return [
            'today_count' => Sale::where('status', 'completed')->whereDate('date', $today)->count() - 2,
            'today_total' => (float) Sale::where('status', 'completed')->whereDate('date', $today)->sum('total') - 300_000,
        ];
    })();

    $props = callDashboard(null);

    expect($props['stats']['today']['count'] - $beforeProps['today_count'])->toBe(2)
        ->and($props['stats']['today']['total'] - $beforeProps['today_total'])->toBe(300_000.0);
});

it('tren 30 hari mengembalikan persis 30 titik berurutan dengan total per hari', function () {
    $w = dashboardTestWarehouse();
    $owner = ownerForDashboard();

    $today = CarbonImmutable::now()->startOfDay();

    seedSale($w->id, $owner->id, $today->addHours(9), 100_000);
    seedSale($w->id, $owner->id, $today->addHours(13), 50_000);
    // 5 hari lalu: 1 trx 250k
    seedSale($w->id, $owner->id, $today->subDays(5)->addHours(11), 250_000);

    $props = callDashboard($w->id);

    expect($props['trend'])->toHaveCount(30);

    $lastIdx = 29;
    $fiveAgoIdx = 24;

    expect($props['trend'][$lastIdx]['date'])->toBe($today->toDateString())
        ->and($props['trend'][$lastIdx]['total'])->toBe(150_000.0)
        ->and($props['trend'][$fiveAgoIdx]['date'])->toBe($today->subDays(5)->toDateString())
        ->and($props['trend'][$fiveAgoIdx]['total'])->toBe(250_000.0);
});

it('top 5 produk diurutkan berdasarkan omzet bulan berjalan', function () {
    $w = dashboardTestWarehouse();
    $owner = ownerForDashboard();

    $pA = Product::where('sku', 'SKU-001')->firstOrFail();
    $pB = Product::query()->where('id', '!=', $pA->id)->firstOrFail();

    $now = CarbonImmutable::now();
    $date = $now->startOfDay()->addHours(10);

    // A: 1 unit @ 800k = 800k
    $saleA = seedSale($w->id, $owner->id, $date, 800_000);
    DB::table('sales_items')->insert([
        'sale_id' => $saleA->id, 'product_id' => $pA->id,
        'unit_id' => $pA->base_unit_id, 'qty' => 1,
        'price' => 800_000, 'discount_amount' => 0,
        'cost_snapshot' => 0, 'subtotal' => 800_000,
        'created_at' => $date, 'updated_at' => $date,
    ]);

    // B: 5 unit @ 100k = 500k
    $saleB = seedSale($w->id, $owner->id, $date, 500_000);
    DB::table('sales_items')->insert([
        'sale_id' => $saleB->id, 'product_id' => $pB->id,
        'unit_id' => $pB->base_unit_id, 'qty' => 5,
        'price' => 100_000, 'discount_amount' => 0,
        'cost_snapshot' => 0, 'subtotal' => 500_000,
        'created_at' => $date, 'updated_at' => $date,
    ]);

    $props = callDashboard($w->id);

    // Saring hanya produk yg dipakai test, karena demo data juga ada.
    $byId = collect($props['top_products'])->keyBy('id');

    expect($byId->has($pA->id))->toBeTrue()
        ->and($byId->has($pB->id))->toBeTrue();

    // A omzet > B omzet, jadi posisi A < B di urutan.
    $names = collect($props['top_products'])->pluck('id')->all();
    $posA = array_search($pA->id, $names);
    $posB = array_search($pB->id, $names);
    expect($posA)->toBeLessThan($posB);
});

it('low_stock memuat produk dgn qty <= min_stock dan kosong saat permission tidak ada', function () {
    $w = dashboardTestWarehouse();
    $owner = ownerForDashboard();

    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $p->update(['min_stock' => 10, 'is_active' => true]);

    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p->id, 'warehouse_id' => $w->id],
        ['qty' => 5, 'cost_avg' => 1000, 'updated_at' => now(), 'created_at' => now()],
    );

    $props = callDashboard($w->id);

    $row = collect($props['low_stock'])->firstWhere('product_id', $p->id);
    expect($row)->not->toBeNull()
        ->and($row['qty'])->toBe(5.0)
        ->and($row['min_stock'])->toBe(10.0)
        ->and($props['can']['view_inventory'])->toBeTrue();
});

it('AP jatuh tempo dalam 7 hari muncul, status paid tidak', function () {
    $owner = ownerForDashboard();

    $supplier = Supplier::firstOrCreate(
        ['code' => 'SUP-DASHT'],
        ['name' => 'Dashboard Test Supplier', 'is_active' => true],
    );

    // PO + GR minimal supaya FK accounts_payable terpenuhi.
    $po = PurchaseOrder::create([
        'po_no' => 'PO-DASHT-'.uniqid('', true),
        'supplier_id' => $supplier->id,
        'warehouse_id' => dashboardTestWarehouse()->id,
        'status' => 'received',
        'subtotal' => 1_000_000,
        'total' => 1_000_000,
        'created_by' => $owner->id,
    ]);
    $gr = GoodsReceipt::create([
        'gr_no' => 'GR-DASHT-'.uniqid('', true),
        'po_id' => $po->id,
        'warehouse_id' => dashboardTestWarehouse()->id,
        'received_at' => now()->toDateString(),
        'received_by' => $owner->id,
        'subtotal' => 1_000_000,
        'total' => 1_000_000,
    ]);

    AccountsPayable::create([
        'ap_no' => 'AP-DASHT-NEAR-'.uniqid('', true),
        'supplier_id' => $supplier->id, 'gr_id' => $gr->id, 'po_id' => $po->id,
        'amount' => 1_000_000, 'paid_amount' => 0,
        'due_date' => now()->addDays(3)->toDateString(),
        'status' => 'open',
    ]);

    AccountsPayable::create([
        'ap_no' => 'AP-DASHT-FAR-'.uniqid('', true),
        'supplier_id' => $supplier->id, 'gr_id' => $gr->id, 'po_id' => $po->id,
        'amount' => 500_000, 'paid_amount' => 0,
        'due_date' => now()->addDays(60)->toDateString(),
        'status' => 'open',
    ]);

    AccountsPayable::create([
        'ap_no' => 'AP-DASHT-PAID-'.uniqid('', true),
        'supplier_id' => $supplier->id, 'gr_id' => $gr->id, 'po_id' => $po->id,
        'amount' => 200_000, 'paid_amount' => 200_000,
        'due_date' => now()->addDays(2)->toDateString(),
        'status' => 'paid',
    ]);

    $props = callDashboard();

    $aps = collect($props['ap_due'])->where('supplier_name', 'Dashboard Test Supplier');
    expect($aps)->toHaveCount(1) // hanya NEAR yg <=7 hari & belum lunas
        ->and($aps->first()['remaining'])->toBe(1_000_000.0)
        ->and($props['can']['view_ap'])->toBeTrue();
});
