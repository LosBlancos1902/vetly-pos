<?php

use App\Models\Tenant\Category;
use App\Models\Tenant\Product;
use App\Models\Tenant\Promo;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\Promo\PromoContext;
use App\Services\Promo\Strategies\TebusMurahStrategy;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Support\Facades\Auth;

/**
 * Tipe 5 — Tebus Murah. Test fokus pada syarat, tebus in cart, effective
 * qty cap, clamp 0, 5-dim guard.
 *
 * Untuk qualifying_category_ids test, pakai produk real dari demo
 * (SKU-001/002/003 punya category_id=1).
 */

function ownerForTebus(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function makeTebusCtx(Warehouse $warehouse, array $cartItems, float $manualDiscount = 0): PromoContext
{
    $subtotal = 0.0;
    $items = [];
    foreach ($cartItems as $i) {
        $items[] = [
            'product_id' => (int) $i['product_id'],
            'unit_id' => $i['unit_id'] ?? 1,
            'qty' => (float) $i['qty'],
            'price' => (float) $i['price'],
            'discount_amount' => (float) ($i['discount_amount'] ?? 0),
        ];
        $subtotal += (float) $i['qty'] * (float) $i['price'];
    }

    return new PromoContext(
        items: $items,
        warehouse: $warehouse,
        customerId: null,
        datetime: now(),
        subtotal: $subtotal,
        manualDiscount: $manualDiscount,
    );
}

function makeTebusPromo(array $config, float $minPurchase = 0): Promo
{
    return Promo::create([
        'name' => 'Tebus Test '.uniqid(),
        'type' => Promo::TYPE_TEBUS_MURAH,
        // discount_kind/value tidak dipakai strategy, tapi NOT NULL di DB
        'discount_kind' => 'nominal',
        'discount_value' => 1,
        'min_purchase' => $minPurchase,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
        'is_stackable' => false,
        'config' => $config,
    ]);
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForTebus());
    Promo::query()->delete();
});

afterEach(function () {
    Promo::query()->delete();
});

// ─── 1. SYARAT min_purchase OK + tebus DI CART ──────────────────────────

it('TEBUS 1: syarat min_purchase OK + tebus di cart → diskon = qty × (priceNormal − tebusPrice)', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeTebusPromo([
        'qualifying_product_ids' => [],
        'qualifying_category_ids' => [],
        'qualifying_min_qty_per_set' => 1,
        'tebus_product_id' => 999, // dummy tebus product
        'tebus_price' => 5000,
        'max_tebus_per_transaction' => null,
    ], minPurchase: 50000);

    // Cart: produk lain 60k (memenuhi min_purchase), + 2 unit tebus @ 10k
    $ctx = makeTebusCtx($w, [
        ['product_id' => 1, 'qty' => 2, 'price' => 30000],
        ['product_id' => 999, 'qty' => 2, 'price' => 10000],
    ]);

    $strategy = app(TebusMurahStrategy::class);
    expect($strategy->qualifies($promo, $ctx))->toBeTrue();
    // 2 × (10000 − 5000) = 10000
    expect($strategy->computeDiscount($promo, $ctx))->toBe(10000.0);
});

// ─── 2. SYARAT OK + TEBUS NGGAK DI CART (customer skip, valid) ────────

it('TEBUS 2: syarat OK tapi tebus TIDAK di cart → diskon 0 (customer skip valid)', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeTebusPromo([
        'qualifying_product_ids' => [],
        'qualifying_category_ids' => [],
        'qualifying_min_qty_per_set' => 1,
        'tebus_product_id' => 999,
        'tebus_price' => 5000,
        'max_tebus_per_transaction' => null,
    ], minPurchase: 50000);

    // Cart belanja 60k tapi nggak scan tebus_product
    $ctx = makeTebusCtx($w, [
        ['product_id' => 1, 'qty' => 2, 'price' => 30000],
    ]);

    $strategy = app(TebusMurahStrategy::class);
    expect($strategy->qualifies($promo, $ctx))->toBeFalse();
    expect($strategy->computeDiscount($promo, $ctx))->toBe(0.0);
});

// ─── 3. SYARAT NGGAK KEPENUHI + TEBUS DI CART ─────────────────────────

it('TEBUS 3: syarat min_purchase NGGAK kepenuhi + tebus di cart → qualifies=false, resolver total=0', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeTebusPromo([
        'qualifying_product_ids' => [],
        'qualifying_category_ids' => [],
        'qualifying_min_qty_per_set' => 1,
        'tebus_product_id' => 999,
        'tebus_price' => 5000,
        'max_tebus_per_transaction' => null,
    ], minPurchase: 100000); // tinggi

    // Cart cuma 30k (di bawah min)
    $ctx = makeTebusCtx($w, [
        ['product_id' => 1, 'qty' => 1, 'price' => 20000],
        ['product_id' => 999, 'qty' => 1, 'price' => 10000],
    ]);

    // qualifies dilakukan dulu di resolver — kalau false, computeDiscount
    // tidak pernah dipanggil. End-to-end via resolver:
    expect(app(TebusMurahStrategy::class)->qualifies($promo, $ctx))->toBeFalse();
    expect(app(\App\Services\Promo\PromoResolver::class)->resolve($ctx)->totalDiscount)->toBe(0.0);
});

// ─── 4. QUALIFYING PRODUCTS + 2 SET KEPENUHI → TEBUS 2 UNIT ───────────

it('TEBUS 4: qualifying_product_ids dipakai, 2 set qualifying + scan 2 tebus → diskon 2 unit', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeTebusPromo([
        'qualifying_product_ids' => [1, 2], // SKU-001, SKU-002
        'qualifying_category_ids' => [],
        'qualifying_min_qty_per_set' => 3, // 3 unit qualifying → 1 set
        'tebus_product_id' => 999,
        'tebus_price' => 5000,
        'max_tebus_per_transaction' => null,
    ]);

    // qualifyingQty = 3+3 = 6 → setQualifying = floor(6/3) = 2
    // tebusInCart = 2 → effective = min(2, 2, ∞) = 2
    // diskon = 2 × (8000−5000) = 6000
    $ctx = makeTebusCtx($w, [
        ['product_id' => 1, 'qty' => 3, 'price' => 10000],
        ['product_id' => 2, 'qty' => 3, 'price' => 10000],
        ['product_id' => 999, 'qty' => 2, 'price' => 8000],
    ]);

    expect(app(TebusMurahStrategy::class)->computeDiscount($promo, $ctx))->toBe(6000.0);
});

// ─── 5. MAX TEBUS PER TRANSACTION CAP ──────────────────────────────────

it('TEBUS 5: max_tebus_per_transaction=1 → cap walau setCount lebih', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeTebusPromo([
        'qualifying_product_ids' => [],
        'qualifying_category_ids' => [],
        'qualifying_min_qty_per_set' => 1,
        'tebus_product_id' => 999,
        'tebus_price' => 5000,
        'max_tebus_per_transaction' => 1,
    ], minPurchase: 10000);

    // Customer scan 5 tebus, syarat OK → tetap cuma 1 yang diskon
    $ctx = makeTebusCtx($w, [
        ['product_id' => 1, 'qty' => 1, 'price' => 50000],
        ['product_id' => 999, 'qty' => 5, 'price' => 10000],
    ]);

    expect(app(TebusMurahStrategy::class)->computeDiscount($promo, $ctx))->toBe(5000.0); // 1 × 5000
});

// ─── 6. TEBUS_PRICE > NORMAL → CLAMP 0 ──────────────────────────────────

it('TEBUS 6: tebus_price > harga normal (owner typo) → diskon clamp 0', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeTebusPromo([
        'qualifying_product_ids' => [],
        'qualifying_category_ids' => [],
        'qualifying_min_qty_per_set' => 1,
        'tebus_product_id' => 999,
        'tebus_price' => 15000, // > harga normal
        'max_tebus_per_transaction' => null,
    ], minPurchase: 10000);

    $ctx = makeTebusCtx($w, [
        ['product_id' => 1, 'qty' => 1, 'price' => 20000],
        ['product_id' => 999, 'qty' => 1, 'price' => 10000], // normal lebih murah dari tebus
    ]);

    expect(app(TebusMurahStrategy::class)->computeDiscount($promo, $ctx))->toBe(0.0);
});

// ─── 7. 5-DIM CABANG MISMATCH ──────────────────────────────────────────

it('TEBUS 7: 5-dim guard cabang — promo restricted ke WH lain → tidak qualifies', function () {
    $wPromo = Warehouse::firstOrCreate(
        ['code' => 'WH-TEBUS-PROMO'],
        ['name' => 'Tebus Promo WH', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    $wOther = Warehouse::firstOrFail(); // bukan WH-TEBUS-PROMO

    $promo = makeTebusPromo([
        'qualifying_product_ids' => [],
        'qualifying_category_ids' => [],
        'qualifying_min_qty_per_set' => 1,
        'tebus_product_id' => 999,
        'tebus_price' => 5000,
        'max_tebus_per_transaction' => null,
    ], minPurchase: 10000);
    $promo->warehouses()->sync([$wPromo->id]);

    // Transaksi di warehouse LAIN
    $ctx = makeTebusCtx($wOther, [
        ['product_id' => 1, 'qty' => 1, 'price' => 50000],
        ['product_id' => 999, 'qty' => 1, 'price' => 10000],
    ]);

    expect(app(TebusMurahStrategy::class)->qualifies($promo, $ctx))->toBeFalse();
});
