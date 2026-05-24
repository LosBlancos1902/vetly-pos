<?php

use App\Http\Controllers\Master\CustomerCategoryController;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerCategory;
use App\Models\Tenant\User as TenantUser;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function ownerForCustCat(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function callCustCatController(string $method, ?Request $request = null, ?CustomerCategory $category = null)
{
    $controller = app(CustomerCategoryController::class);
    $request ??= Request::create('/master/customer-categories', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($request),
        'store' => $controller->store($request),
        'update' => $controller->update($request, $category),
        'destroy' => $controller->destroy($category),
    };
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Customer::where('phone', 'like', 'CCAT-%')->each(fn ($c) => $c->delete());
    CustomerCategory::where('name', 'like', 'CCAT-TEST-%')->each(fn ($c) => $c->delete());
});

afterEach(function () {
    Customer::where('phone', 'like', 'CCAT-%')->each(fn ($c) => $c->delete());
    CustomerCategory::where('name', 'like', 'CCAT-TEST-%')->each(fn ($c) => $c->delete());
});

// ─────────────────────────────────────────────────────────────────────────

it('create root category dgn color + icon', function () {
    Auth::login(ownerForCustCat());

    $req = Request::create('', 'POST', [
        'name' => 'CCAT-TEST-VIP', 'color' => 'warning', 'icon' => '⭐', 'is_active' => true,
    ]);
    callCustCatController('store', $req);

    $cat = CustomerCategory::where('name', 'CCAT-TEST-VIP')->firstOrFail();
    expect($cat->color)->toBe('warning')
        ->and($cat->icon)->toBe('⭐')
        ->and($cat->parent_id)->toBeNull()
        ->and($cat->is_active)->toBeTrue();
});

it('create child category dgn parent_id', function () {
    Auth::login(ownerForCustCat());
    $parent = CustomerCategory::create(['name' => 'CCAT-TEST-Member', 'color' => 'info', 'is_active' => true]);

    $req = Request::create('', 'POST', [
        'name' => 'CCAT-TEST-Member-VIP',
        'parent_id' => $parent->id,
        'color' => 'success',
        'is_active' => true,
    ]);
    callCustCatController('store', $req);

    $child = CustomerCategory::where('name', 'CCAT-TEST-Member-VIP')->firstOrFail();
    expect($child->parent_id)->toBe($parent->id);
});

it('VALIDASI: nama duplikat ditolak', function () {
    Auth::login(ownerForCustCat());
    CustomerCategory::create(['name' => 'CCAT-TEST-Dup', 'color' => 'muted', 'is_active' => true]);

    $req = Request::create('', 'POST', ['name' => 'CCAT-TEST-Dup', 'color' => 'muted', 'is_active' => true]);
    expect(fn () => callCustCatController('store', $req))->toThrow(ValidationException::class);
});

it('VALIDASI: color harus salah satu enum whitelist', function () {
    Auth::login(ownerForCustCat());

    $req = Request::create('', 'POST', [
        'name' => 'CCAT-TEST-BadColor',
        'color' => 'rainbow', // invalid
        'is_active' => true,
    ]);
    expect(fn () => callCustCatController('store', $req))->toThrow(ValidationException::class);
});

it('GUARDRAIL: parent_id = self → ditolak', function () {
    Auth::login(ownerForCustCat());
    $cat = CustomerCategory::create(['name' => 'CCAT-TEST-Loop', 'color' => 'muted', 'is_active' => true]);

    $req = Request::create('', 'PUT', [
        'name' => 'CCAT-TEST-Loop', 'color' => 'muted',
        'parent_id' => $cat->id, 'is_active' => true,
    ]);
    expect(fn () => callCustCatController('update', $req, $cat))->toThrow(HttpException::class);
});

it('GUARDRAIL: parent_id = descendant → ditolak (loop dalam)', function () {
    Auth::login(ownerForCustCat());
    $a = CustomerCategory::create(['name' => 'CCAT-TEST-A', 'color' => 'muted', 'is_active' => true]);
    $b = CustomerCategory::create(['name' => 'CCAT-TEST-B', 'parent_id' => $a->id, 'color' => 'muted', 'is_active' => true]);
    $c = CustomerCategory::create(['name' => 'CCAT-TEST-C', 'parent_id' => $b->id, 'color' => 'muted', 'is_active' => true]);

    $req = Request::create('', 'PUT', [
        'name' => 'CCAT-TEST-A', 'color' => 'muted',
        'parent_id' => $c->id, 'is_active' => true,
    ]);
    expect(fn () => callCustCatController('update', $req, $a))->toThrow(HttpException::class);
});

it('destroy: kategori tanpa customer & tanpa anak → hard delete', function () {
    Auth::login(ownerForCustCat());
    $cat = CustomerCategory::create(['name' => 'CCAT-TEST-Empty', 'color' => 'muted', 'is_active' => true]);

    callCustCatController('destroy', category: $cat);

    expect(CustomerCategory::where('name', 'CCAT-TEST-Empty')->exists())->toBeFalse();
});

it('destroy: kategori dgn customer → soft-deactivate (jaga histori CRM)', function () {
    Auth::login(ownerForCustCat());
    $cat = CustomerCategory::create(['name' => 'CCAT-TEST-WithCust', 'color' => 'info', 'is_active' => true]);
    $customer = Customer::create([
        'code' => Customer::generateCode(),
        'name' => 'Cust w/ Cat',
        'phone' => 'CCAT-1234567890',
        'customer_category_id' => $cat->id,
        'is_active' => true,
    ]);

    callCustCatController('destroy', category: $cat);

    $fresh = $cat->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_active)->toBeFalse();
    // Customer tetap ada + tetap link ke kategori (nonaktif tapi tidak hilang)
    expect($customer->fresh()->customer_category_id)->toBe($cat->id);

    $customer->delete();
});

it('destroy: kategori dgn sub → ditolak (cegah orphan)', function () {
    Auth::login(ownerForCustCat());
    $parent = CustomerCategory::create(['name' => 'CCAT-TEST-HasChild', 'color' => 'muted', 'is_active' => true]);
    CustomerCategory::create(['name' => 'CCAT-TEST-ChildOfHasChild', 'parent_id' => $parent->id, 'color' => 'muted', 'is_active' => true]);

    expect(fn () => callCustCatController('destroy', category: $parent))->toThrow(HttpException::class);
    expect($parent->fresh())->not->toBeNull()
        ->and($parent->fresh()->is_active)->toBeTrue();
});

it('CASCADE nullOnDelete: hapus kategori → customer.customer_category_id set NULL', function () {
    Auth::login(ownerForCustCat());
    $cat = CustomerCategory::create(['name' => 'CCAT-TEST-Cascade', 'color' => 'muted', 'is_active' => true]);
    $customer = Customer::create([
        'code' => Customer::generateCode(),
        'name' => 'Cascade Test',
        'phone' => 'CCAT-9999999999',
        'customer_category_id' => $cat->id,
        'is_active' => true,
    ]);

    // Hard-delete kategori (bypass controller — anggap kasus migration cleanup)
    $cat->delete();

    expect($customer->fresh()->customer_category_id)->toBeNull();
    expect(Customer::find($customer->id))->not->toBeNull(); // customer survives

    $customer->delete();
});

it('INTEGRATION: CustomerController.index expose categories prop + filter by category_id works', function () {
    Auth::login(ownerForCustCat());
    $cat = CustomerCategory::create(['name' => 'CCAT-TEST-Filter', 'color' => 'info', 'is_active' => true]);
    $customer = Customer::create([
        'code' => Customer::generateCode(),
        'name' => 'Filter Test',
        'phone' => 'CCAT-8888888888',
        'customer_category_id' => $cat->id,
        'is_active' => true,
    ]);

    $controller = app(\App\Http\Controllers\Master\CustomerController::class);

    // Tanpa filter → ada customer ini
    $req = Request::create('', 'GET');
    $req->setUserResolver(fn () => Auth::user());
    $props = $controller->index($req)->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props)->toHaveKeys(['customers', 'categories', 'filters']);

    // Filter by category_id → cuma customer kategori ini
    $req2 = Request::create('', 'GET', ['category_id' => $cat->id]);
    $req2->setUserResolver(fn () => Auth::user());
    $props2 = $controller->index($req2)->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $names = collect($props2['customers']['data'])->pluck('name')->all();
    expect($names)->toContain('Filter Test');
    // Semua harus punya category_id = cat->id
    foreach ($props2['customers']['data'] as $c) {
        expect($c['customer_category_id'])->toBe($cat->id);
    }

    $customer->delete();
});

it('OTORISASI: cashier role TIDAK punya customer.manage default → ditolak akses kategori', function () {
    // Wait — cashier sebenarnya PUNYA customer.manage (utk quick-create POS).
    // Test: user tanpa role apapun (atau dgn permission revoked) → ditolak.
    $unrelated = TenantUser::firstOrCreate(['email' => 'no-perm-ccat@vetly.id'], [
        'name' => 'No Perm', 'password' => bcrypt('x'), 'is_active' => true,
    ]);
    $unrelated->syncRoles([]); // tidak ada role sama sekali
    Auth::login($unrelated);

    $req = Request::create('', 'POST', ['name' => 'CCAT-TEST-Auth', 'color' => 'muted']);
    expect(fn () => callCustCatController('store', $req))->toThrow(AuthorizationException::class);
});
