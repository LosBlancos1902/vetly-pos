<?php

use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\PriceTier;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductUnit;
use App\Models\Tenant\ProductUnitPrice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Single-source-of-truth fallback test untuk ProductUnit::priceForTier.
 *
 * Aturan (urut):
 *   1. exact tier match
 *   2. tier default untuk satuan yg SAMA
 *   3. legacy: product_units.price → products.price × conversion
 *
 * PENTING: tenant test DB persist antar test (lihat TenantTestCase
 * docblock). Jangan mutate seed data — selalu pakai fixture isolated
 * yg di-create di beforeEach + di-cleanup di afterEach.
 */

beforeEach(function () {
    Cache::driver('array')->forget('price_tier:default_id');
    PriceTier::where('is_default', false)->delete(); // cascade ke prices

    $sku = 'TIER-FIXT-'.uniqid();
    $baseUnitId = MasterUnit::first()->id;

    $this->fixtureProduct = Product::create([
        'sku' => $sku,
        'name' => 'Tier Fixture '.$sku,
        'base_unit_id' => $baseUnitId,
        'type' => Product::TYPE_SALEABLE_RETAIL,
        'price' => 10000,
        'cost_avg' => 0,
        'is_active' => true,
    ]);

    $this->fixtureUnit = ProductUnit::create([
        'product_id' => $this->fixtureProduct->id,
        'unit_id' => $baseUnitId,
        'level' => 1,
        'conversion_to_base' => 1,
        'is_purchase_unit' => true,
        'is_sale_unit' => true,
        'price' => null, // sengaja null — kita test fallback ke products.price
    ]);
});

afterEach(function () {
    // Cascade: product → product_units → product_unit_prices.
    $this->fixtureProduct?->delete();
    PriceTier::where('is_default', false)->delete();
    Cache::driver('array')->forget('price_tier:default_id');
});

it('step 1: exact tier match → return harga di tier itu', function () {
    $grosir = PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);

    ProductUnitPrice::create([
        'product_unit_id' => $this->fixtureUnit->id,
        'price_tier_id' => $grosir->id,
        'price' => 8888,
    ]);

    expect($this->fixtureUnit->priceForTier($grosir->id))->toBe(8888.0);
});

it('step 2: tier kosong → fallback ke tier default untuk satuan yg sama', function () {
    $defaultId = PriceTier::where('is_default', true)->value('id');
    $grosir = PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);

    ProductUnitPrice::create([
        'product_unit_id' => $this->fixtureUnit->id,
        'price_tier_id' => $defaultId,
        'price' => 5000,
    ]);
    // Tier grosir SENGAJA kosong.

    expect($this->fixtureUnit->priceForTier($grosir->id))->toBe(5000.0);
});

it('step 3: default tier juga kosong → fallback ke product_units.price legacy', function () {
    $grosir = PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);

    // Keduanya kosong di product_unit_prices. Sisakan kolom legacy.
    $this->fixtureUnit->update(['price' => 1234]);

    expect($this->fixtureUnit->fresh()->priceForTier($grosir->id))->toBe(1234.0);
});

it('step 3b: legacy unit price NULL → fallback ke products.price × conversion', function () {
    $this->fixtureProduct->update(['price' => 7500]);
    $this->fixtureUnit->update(['price' => null, 'conversion_to_base' => 2]);

    $defaultId = PriceTier::where('is_default', true)->value('id');

    expect($this->fixtureUnit->fresh()->priceForTier($defaultId))->toBe(15000.0); // 7500 × 2
});

it('cache: defaultTierId hanya 1 query meski dipanggil berkali-kali', function () {
    Cache::driver('array')->forget('price_tier:default_id');

    DB::enableQueryLog();
    DB::flushQueryLog();

    for ($i = 0; $i < 50; $i++) {
        ProductUnit::defaultTierId();
    }

    $queries = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'price_tiers'))
        ->count();

    DB::disableQueryLog();

    expect($queries)->toBe(1);
});

it('safety: priceForTier dgn tier id yg tidak exist → tetap fallback ke default, tidak crash', function () {
    $defaultId = PriceTier::where('is_default', true)->value('id');

    ProductUnitPrice::create([
        'product_unit_id' => $this->fixtureUnit->id,
        'price_tier_id' => $defaultId,
        'price' => 9999,
    ]);

    expect($this->fixtureUnit->priceForTier(999999))->toBe(9999.0);
});
