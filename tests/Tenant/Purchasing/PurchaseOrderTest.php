<?php

use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function callPoController(string $method, ?Request $request = null, ?PurchaseOrder $po = null)
{
    $controller = new PurchaseOrderController;
    $request ??= Request::create('/purchasing/orders', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($request),
        'store' => $controller->store($request),
        'update' => $controller->update($request, $po),
        'submit' => $controller->submit($request, $po),
        'approve' => $controller->approve($request, $po),
        'reject' => $controller->reject($request, $po),
        'cancel' => $controller->cancel($request, $po),
    };
}

/**
 * Helper: bikin user dengan permission tertentu yang owner harus assign manual
 * (po_create / po_approve tidak default ke role manapun kecuali owner).
 */
function userWithPoPerms(array $perms, ?int $warehouseId = null): TenantUser
{
    // Pakai role manager sebagai role "biasa" yang kita beri PO perms ad-hoc.
    // Simulasi owner ngasih permission ke role tertentu via /settings/roles.
    $role = Role::findByName('manager');
    foreach ($perms as $p) {
        $role->givePermissionTo($p);
    }
    $email = 'po-test-'.implode('-', $perms).'@vetly.id';
    $user = TenantUser::firstOrCreate(['email' => $email], [
        'name' => 'PO Tester',
        'password' => bcrypt('test'),
        'is_active' => true,
        'warehouse_id' => $warehouseId,
    ]);
    $user->update(['warehouse_id' => $warehouseId]);
    $user->syncRoles(['manager']);

    return $user->fresh();
}

function ownerForPo(): TenantUser
{
    return TenantUser::query()
        ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
        ->firstOrFail();
}

function basePoForm(int $supplierId, int $warehouseId, int $productId, int $unitId): array
{
    return [
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'payment_type' => 'cash',
        'payment_term_days' => 0,
        'items' => [
            ['product_id' => $productId, 'unit_id' => $unitId, 'qty_ordered' => 10, 'unit_price' => 1500],
        ],
    ];
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();

    // Bersihkan dependent rows urut FK: ApPayment → AP → GR → PO.
    \App\Models\Tenant\ApPayment::query()->delete();
    \App\Models\Tenant\AccountsPayable::query()->delete();
    \App\Models\Tenant\GoodsReceipt::query()->delete();
    PurchaseOrder::query()->delete();
    Supplier::query()->where('code', 'like', 'PO-TEST-%')->delete();

    Supplier::firstOrCreate(
        ['code' => 'PO-TEST-001'],
        ['name' => 'PO Test Supplier', 'payment_term_days' => 14, 'is_active' => true],
    );

    // Bersihkan permission ad-hoc dari manager (per-test setup-nya assign manual).
    $manager = Role::findByName('manager');
    foreach (['purchasing.po_create', 'purchasing.po_approve'] as $p) {
        $manager->revokePermissionTo($p);
    }
});

it('creates PO with cash payment (term forced to 0)', function () {
    $owner = ownerForPo();
    Auth::login($owner);

    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $form = basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id);
    $form['payment_type'] = 'cash';
    $form['payment_term_days'] = 30; // user kirim 30, harus dipaksa 0 karena cash

    $request = Request::create('/purchasing/orders', 'POST', $form);
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();
    expect($po->payment_type)->toBe('cash')
        ->and((int) $po->payment_term_days)->toBe(0)
        ->and($po->status)->toBe('draft')
        ->and((float) $po->total)->toBe(15000.0)
        ->and($po->items)->toHaveCount(1);
});

it('creates PO with tempo payment + term_days', function () {
    $owner = ownerForPo();
    Auth::login($owner);

    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $form = basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id);
    $form['payment_type'] = 'tempo';
    $form['payment_term_days'] = 30;

    $request = Request::create('/purchasing/orders', 'POST', $form);
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();
    expect($po->payment_type)->toBe('tempo')
        ->and((int) $po->payment_term_days)->toBe(30);
});

it('rejects tempo PO with term_days = 0', function () {
    $owner = ownerForPo();
    Auth::login($owner);

    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $form = basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id);
    $form['payment_type'] = 'tempo';
    $form['payment_term_days'] = 0;

    $request = Request::create('/purchasing/orders', 'POST', $form);

    expect(fn () => callPoController('store', $request))
        ->toThrow(HttpException::class);
});

it('generates PO number with PO-YYYYMM- prefix', function () {
    $owner = ownerForPo();
    Auth::login($owner);
    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();
    expect($po->po_no)->toMatch('/^PO-\d{6}-\d{4}$/');
});

it('submit transitions draft → submitted by creator only', function () {
    $owner = ownerForPo();
    Auth::login($owner);
    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();
    callPoController('submit', po: $po);

    expect($po->refresh()->status)->toBe('submitted');
});

it('approve transitions submitted → approved + records approver', function () {
    $owner = ownerForPo();
    Auth::login($owner);
    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();
    $po->update(['status' => 'submitted']);

    callPoController('approve', po: $po);

    $po->refresh();
    expect($po->status)->toBe('approved')
        ->and($po->approved_by)->toBe($owner->id)
        ->and($po->approved_at)->not->toBeNull();
});

it('reject transitions submitted → rejected with reason', function () {
    $owner = ownerForPo();
    Auth::login($owner);
    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();
    $po->update(['status' => 'submitted']);

    $rejReq = Request::create("/purchasing/orders/{$po->id}/reject", 'POST', [
        'rejected_reason' => 'Harga di atas budget',
    ]);
    callPoController('reject', $rejReq, $po);

    $po->refresh();
    expect($po->status)->toBe('rejected')
        ->and($po->rejected_reason)->toBe('Harga di atas budget');
});

it('cancel transitions draft → cancelled with reason', function () {
    $owner = ownerForPo();
    Auth::login($owner);
    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();

    $cancelReq = Request::create("/purchasing/orders/{$po->id}/cancel", 'POST', [
        'cancelled_reason' => 'Salah supplier',
    ]);
    callPoController('cancel', $cancelReq, $po);

    $po->refresh();
    expect($po->status)->toBe('cancelled')
        ->and($po->cancelled_reason)->toBe('Salah supplier')
        ->and($po->cancelled_at)->not->toBeNull();
});

it('cannot approve PO still in draft (status guard)', function () {
    $owner = ownerForPo();
    Auth::login($owner);
    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();

    expect(fn () => callPoController('approve', po: $po))
        ->toThrow(HttpException::class);
});

it('cannot submit twice (status guard)', function () {
    $owner = ownerForPo();
    Auth::login($owner);
    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();
    callPoController('submit', po: $po);

    expect(fn () => callPoController('submit', po: $po->fresh()))
        ->toThrow(HttpException::class);
});

it('po permissions default to owner only — manager cannot create', function () {
    // Manager dengan permission default tidak punya purchasing.po_create.
    $manager = TenantUser::query()
        ->whereHas('roles', fn ($q) => $q->where('name', 'manager'))
        ->first();

    if (! $manager) {
        $manager = TenantUser::create([
            'name' => 'Test Manager',
            'email' => 'test-mgr-po@vetly.id',
            'password' => bcrypt('test'),
            'is_active' => true,
        ]);
        $manager->assignRole('manager');
    }
    Auth::login($manager);

    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));

    expect(fn () => callPoController('store', $request))
        ->toThrow(AuthorizationException::class);
});

it('owner can grant po_create to manager at runtime (simulates /settings/roles)', function () {
    // Simulasi owner ngasih po_create ke role manager via UI.
    $manager = userWithPoPerms(['purchasing.po_create']);
    Auth::login($manager);

    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));

    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();
    expect($po)->not->toBeNull()
        ->and($po->created_by)->toBe($manager->id);
});

it('user with po_create cannot approve without po_approve', function () {
    $manager = userWithPoPerms(['purchasing.po_create']);
    Auth::login($manager);

    $supplier = Supplier::where('code', 'PO-TEST-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    $unit = MasterUnit::query()->firstOrFail();

    $request = Request::create('/purchasing/orders', 'POST',
        basePoForm($supplier->id, $warehouse->id, $product->id, $unit->id));
    callPoController('store', $request);

    $po = PurchaseOrder::latest('id')->first();
    $po->update(['status' => 'submitted']);

    expect(fn () => callPoController('approve', po: $po))
        ->toThrow(AuthorizationException::class);
});
