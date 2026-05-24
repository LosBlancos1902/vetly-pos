<?php

use App\Http\Controllers\Master\PriceTierController;
use App\Models\Tenant\PriceTier;
use App\Models\Tenant\ProductUnit;
use App\Models\Tenant\ProductUnitPrice;
use App\Models\Tenant\User as TenantUser;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

function ownerForTierCrud(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function callTierController(string $method, ?Request $request = null, ?PriceTier $tier = null)
{
    $controller = app(PriceTierController::class);
    $request ??= Request::create('/master/price-tiers', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'store' => $controller->store($request),
        'update' => $controller->update($request, $tier),
        'destroy' => $controller->destroy($tier),
        'setDefault' => $controller->setDefault($tier),
    };
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Cache::driver('array')->forget('price_tier:default_id');
    // Pastikan Eceran kembali jadi default (kalau test sebelumnya swap default).
    PriceTier::query()->update(['is_default' => false]);
    PriceTier::where('name', 'Eceran')->update(['is_default' => true]);
    // Hapus tier extra (sisakan Eceran).
    PriceTier::where('name', '!=', 'Eceran')->delete();
});

afterEach(function () {
    PriceTier::query()->update(['is_default' => false]);
    PriceTier::updateOrCreate(['name' => 'Eceran'],
        ['sort_order' => 1, 'is_default' => true, 'is_active' => true]);
    PriceTier::where('name', '!=', 'Eceran')->delete();
    Cache::driver('array')->forget('price_tier:default_id');
});

it('create tier baru → non-default + active', function () {
    Auth::login(ownerForTierCrud());

    $req = Request::create('', 'POST', ['name' => 'Klinik', 'sort_order' => 3]);
    callTierController('store', $req);

    $tier = PriceTier::where('name', 'Klinik')->firstOrFail();
    expect($tier->is_default)->toBeFalse()
        ->and($tier->is_active)->toBeTrue();
});

it('delete tier non-default → success + cascade ke product_unit_prices', function () {
    Auth::login(ownerForTierCrud());
    $tier = PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);

    // Sambungkan ke harga supaya cascade ke-test.
    $unit = ProductUnit::first();
    ProductUnitPrice::create(['product_unit_id' => $unit->id, 'price_tier_id' => $tier->id, 'price' => 100]);
    expect(ProductUnitPrice::where('price_tier_id', $tier->id)->count())->toBe(1);

    callTierController('destroy', tier: $tier);

    expect(PriceTier::where('id', $tier->id)->exists())->toBeFalse()
        ->and(ProductUnitPrice::where('price_tier_id', $tier->id)->count())->toBe(0); // cascade
});

it('delete tier default → ditolak (HttpException 422)', function () {
    Auth::login(ownerForTierCrud());
    $defaultTier = PriceTier::where('is_default', true)->firstOrFail();

    expect(fn () => callTierController('destroy', tier: $defaultTier))->toThrow(HttpException::class);
});

it('setDefault: pindah default ke tier lain (atomic swap + cache invalidate)', function () {
    Auth::login(ownerForTierCrud());
    $oldDefault = PriceTier::where('is_default', true)->firstOrFail();
    $newDefault = PriceTier::create(['name' => 'NewDefault', 'sort_order' => 5, 'is_default' => false]);

    // Warm cache
    ProductUnit::defaultTierId();
    expect(Cache::driver('array')->get('price_tier:default_id'))->toBe($oldDefault->id);

    callTierController('setDefault', tier: $newDefault);

    expect($oldDefault->fresh()->is_default)->toBeFalse()
        ->and($newDefault->fresh()->is_default)->toBeTrue()
        // Cache di-invalidate, lookup ulang harus return id baru.
        ->and(ProductUnit::defaultTierId())->toBe($newDefault->id);
});

it('user tanpa master.manage ditolak create tier', function () {
    $cashier = TenantUser::firstOrCreate(['email' => 'cashier-tier@vetly.id'], [
        'name' => 'Cashier Tier', 'password' => bcrypt('test'), 'is_active' => true,
    ]);
    $cashier->syncRoles(['cashier']);

    Auth::login($cashier);
    $req = Request::create('', 'POST', ['name' => 'Test', 'sort_order' => 1]);
    expect(fn () => callTierController('store', $req))->toThrow(AuthorizationException::class);
});
