<?php

use App\Http\Controllers\Master\CustomerController;
use App\Http\Controllers\POS\CashierController;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\Sale;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\JournalEngine;
use App\Services\ServiceBundleService;
use App\Services\StockMovement as StockMovementService;
use App\Services\UnitConverter;
use App\Services\VetlySyncService;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

function ownerForCustomer(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function callCustomerController(string $method, ?Request $request = null, ?Customer $customer = null)
{
    $controller = app(CustomerController::class);
    $request ??= Request::create('/master/customers', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($request),
        'search' => $controller->search($request),
        'store' => $controller->store($request),
        'quickStore' => $controller->quickStore($request),
        'update' => $controller->update($request, $customer),
        'destroy' => $controller->destroy($customer),
    };
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Sale::query()->delete();
    Customer::where('code', 'like', 'CUS-%')->each(fn ($c) => $c->delete());
});

afterEach(function () {
    Sale::query()->delete();
    Customer::where('code', 'like', 'CUS-%')->each(fn ($c) => $c->delete());
});

// ─────────────────────────────────────────────────────────────────────────

it('create customer auto-generate code CUS-YYYYMMDD-NNNN', function () {
    Auth::login(ownerForCustomer());

    $req = Request::create('', 'POST', ['name' => 'Test User', 'phone' => '081111111111']);
    callCustomerController('store', $req);

    $c = Customer::where('phone', '081111111111')->firstOrFail();
    expect($c->name)->toBe('Test User')
        ->and($c->code)->toMatch('/^CUS-\d{8}-\d{4}$/')
        ->and($c->is_active)->toBeTrue();
});

it('VALIDASI: phone wajib + unique', function () {
    Auth::login(ownerForCustomer());

    // Phone kosong → reject
    $req = Request::create('', 'POST', ['name' => 'No Phone']);
    expect(fn () => callCustomerController('store', $req))->toThrow(ValidationException::class);

    // Duplikat phone → reject
    Customer::create([
        'code' => Customer::generateCode(), 'name' => 'Existing',
        'phone' => '082222222222', 'is_active' => true,
    ]);
    $req2 = Request::create('', 'POST', ['name' => 'Duplicate', 'phone' => '082222222222']);
    expect(fn () => callCustomerController('store', $req2))->toThrow(ValidationException::class);
});

it('update: ignore self saat cek phone unique', function () {
    Auth::login(ownerForCustomer());
    $c = Customer::create([
        'code' => Customer::generateCode(), 'name' => 'Existing',
        'phone' => '083333333333', 'is_active' => true,
    ]);

    $req = Request::create('', 'PUT', ['name' => 'Renamed', 'phone' => '083333333333']);
    callCustomerController('update', $req, $c);

    expect($c->fresh()->name)->toBe('Renamed');
});

it('search: live search by phone substring (phone-first ordering)', function () {
    Auth::login(ownerForCustomer());
    Customer::create(['code' => Customer::generateCode(), 'name' => 'Andi', 'phone' => '08110001111', 'is_active' => true]);
    Customer::create(['code' => Customer::generateCode(), 'name' => 'Budi', 'phone' => '08110002222', 'is_active' => true]);
    Customer::create(['code' => Customer::generateCode(), 'name' => 'Citra', 'phone' => '08220003333', 'is_active' => true]);

    $req = Request::create('', 'GET', ['q' => '0811']);
    $body = json_decode(callCustomerController('search', $req)->getContent(), true);

    expect($body['results'])->toHaveCount(2);
    foreach ($body['results'] as $r) {
        expect($r['phone'])->toContain('0811');
    }
});

it('search: query < 2 char → empty results (no DB hit)', function () {
    Auth::login(ownerForCustomer());

    $req = Request::create('', 'GET', ['q' => 'a']);
    $body = json_decode(callCustomerController('search', $req)->getContent(), true);
    expect($body['results'])->toBe([]);
});

it('search: customer nonaktif tidak muncul', function () {
    Auth::login(ownerForCustomer());
    Customer::create(['code' => Customer::generateCode(), 'name' => 'Inactive Andi',
        'phone' => '081444444444', 'is_active' => false]);

    $req = Request::create('', 'GET', ['q' => 'Andi']);
    $body = json_decode(callCustomerController('search', $req)->getContent(), true);
    expect($body['results'])->toBe([]);
});

it('quickStore: return JSON customer langsung (utk POS picker)', function () {
    Auth::login(ownerForCustomer());

    $req = Request::create('', 'POST', ['name' => 'Quick', 'phone' => '085555555555']);
    $response = callCustomerController('quickStore', $req);
    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKey('customer')
        ->and($body['customer']['name'])->toBe('Quick')
        ->and($body['customer']['phone'])->toBe('085555555555')
        ->and($body['customer']['code'])->toMatch('/^CUS-\d{8}-\d{4}$/');
});

it('destroy: customer tanpa transaksi → hard delete', function () {
    Auth::login(ownerForCustomer());
    $c = Customer::create(['code' => Customer::generateCode(), 'name' => 'Empty',
        'phone' => '081666666666', 'is_active' => true]);

    callCustomerController('destroy', customer: $c);

    expect(Customer::where('phone', '081666666666')->exists())->toBeFalse();
});

it('destroy: customer dgn sale → soft-deactivate (jaga histori)', function () {
    Auth::login(ownerForCustomer());
    $c = Customer::create(['code' => Customer::generateCode(), 'name' => 'WithSale',
        'phone' => '081777777777', 'is_active' => true]);
    Sale::create([
        'invoice_no' => 'INV-CUST-DEL-'.uniqid(),
        'date' => now(),
        'warehouse_id' => Warehouse::firstOrFail()->id,
        'cashier_id' => Auth::id(),
        'customer_id' => $c->id,
        'subtotal' => 10000, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => 10000,
        'payment_status' => 'paid', 'status' => 'completed',
    ]);

    callCustomerController('destroy', customer: $c);

    $fresh = $c->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_active)->toBeFalse();
});

it('POS integration: sale dgn customer_id terhubung benar', function () {
    Auth::login(ownerForCustomer());
    $c = Customer::create(['code' => Customer::generateCode(), 'name' => 'POS Test',
        'phone' => '081888888888', 'is_active' => true]);

    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p->id, 'warehouse_id' => $warehouse->id],
        ['qty' => 100, 'cost_avg' => 5000, 'updated_at' => now(), 'created_at' => now()],
    );
    Product::where('id', $p->id)->update(['cost_avg' => 5000]);

    $controller = app(CashierController::class);
    $stock = new StockMovementService(new HppCalculator, new UnitConverter);
    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'customer_id' => $c->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id, 'qty' => 1, 'price' => 10000]],
        'payment_method' => 'cash',
        'amount_paid' => 10000,
    ]);
    $req->setUserResolver(fn () => Auth::user());
    $controller->store($req, $stock, new JournalEngine,
        new ServiceBundleService($stock, new UnitConverter), new VetlySyncService);

    $sale = Sale::latest('id')->first();
    expect($sale->customer_id)->toBe($c->id);
});

it('REGRESSION: walk-in sale (customer_id=null) tetap valid', function () {
    Auth::login(ownerForCustomer());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p->id, 'warehouse_id' => $warehouse->id],
        ['qty' => 100, 'cost_avg' => 5000, 'updated_at' => now(), 'created_at' => now()],
    );
    Product::where('id', $p->id)->update(['cost_avg' => 5000]);

    $controller = app(CashierController::class);
    $stock = new StockMovementService(new HppCalculator, new UnitConverter);
    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        // customer_id sengaja tidak dikirim — walk-in
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id, 'qty' => 1, 'price' => 10000]],
        'payment_method' => 'cash',
        'amount_paid' => 10000,
    ]);
    $req->setUserResolver(fn () => Auth::user());
    $controller->store($req, $stock, new JournalEngine,
        new ServiceBundleService($stock, new UnitConverter), new VetlySyncService);

    $sale = Sale::latest('id')->first();
    expect($sale->customer_id)->toBeNull();
});

it('F3: show() expose customer detail + sales history paginated', function () {
    Auth::login(ownerForCustomer());
    $c = Customer::create(['code' => Customer::generateCode(), 'name' => 'History Test',
        'phone' => '081000000001', 'is_active' => true]);

    $warehouse = Warehouse::firstOrFail();
    for ($i = 1; $i <= 3; $i++) {
        Sale::create([
            'invoice_no' => 'INV-HIST-'.uniqid(),
            'date' => now()->subDays($i),
            'warehouse_id' => $warehouse->id,
            'cashier_id' => Auth::id(),
            'customer_id' => $c->id,
            'subtotal' => $i * 10000, 'discount_amount' => 0, 'tax_amount' => 0,
            'total' => $i * 10000,
            'payment_status' => 'paid', 'status' => 'completed',
        ]);
    }

    $controller = app(CustomerController::class);
    $req = Request::create('', 'GET');
    $req->setUserResolver(fn () => Auth::user());
    /** @var \Inertia\Response $response */
    $response = $controller->show($req, $c);
    $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props)->toHaveKeys(['customer', 'sales', 'stats'])
        ->and($props['sales']['total'])->toBe(3)
        ->and($props['stats']['total_sales'])->toBe(3)
        ->and((float) $props['stats']['total_spent'])->toBe(60000.0); // 10+20+30
});

it('F3: show() reconcile total_spent kalau cache drift', function () {
    Auth::login(ownerForCustomer());
    $c = Customer::create(['code' => Customer::generateCode(), 'name' => 'Drift',
        'phone' => '081000000002', 'is_active' => true, 'total_spent' => 99999]); // drift sengaja

    Sale::create([
        'invoice_no' => 'INV-DRIFT-'.uniqid(),
        'date' => now(),
        'warehouse_id' => Warehouse::firstOrFail()->id,
        'cashier_id' => Auth::id(),
        'customer_id' => $c->id,
        'subtotal' => 50000, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => 50000,
        'payment_status' => 'paid', 'status' => 'completed',
    ]);

    $controller = app(CustomerController::class);
    $req = Request::create('', 'GET');
    $req->setUserResolver(fn () => Auth::user());
    $controller->show($req, $c);

    // Cache di-reconcile ke real sum
    expect((float) $c->fresh()->total_spent)->toBe(50000.0);
});

it('F3: total_spent auto-increment setelah sale committed lewat POS', function () {
    Auth::login(ownerForCustomer());
    $c = Customer::create(['code' => Customer::generateCode(), 'name' => 'Increment',
        'phone' => '081000000003', 'is_active' => true, 'total_spent' => 0]);

    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p->id, 'warehouse_id' => $warehouse->id],
        ['qty' => 100, 'cost_avg' => 5000, 'updated_at' => now(), 'created_at' => now()],
    );
    Product::where('id', $p->id)->update(['cost_avg' => 5000]);

    $controller = app(CashierController::class);
    $stock = new StockMovementService(new HppCalculator, new UnitConverter);

    // 2 sale berturut-turut
    foreach ([10000, 15000] as $amount) {
        $req = Request::create('/pos/sales', 'POST', [
            'warehouse_id' => $warehouse->id,
            'customer_id' => $c->id,
            'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
                'qty' => 1, 'price' => $amount]],
            'payment_method' => 'cash',
            'amount_paid' => $amount,
        ]);
        $req->setUserResolver(fn () => Auth::user());
        $controller->store($req, $stock, new JournalEngine,
            new ServiceBundleService($stock, new UnitConverter), new VetlySyncService);
    }

    expect((float) $c->fresh()->total_spent)->toBe(25000.0); // 10rb + 15rb
});

it('OTORISASI: cashier role PUNYA customer.manage (untuk quick-create dari POS)', function () {
    $cashier = TenantUser::firstOrCreate(['email' => 'cashier-cust@vetly.id'], [
        'name' => 'Cashier C', 'password' => bcrypt('x'), 'is_active' => true,
    ]);
    $cashier->syncRoles(['cashier']);
    Auth::login($cashier);

    $req = Request::create('', 'POST', ['name' => 'From Cashier', 'phone' => '081999999999']);
    callCustomerController('quickStore', $req); // tidak throw

    expect(Customer::where('phone', '081999999999')->exists())->toBeTrue();
});
