<?php

use App\Http\Controllers\Master\CategoryController;
use App\Models\Tenant\Category;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\Product;
use App\Models\Tenant\User as TenantUser;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function ownerForCat(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function callCatController(string $method, ?Request $request = null, ?Category $category = null)
{
    $controller = app(CategoryController::class);
    $request ??= Request::create('/master/categories', 'GET');
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
    Category::where('name', 'like', 'CAT-TEST-%')->each(fn ($c) => $c->delete());
});

afterEach(function () {
    Category::where('name', 'like', 'CAT-TEST-%')->each(fn ($c) => $c->delete());
});

// ─────────────────────────────────────────────────────────────────────────

it('create root category', function () {
    Auth::login(ownerForCat());

    $req = Request::create('', 'POST', ['name' => 'CAT-TEST-Root', 'is_active' => true]);
    callCatController('store', $req);

    $cat = Category::where('name', 'CAT-TEST-Root')->firstOrFail();
    expect($cat->parent_id)->toBeNull()
        ->and($cat->is_active)->toBeTrue();
});

it('create child category dgn parent_id', function () {
    Auth::login(ownerForCat());
    $parent = Category::create(['name' => 'CAT-TEST-Parent', 'is_active' => true]);

    $req = Request::create('', 'POST', [
        'name' => 'CAT-TEST-Child',
        'parent_id' => $parent->id,
        'is_active' => true,
    ]);
    callCatController('store', $req);

    $child = Category::where('name', 'CAT-TEST-Child')->firstOrFail();
    expect($child->parent_id)->toBe($parent->id);
});

it('update: rename + reparent', function () {
    Auth::login(ownerForCat());
    $a = Category::create(['name' => 'CAT-TEST-A', 'is_active' => true]);
    $b = Category::create(['name' => 'CAT-TEST-B', 'is_active' => true]);

    $req = Request::create('', 'PUT', [
        'name' => 'CAT-TEST-A-Renamed',
        'parent_id' => $b->id,
        'is_active' => true,
    ]);
    callCatController('update', $req, $a);

    $fresh = $a->fresh();
    expect($fresh->name)->toBe('CAT-TEST-A-Renamed')
        ->and($fresh->parent_id)->toBe($b->id);
});

it('VALIDASI: nama duplikat ditolak', function () {
    Auth::login(ownerForCat());
    Category::create(['name' => 'CAT-TEST-Dup', 'is_active' => true]);

    $req = Request::create('', 'POST', ['name' => 'CAT-TEST-Dup', 'is_active' => true]);
    expect(fn () => callCatController('store', $req))->toThrow(ValidationException::class);
});

it('VALIDASI: nama duplikat di update OK kalau ignore self', function () {
    Auth::login(ownerForCat());
    $cat = Category::create(['name' => 'CAT-TEST-Self', 'is_active' => true]);

    $req = Request::create('', 'PUT', ['name' => 'CAT-TEST-Self', 'is_active' => true]);
    // Tidak throw — nama sama dgn diri sendiri valid
    callCatController('update', $req, $cat);

    expect($cat->fresh()->name)->toBe('CAT-TEST-Self');
});

it('GUARDRAIL: parent_id = self → ditolak (cegah loop trivial)', function () {
    Auth::login(ownerForCat());
    $cat = Category::create(['name' => 'CAT-TEST-Loop', 'is_active' => true]);

    $req = Request::create('', 'PUT', ['name' => 'CAT-TEST-Loop', 'parent_id' => $cat->id, 'is_active' => true]);
    expect(fn () => callCatController('update', $req, $cat))->toThrow(HttpException::class);
});

it('GUARDRAIL: parent_id = descendant → ditolak (cegah loop dalam)', function () {
    Auth::login(ownerForCat());
    $a = Category::create(['name' => 'CAT-TEST-LoopA', 'is_active' => true]);
    $b = Category::create(['name' => 'CAT-TEST-LoopB', 'parent_id' => $a->id, 'is_active' => true]);
    $c = Category::create(['name' => 'CAT-TEST-LoopC', 'parent_id' => $b->id, 'is_active' => true]);

    // A coba di-set parent ke C → loop A→C→B→A
    $req = Request::create('', 'PUT', ['name' => 'CAT-TEST-LoopA', 'parent_id' => $c->id, 'is_active' => true]);
    expect(fn () => callCatController('update', $req, $a))->toThrow(HttpException::class);
});

it('destroy: kategori tanpa produk & tanpa anak → hard delete', function () {
    Auth::login(ownerForCat());
    $cat = Category::create(['name' => 'CAT-TEST-Empty', 'is_active' => true]);

    callCatController('destroy', category: $cat);

    expect(Category::where('name', 'CAT-TEST-Empty')->exists())->toBeFalse();
});

it('destroy: kategori dgn produk → soft-deactivate (jaga histori)', function () {
    Auth::login(ownerForCat());
    $cat = Category::create(['name' => 'CAT-TEST-WithProd', 'is_active' => true]);
    $unitId = MasterUnit::first()->id;
    $product = Product::create([
        'sku' => 'CAT-TEST-PRD',
        'name' => 'Test',
        'category_id' => $cat->id,
        'base_unit_id' => $unitId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'price' => 1000,
    ]);

    callCatController('destroy', category: $cat);

    $fresh = $cat->fresh();
    expect($fresh)->not->toBeNull()           // tidak ke-hard-delete
        ->and($fresh->is_active)->toBeFalse(); // tapi nonaktif

    $product->delete(); // cleanup
});

it('destroy: kategori dgn sub-kategori → ditolak (cegah orphan)', function () {
    Auth::login(ownerForCat());
    $parent = Category::create(['name' => 'CAT-TEST-HasChild', 'is_active' => true]);
    Category::create(['name' => 'CAT-TEST-Child', 'parent_id' => $parent->id, 'is_active' => true]);

    expect(fn () => callCatController('destroy', category: $parent))->toThrow(HttpException::class);

    // Parent masih ada (tidak ke-hard-delete maupun soft)
    expect($parent->fresh())->not->toBeNull()
        ->and($parent->fresh()->is_active)->toBeTrue();
});

it('OTORISASI: cashier tanpa master.manage ditolak', function () {
    $cashier = TenantUser::firstOrCreate(['email' => 'cashier-cat@vetly.id'], [
        'name' => 'Cashier Cat', 'password' => bcrypt('x'), 'is_active' => true,
    ]);
    $cashier->syncRoles(['cashier']);
    Auth::login($cashier);

    $req = Request::create('', 'POST', ['name' => 'CAT-TEST-Auth']);
    expect(fn () => callCatController('store', $req))->toThrow(AuthorizationException::class);
});

it('index: expose categories + parentOptions + product_count', function () {
    Auth::login(ownerForCat());
    $cat = Category::create(['name' => 'CAT-TEST-IndexCheck', 'is_active' => true]);
    $unitId = MasterUnit::first()->id;
    $product = Product::create([
        'sku' => 'CAT-TEST-IDX-PRD',
        'name' => 'IdxProd',
        'category_id' => $cat->id,
        'base_unit_id' => $unitId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'price' => 1000,
    ]);

    /** @var \Inertia\Response $response */
    $response = callCatController('index');
    $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props)->toHaveKeys(['categories', 'parentOptions', 'filters']);

    $row = collect($props['categories']['data'])->firstWhere('name', 'CAT-TEST-IndexCheck');
    expect($row)->not->toBeNull()
        ->and($row['product_count'])->toBe(1);

    $product->delete();
});
