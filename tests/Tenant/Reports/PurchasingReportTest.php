<?php

use App\Http\Controllers\Reports\PurchasingReportController;
use App\Models\Tenant\AccountsPayable;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptItem;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderItem;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Purchasing Reports (Batch A).
 * Test fokus:
 *   - Pembelian per supplier: nilai = SUM(gri.subtotal) join supplier.
 *   - AP Aging bucket benar: 0-30 / 31-60 / 61-90 / >90.
 *   - Permission: cashier → 403.
 *   - Export Excel.
 *
 * Cleanup: prefix 'PRREP-%' utk PO/GR/AP.
 */

function ownerForPrRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForPrRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->first()
        ?? TenantUser::create([
            'name' => 'Test Cashier PrRep',
            'email' => 'cashier-prrep@test.local',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ])->assignRole('cashier');
}

function callPurRep(string $method, array $params = [])
{
    $controller = app(PurchasingReportController::class);
    $req = Request::create('/reports/purchasing', 'GET', $params);
    $req->setUserResolver(fn () => Auth::user());

    return $controller->{$method}($req);
}

function seedPurchase(Supplier $sup, Warehouse $wh, Product $p, float $qty, float $unitPrice, string $date): array
{
    $sub = $qty * $unitPrice;
    $po = PurchaseOrder::create([
        'po_no' => 'PRREP-'.uniqid('po', true),
        'supplier_id' => $sup->id,
        'warehouse_id' => $wh->id,
        'payment_type' => 'cash',
        'payment_term_days' => 0,
        'status' => 'received',
        'subtotal' => $sub,
        'total' => $sub,
        'created_by' => Auth::id(),
    ]);
    $poItem = PurchaseOrderItem::create([
        'po_id' => $po->id,
        'product_id' => $p->id,
        'unit_id' => $p->base_unit_id,
        'qty_ordered' => $qty,
        'qty_received' => $qty,
        'unit_price' => $unitPrice,
        'subtotal' => $sub,
    ]);
    $gr = GoodsReceipt::create([
        'gr_no' => 'PRREP-'.uniqid('gr', true),
        'po_id' => $po->id,
        'warehouse_id' => $wh->id,
        'received_at' => $date,
        'received_by' => Auth::id(),
        'subtotal' => $sub,
        'total' => $sub,
    ]);
    GoodsReceiptItem::create([
        'gr_id' => $gr->id,
        'po_item_id' => $poItem->id,
        'product_id' => $p->id,
        'unit_id' => $p->base_unit_id,
        'qty_received' => $qty,
        'unit_price' => $unitPrice,
        'subtotal' => $sub,
    ]);

    return ['po' => $po, 'gr' => $gr];
}

function seedAp(Supplier $sup, GoodsReceipt $gr, PurchaseOrder $po, float $amount, float $paid, string $dueDate): AccountsPayable
{
    return AccountsPayable::create([
        'ap_no' => 'PRREP-'.uniqid('ap', true),
        'supplier_id' => $sup->id,
        'gr_id' => $gr->id,
        'po_id' => $po->id,
        'amount' => $amount,
        'paid_amount' => $paid,
        'due_date' => $dueDate,
        'status' => $paid >= $amount ? 'paid' : ($paid > 0 ? 'partially_paid' : 'open'),
    ]);
}

function cleanupPrRep(): void
{
    AccountsPayable::where('ap_no', 'like', 'PRREP-%')->delete();
    GoodsReceiptItem::whereHas('goodsReceipt', fn ($q) => $q->where('gr_no', 'like', 'PRREP-%'))->delete();
    GoodsReceipt::where('gr_no', 'like', 'PRREP-%')->delete();
    PurchaseOrderItem::whereHas('purchaseOrder', fn ($q) => $q->where('po_no', 'like', 'PRREP-%'))->delete();
    PurchaseOrder::where('po_no', 'like', 'PRREP-%')->delete();
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForPrRep());
    cleanupPrRep();
});

afterEach(function () {
    cleanupPrRep();
});

it('PEMBELIAN per supplier: nilai = SUM(gri.subtotal)', function () {
    $sup = Supplier::firstOrCreate(
        ['code' => 'SUP-PRREP-A'],
        ['name' => 'PrRep Supplier A', 'is_active' => true, 'payment_term_days' => 0],
    );
    $wh = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    seedPurchase($sup, $wh, $p1, qty: 10, unitPrice: 5000, date: '2027-07-01'); // 50k
    seedPurchase($sup, $wh, $p1, qty: 5, unitPrice: 6000, date: '2027-07-15');   // 30k

    $props = callPurRep('index', [
        'dim' => 'supplier',
        'from' => '2027-07-01',
        'to' => '2027-07-31',
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $supRow = collect($props['rows'])->firstWhere('key_id', $sup->id);
    expect($supRow)->not->toBeNull();
    expect($supRow['nilai'])->toBe(80000.0); // 50k+30k
    expect($supRow['qty'])->toBe(15.0); // 10+5
    expect($supRow['trx_count'])->toBe(2);
});

it('AP AGING: bucket 0-30/31-60/61-90/>90 correct by days from due_date', function () {
    $sup = Supplier::firstOrCreate(
        ['code' => 'SUP-PRREP-AP'],
        ['name' => 'PrRep AP Supplier', 'is_active' => true, 'payment_term_days' => 30],
    );
    $wh = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    // Setup 4 AP dengan due_date yang masing-masing fall ke bucket berbeda
    // referenced as_of='2027-08-15'.
    // 0-30: due 2027-08-01 → 14 hari overdue → 0-30 ✓
    // 31-60: due 2027-07-01 → 45 hari overdue → 31-60 ✓
    // 61-90: due 2027-06-01 → 75 hari overdue → 61-90 ✓
    // >90: due 2027-04-01 → 136 hari overdue → >90 ✓
    foreach ([
        ['2027-08-01', 100000, '0-30'],
        ['2027-07-01', 150000, '31-60'],
        ['2027-06-01', 200000, '61-90'],
        ['2027-04-01', 300000, '>90'],
    ] as [$due, $amount, $expectedBucket]) {
        $pkg = seedPurchase($sup, $wh, $p1, qty: 1, unitPrice: $amount, date: $due);
        seedAp($sup, $pkg['gr'], $pkg['po'], amount: $amount, paid: 0, dueDate: $due);
    }

    $props = callPurRep('apAging', ['as_of' => '2027-08-15'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    // Filter to APs created by THIS test (ap_no LIKE PRREP-%)
    $myAps = collect($props['rows'])->filter(fn ($r) => str_starts_with($r->ap_no, 'PRREP-'));
    expect($myAps->count())->toBe(4);

    $byBucket = $myAps->groupBy('bucket');
    expect($byBucket->get('0-30')->first()->remaining)->toBe(100000.0);
    expect($byBucket->get('31-60')->first()->remaining)->toBe(150000.0);
    expect($byBucket->get('61-90')->first()->remaining)->toBe(200000.0);
    expect($byBucket->get('>90')->first()->remaining)->toBe(300000.0);
});

it('AP AGING: status=paid TIDAK termasuk (amount = paid_amount)', function () {
    $sup = Supplier::firstOrCreate(
        ['code' => 'SUP-PRREP-AP2'],
        ['name' => 'PrRep AP2 Supplier', 'is_active' => true, 'payment_term_days' => 0],
    );
    $wh = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    // 1 fully paid → exclude. 1 partial → include.
    $pkg1 = seedPurchase($sup, $wh, $p1, qty: 1, unitPrice: 50000, date: '2027-09-01');
    seedAp($sup, $pkg1['gr'], $pkg1['po'], amount: 50000, paid: 50000, dueDate: '2027-09-01');
    AccountsPayable::where('po_id', $pkg1['po']->id)->update(['status' => 'paid']);

    $pkg2 = seedPurchase($sup, $wh, $p1, qty: 1, unitPrice: 80000, date: '2027-09-05');
    seedAp($sup, $pkg2['gr'], $pkg2['po'], amount: 80000, paid: 30000, dueDate: '2027-09-05');

    $props = callPurRep('apAging', ['as_of' => '2027-09-15'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $myAps = collect($props['rows'])->filter(fn ($r) => str_starts_with($r->ap_no, 'PRREP-'));
    expect($myAps->count())->toBe(1); // hanya partial
    expect($myAps->first()->remaining)->toBe(50000.0); // 80k - 30k
});

it('PERM: cashier → 403', function () {
    Auth::login(cashierForPrRep());
    expect(fn () => callPurRep('index'))->toThrow(AuthorizationException::class);
    expect(fn () => callPurRep('apAging'))->toThrow(AuthorizationException::class);
});

it('EXPORT: purchasing & ap-aging → xlsx', function () {
    $r1 = callPurRep('index', ['from' => '2027-10-01', 'to' => '2027-10-31', 'dim' => 'supplier', 'export' => '1']);
    $r2 = callPurRep('apAging', ['as_of' => '2027-10-15', 'export' => '1']);

    expect($r1)->toBeInstanceOf(BinaryFileResponse::class);
    expect($r2)->toBeInstanceOf(BinaryFileResponse::class);
});
