<?php

use App\Http\Controllers\Master\ProductController;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\PriceTier;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductUnit;
use App\Models\Tenant\ProductUnitPrice;
use App\Models\Tenant\User as TenantUser;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * F3 backend CRUD test: ProductController + StoreProductRequest +
 * UpdateProductRequest dgn nested units + multi-tier prices.
 *
 * Fokus: validation rules + transactional integrity, BUKAN flow visual.
 */

function ownerForProductCrud(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function pcsUnitId(): int
{
    return MasterUnit::where('code', 'pcs')->value('id')
        ?? MasterUnit::first()->id;
}

function dusUnitId(): int
{
    return MasterUnit::where('code', 'dus')->value('id')
        ?? MasterUnit::where('code', '!=', 'pcs')->first()->id;
}

function defaultTierIdForCrud(): int
{
    return PriceTier::where('is_default', true)->value('id');
}

function callProductController(string $method, ?Request $request = null, ?Product $product = null)
{
    $controller = app(ProductController::class);
    $request ??= Request::create('/master/products', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($request),
        'store' => $controller->store($request),
        'update' => $controller->update($request, $product),
        'destroy' => $controller->destroy($product),
        'show' => $controller->show($product),
    };
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Cache::driver('array')->forget('price_tier:default_id');
    PriceTier::where('is_default', false)->delete();
    Product::where('sku', 'like', 'CRUD-TEST-%')->each(fn ($p) => $p->delete());
});

afterEach(function () {
    Product::where('sku', 'like', 'CRUD-TEST-%')->each(fn ($p) => $p->delete());
    PriceTier::where('is_default', false)->delete();
    Cache::driver('array')->forget('price_tier:default_id');
});

// ─────────────────────────────────────────────────────────────────────────

it('create produk dgn 1 base unit + 1 turunan + 2 tier → semua tersimpan benar', function () {
    Auth::login(ownerForProductCrud());
    $defaultId = defaultTierIdForCrud();
    $grosir = PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'], ['is_active' => true])->id;

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-001',
        'name' => 'Test Premium Food',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            [
                'unit_id' => pcsUnitId(),
                'level' => 1,
                'conversion_to_base' => 1,
                'prices' => [
                    ['price_tier_id' => $defaultId, 'price' => 10000],
                    ['price_tier_id' => $grosir->id, 'price' => 9500],
                ],
            ],
            [
                'unit_id' => dusUnitId(),
                'level' => 2,
                'conversion_to_base' => 12,
                'prices' => [
                    ['price_tier_id' => $defaultId, 'price' => 115000],
                    // tier grosir SENGAJA kosong → akan fallback
                ],
            ],
        ],
    ]);
    callProductController('store', $req);

    $product = Product::where('sku', 'CRUD-TEST-001')->with('units.prices')->firstOrFail();
    expect($product->name)->toBe('Test Premium Food')
        ->and((float) $product->price)->toBe(10000.0)        // legacy auto-sync dari base default
        ->and($product->units)->toHaveCount(2);

    $pcsUnit = $product->units->firstWhere('level', 1);
    $dusUnit = $product->units->firstWhere('level', 2);
    expect((float) $pcsUnit->conversion_to_base)->toBe(1.0)
        ->and((float) $dusUnit->conversion_to_base)->toBe(12.0)
        ->and($pcsUnit->prices)->toHaveCount(2)
        ->and($dusUnit->prices)->toHaveCount(1);

    // Fallback test: priceForTier(grosir) di dus → fallback ke default = 115000.
    expect($dusUnit->priceForTier($grosir->id))->toBe(115000.0);
});

it('update produk: ganti satuan turunan + harga (replace-all)', function () {
    Auth::login(ownerForProductCrud());
    $defaultId = defaultTierIdForCrud();
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'])->id;

    // Create
    $reqCreate = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-002',
        'name' => 'Original',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 5000],
            ]],
            ['unit_id' => dusUnitId(), 'level' => 2, 'conversion_to_base' => 6, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 28000],
            ]],
        ],
    ]);
    callProductController('store', $reqCreate);

    $product = Product::where('sku', 'CRUD-TEST-002')->firstOrFail();
    $oldUnitIds = $product->units->pluck('id')->all();

    // Update: hapus dus, ganti rasio base, ubah harga base
    $reqUpdate = Request::create('/master/products/'.$product->id, 'PUT', [
        'sku' => 'CRUD-TEST-002',
        'name' => 'Updated Name',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 6000],
            ]],
        ],
    ]);
    callProductController('update', $reqUpdate, $product);

    $fresh = $product->fresh()->load('units.prices');
    expect($fresh->name)->toBe('Updated Name')
        ->and($fresh->units)->toHaveCount(1)
        ->and((float) $fresh->units->first()->priceForTier($defaultId))->toBe(6000.0)
        ->and((float) $fresh->price)->toBe(6000.0);

    // Old unit rows ter-cascade hilang (FK cascade ke prices juga).
    expect(ProductUnit::whereIn('id', $oldUnitIds)->count())->toBe(0)
        ->and(ProductUnitPrice::whereIn('product_unit_id', $oldUnitIds)->count())->toBe(0);
});

it('hard-delete produk tanpa histori → cascade ke units + prices', function () {
    Auth::login(ownerForProductCrud());
    $defaultId = defaultTierIdForCrud();
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'])->id;

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-DEL',
        'name' => 'To Delete',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 1000],
            ]],
        ],
    ]);
    callProductController('store', $req);

    $product = Product::where('sku', 'CRUD-TEST-DEL')->firstOrFail();
    $unitIds = $product->units->pluck('id')->all();

    callProductController('destroy', product: $product);

    expect(Product::where('sku', 'CRUD-TEST-DEL')->exists())->toBeFalse()
        ->and(ProductUnit::whereIn('id', $unitIds)->count())->toBe(0)
        ->and(ProductUnitPrice::whereIn('product_unit_id', $unitIds)->count())->toBe(0);
});

it('VALIDASI: ditolak kalau tidak ada base unit (level=1)', function () {
    Auth::login(ownerForProductCrud());
    $defaultId = defaultTierIdForCrud();
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'])->id;

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-NOBASE',
        'name' => 'No Base',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => dusUnitId(), 'level' => 2, 'conversion_to_base' => 12, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 100],
            ]],
        ],
    ]);

    expect(fn () => callProductController('store', $req))->toThrow(ValidationException::class);
});

it('VALIDASI: ditolak kalau 2 base unit (level=1 duplikat)', function () {
    Auth::login(ownerForProductCrud());
    $defaultId = defaultTierIdForCrud();
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'])->id;

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-2BASE',
        'name' => '2 Base',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 1000],
            ]],
            ['unit_id' => dusUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => []],
        ],
    ]);

    expect(fn () => callProductController('store', $req))->toThrow(ValidationException::class);
});

it('VALIDASI: ditolak kalau base unit conversion_to_base != 1', function () {
    Auth::login(ownerForProductCrud());
    $defaultId = defaultTierIdForCrud();
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'])->id;

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-BADBASE',
        'name' => 'Bad Base',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 5, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 1000],
            ]],
        ],
    ]);

    expect(fn () => callProductController('store', $req))->toThrow(ValidationException::class);
});

it('VALIDASI: ditolak kalau rasio satuan turunan <= 0', function () {
    Auth::login(ownerForProductCrud());
    $defaultId = defaultTierIdForCrud();
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'])->id;

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-RATIOZERO',
        'name' => 'Zero Ratio',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 1000],
            ]],
            ['unit_id' => dusUnitId(), 'level' => 2, 'conversion_to_base' => 0, 'prices' => []],
        ],
    ]);

    expect(fn () => callProductController('store', $req))->toThrow(ValidationException::class);
});

it('VALIDASI: ditolak kalau unit_id duplikat dalam 1 produk', function () {
    Auth::login(ownerForProductCrud());
    $defaultId = defaultTierIdForCrud();
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'])->id;

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-DUP',
        'name' => 'Dup Unit',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 1000],
            ]],
            ['unit_id' => pcsUnitId(), 'level' => 2, 'conversion_to_base' => 12, 'prices' => []],
        ],
    ]);

    expect(fn () => callProductController('store', $req))->toThrow(ValidationException::class);
});

it('VALIDASI: ditolak kalau harga tier default kosong di base unit', function () {
    Auth::login(ownerForProductCrud());
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'])->id;

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-NOPRICE',
        'name' => 'No Default Price',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => []],
        ],
    ]);

    expect(fn () => callProductController('store', $req))->toThrow(ValidationException::class);
});

it('VALIDASI: ditolak kalau category_id kosong (required, beda dari schema nullable)', function () {
    Auth::login(ownerForProductCrud());
    $defaultId = defaultTierIdForCrud();

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-NOCAT',
        'name' => 'No Cat',
        // category_id sengaja tidak dikirim
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 100],
            ]],
        ],
    ]);

    expect(fn () => callProductController('store', $req))->toThrow(ValidationException::class);
});

it('OTORISASI: user tanpa master.manage ditolak', function () {
    $cashier = TenantUser::firstOrCreate(['email' => 'cashier-pcrud@vetly.id'], [
        'name' => 'Cashier PCrud', 'password' => bcrypt('test'), 'is_active' => true,
    ]);
    $cashier->syncRoles(['cashier']); // cashier tidak punya master.manage

    Auth::login($cashier);
    $defaultId = defaultTierIdForCrud();
    $catId = \App\Models\Tenant\Category::firstOrCreate(['name' => 'Test Cat'])->id;

    $req = Request::create('/master/products', 'POST', [
        'sku' => 'CRUD-TEST-AUTH',
        'name' => 'Auth Test',
        'category_id' => $catId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'units' => [
            ['unit_id' => pcsUnitId(), 'level' => 1, 'conversion_to_base' => 1, 'prices' => [
                ['price_tier_id' => $defaultId, 'price' => 100],
            ]],
        ],
    ]);

    expect(fn () => callProductController('store', $req))->toThrow(AuthorizationException::class);
});
