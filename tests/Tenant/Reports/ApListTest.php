<?php

use App\Http\Controllers\Reports\PurchasingReportController;
use App\Models\Tenant\AccountsPayable;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptItem;
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
 * Daftar AP endpoint baru — list SEMUA AP (termasuk paid/void), filter
 * periode received_at + status. Berbeda dgn AP Aging (hanya outstanding).
 */

function ownerForApList(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForApList(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->first()
        ?? TenantUser::create([
            'name' => 'Test Cashier ApList', 'email' => 'cashier-aplist@test.local',
            'password' => bcrypt('test'), 'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ])->assignRole('cashier');
}

function callApList(array $params = [])
{
    $controller = app(PurchasingReportController::class);
    $req = Request::create('/reports/purchasing/ap-list', 'GET', $params);
    $req->setUserResolver(fn () => Auth::user());

    return $controller->apList($req);
}

function seedAplistFixture(Supplier $sup, Warehouse $wh, Product $p, float $amount, float $paid, string $date, string $status): AccountsPayable
{
    $sub = $amount;
    $po = PurchaseOrder::create([
        'po_no' => 'APLIST-'.uniqid('po', true),
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
        'qty_ordered' => 1,
        'qty_received' => 1,
        'unit_price' => $sub,
        'subtotal' => $sub,
    ]);
    $gr = GoodsReceipt::create([
        'gr_no' => 'APLIST-'.uniqid('gr', true),
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
        'qty_received' => 1,
        'unit_price' => $sub,
        'subtotal' => $sub,
    ]);

    return AccountsPayable::create([
        'ap_no' => 'APLIST-'.uniqid('ap', true),
        'supplier_id' => $sup->id,
        'gr_id' => $gr->id,
        'po_id' => $po->id,
        'amount' => $amount,
        'paid_amount' => $paid,
        'due_date' => $date,
        'status' => $status,
    ]);
}

function cleanupApList(): void
{
    AccountsPayable::where('ap_no', 'like', 'APLIST-%')->delete();
    GoodsReceiptItem::whereHas('goodsReceipt', fn ($q) => $q->where('gr_no', 'like', 'APLIST-%'))->delete();
    GoodsReceipt::where('gr_no', 'like', 'APLIST-%')->delete();
    PurchaseOrderItem::whereHas('purchaseOrder', fn ($q) => $q->where('po_no', 'like', 'APLIST-%'))->delete();
    PurchaseOrder::where('po_no', 'like', 'APLIST-%')->delete();
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForApList());
    cleanupApList();
});

afterEach(function () {
    cleanupApList();
});

it('APLIST: list SEMUA AP dalam periode (termasuk paid)', function () {
    $sup = Supplier::firstOrCreate(
        ['code' => 'SUP-APLIST'],
        ['name' => 'ApList Supplier', 'is_active' => true, 'payment_term_days' => 0],
    );
    $wh = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    seedAplistFixture($sup, $wh, $p1, 100000, 0, '2029-10-05', 'open');
    seedAplistFixture($sup, $wh, $p1, 200000, 200000, '2029-10-15', 'paid');
    seedAplistFixture($sup, $wh, $p1, 50000, 25000, '2029-10-20', 'partially_paid');

    $props = callApList([
        'from' => '2029-10-01',
        'to' => '2029-10-31',
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $mine = collect($props['rows'])->filter(fn ($r) => str_starts_with($r->ap_no, 'APLIST-'));
    expect($mine->count())->toBe(3); // semua status

    $statuses = $mine->pluck('status')->sort()->values()->all();
    expect($statuses)->toBe(['open', 'paid', 'partially_paid']);
});

it('APLIST: filter status=paid hanya menampilkan paid', function () {
    $sup = Supplier::firstOrCreate(
        ['code' => 'SUP-APLIST2'],
        ['name' => 'ApList2 Supplier', 'is_active' => true, 'payment_term_days' => 0],
    );
    $wh = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    seedAplistFixture($sup, $wh, $p1, 100000, 0, '2029-11-01', 'open');
    seedAplistFixture($sup, $wh, $p1, 200000, 200000, '2029-11-05', 'paid');

    $props = callApList([
        'from' => '2029-11-01',
        'to' => '2029-11-30',
        'status' => 'paid',
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $mine = collect($props['rows'])->filter(fn ($r) => str_starts_with($r->ap_no, 'APLIST-'));
    expect($mine->count())->toBe(1);
    expect($mine->first()->status)->toBe('paid');
});

it('APLIST: remaining = amount − paid_amount', function () {
    $sup = Supplier::firstOrCreate(
        ['code' => 'SUP-APLIST3'],
        ['name' => 'ApList3 Supplier', 'is_active' => true, 'payment_term_days' => 0],
    );
    $wh = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();

    seedAplistFixture($sup, $wh, $p1, 150000, 60000, '2029-12-10', 'partially_paid');

    $props = callApList(['from' => '2029-12-01', 'to' => '2029-12-31'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $mine = collect($props['rows'])->filter(fn ($r) => str_starts_with($r->ap_no, 'APLIST-'))->first();
    expect($mine->amount)->toBe(150000.0);
    expect($mine->paid_amount)->toBe(60000.0);
    expect($mine->remaining)->toBe(90000.0);
});

it('APLIST PERM: cashier → 403', function () {
    Auth::login(cashierForApList());
    expect(fn () => callApList())->toThrow(AuthorizationException::class);
});

it('APLIST EXPORT: export=1 → BinaryFileResponse xlsx', function () {
    $resp = callApList([
        'from' => '2030-01-01',
        'to' => '2030-01-31',
        'export' => '1',
    ]);
    expect($resp)->toBeInstanceOf(BinaryFileResponse::class);
    expect($resp->headers->get('content-type'))->toContain('spreadsheetml.sheet');
});
