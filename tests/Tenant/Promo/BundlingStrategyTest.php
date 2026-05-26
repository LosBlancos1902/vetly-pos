<?php

use App\Models\Tenant\Promo;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\Promo\PromoContext;
use App\Services\Promo\PromoResolver;
use App\Services\Promo\Strategies\BundlingStrategy;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Support\Facades\Auth;

/**
 * Tipe 4 — Bundling. Test fokus pada detection setCount + diskon
 * nominal/percent + cap per-set + edge cases.
 *
 * Cleanup: Promo::query()->delete() di beforeEach+afterEach (pattern sama
 * dgn PromoEngineTest).
 */

function ownerForBundle(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function makeBundleCtx(Warehouse $warehouse, array $cartItems): PromoContext
{
    $subtotal = 0.0;
    $totalQty = 0.0;
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
        $totalQty += (float) $i['qty'];
    }

    return new PromoContext(
        items: $items,
        warehouse: $warehouse,
        customerId: null,
        datetime: now(),
        subtotal: $subtotal,
        manualDiscount: 0,
    );
}

function makeBundlePromo(array $rules, string $kind = 'nominal', float $value = 10000, ?float $cap = null): Promo
{
    return Promo::create([
        'name' => 'Bundle Test '.uniqid(),
        'type' => Promo::TYPE_BUNDLING,
        'discount_kind' => $kind,
        'discount_value' => $value,
        'max_discount_amount' => $cap,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
        'is_stackable' => false,
        'config' => ['bundle_rules' => $rules],
    ]);
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForBundle());
    Promo::query()->delete();
});

afterEach(function () {
    Promo::query()->delete();
});

// ─── DETECTION ────────────────────────────────────────────────

it('BUNDLE 1: rule (A:1, B:2) + cart (A=1,B=2) → 1 set, diskon nominal sesuai value', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeBundlePromo([
        ['product_id' => 100, 'qty' => 1],
        ['product_id' => 200, 'qty' => 2],
    ], value: 15000);

    $ctx = makeBundleCtx($w, [
        ['product_id' => 100, 'qty' => 1, 'price' => 20000],
        ['product_id' => 200, 'qty' => 2, 'price' => 10000],
    ]);

    $strategy = app(BundlingStrategy::class);
    expect($strategy->qualifies($promo, $ctx))->toBeTrue();
    expect($strategy->computeDiscount($promo, $ctx))->toBe(15000.0); // 1 set × 15k
});

it('BUNDLE 2: cart (A=2,B=5) → 2 set (multi-set), diskon × 2', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeBundlePromo([
        ['product_id' => 100, 'qty' => 1],
        ['product_id' => 200, 'qty' => 2],
    ], value: 15000);

    $ctx = makeBundleCtx($w, [
        ['product_id' => 100, 'qty' => 2, 'price' => 20000],
        ['product_id' => 200, 'qty' => 5, 'price' => 10000],
    ]);

    // setCount = min(floor(2/1), floor(5/2)) = min(2, 2) = 2
    expect(app(BundlingStrategy::class)->computeDiscount($promo, $ctx))->toBe(30000.0);
});

it('BUNDLE 3: cart cuma A (B missing) → bundle gugur, diskon 0', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeBundlePromo([
        ['product_id' => 100, 'qty' => 1],
        ['product_id' => 200, 'qty' => 2],
    ], value: 15000);

    $ctx = makeBundleCtx($w, [
        ['product_id' => 100, 'qty' => 5, 'price' => 20000],
        // B (200) tidak ada
    ]);

    $strategy = app(BundlingStrategy::class);
    expect($strategy->qualifies($promo, $ctx))->toBeFalse();
    expect($strategy->computeDiscount($promo, $ctx))->toBe(0.0);
});

it('BUNDLE 4: discount percent + cap per-set diterapkan benar', function () {
    $w = Warehouse::firstOrFail();
    // Bundle A:1 + B:2. Cart price A=20k, B=10k.
    // Nilai 1 set = 1×20k + 2×10k = 40k.
    // Percent 25% → 10k per set. Cap 7k → diskon per set 7k.
    // 2 set → 14k.
    $promo = makeBundlePromo([
        ['product_id' => 100, 'qty' => 1],
        ['product_id' => 200, 'qty' => 2],
    ], kind: 'percent', value: 25, cap: 7000);

    $ctx = makeBundleCtx($w, [
        ['product_id' => 100, 'qty' => 2, 'price' => 20000],
        ['product_id' => 200, 'qty' => 4, 'price' => 10000],
    ]);

    expect(app(BundlingStrategy::class)->computeDiscount($promo, $ctx))->toBe(14000.0);
});

it('BUNDLE 5: bundle dengan 3 komponen (A:1, B:1, C:1) → semua harus ada', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeBundlePromo([
        ['product_id' => 100, 'qty' => 1],
        ['product_id' => 200, 'qty' => 1],
        ['product_id' => 300, 'qty' => 1],
    ], value: 5000);

    // Lengkap → 1 set
    $ctxFull = makeBundleCtx($w, [
        ['product_id' => 100, 'qty' => 1, 'price' => 10000],
        ['product_id' => 200, 'qty' => 1, 'price' => 10000],
        ['product_id' => 300, 'qty' => 1, 'price' => 10000],
    ]);
    expect(app(BundlingStrategy::class)->computeDiscount($promo, $ctxFull))->toBe(5000.0);

    // Kurang 1 → gugur
    $ctxPartial = makeBundleCtx($w, [
        ['product_id' => 100, 'qty' => 1, 'price' => 10000],
        ['product_id' => 200, 'qty' => 1, 'price' => 10000],
        // 300 tidak ada
    ]);
    expect(app(BundlingStrategy::class)->computeDiscount($promo, $ctxPartial))->toBe(0.0);
});

it('BUNDLE 6: item duplicate di cart (produk sama 2 baris) → di-sum', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeBundlePromo([
        ['product_id' => 100, 'qty' => 1],
        ['product_id' => 200, 'qty' => 2],
    ], value: 5000);

    // A muncul 2 line (1+1=2), B muncul 2 line (1+1=2) — totalA=2, totalB=2.
    // setCount = min(floor(2/1), floor(2/2)) = min(2,1) = 1
    $ctx = makeBundleCtx($w, [
        ['product_id' => 100, 'qty' => 1, 'price' => 20000],
        ['product_id' => 100, 'qty' => 1, 'price' => 20000],
        ['product_id' => 200, 'qty' => 1, 'price' => 10000],
        ['product_id' => 200, 'qty' => 1, 'price' => 10000],
    ]);

    expect(app(BundlingStrategy::class)->computeDiscount($promo, $ctx))->toBe(5000.0);
});

it('BUNDLE 7: 5-dim guard — periode lewat → tidak qualifies', function () {
    $w = Warehouse::firstOrFail();
    $promo = Promo::create([
        'name' => 'Bundle Expired',
        'type' => Promo::TYPE_BUNDLING,
        'discount_kind' => 'nominal',
        'discount_value' => 10000,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(1),
        'is_active' => true,
        'config' => ['bundle_rules' => [
            ['product_id' => 100, 'qty' => 1],
            ['product_id' => 200, 'qty' => 1],
        ]],
    ]);

    $ctx = makeBundleCtx($w, [
        ['product_id' => 100, 'qty' => 1, 'price' => 10000],
        ['product_id' => 200, 'qty' => 1, 'price' => 10000],
    ]);

    expect(app(BundlingStrategy::class)->qualifies($promo, $ctx))->toBeFalse();
});

it('BUNDLE 8: qty pecahan di rules (A:0.5) → floor(cartQty/required)', function () {
    $w = Warehouse::firstOrFail();
    $promo = makeBundlePromo([
        ['product_id' => 100, 'qty' => 0.5],
        ['product_id' => 200, 'qty' => 1],
    ], value: 3000);

    // A=1.6 / 0.5 = 3.2 → floor 3; B=4/1 = 4 → setCount = min(3,4) = 3
    $ctx = makeBundleCtx($w, [
        ['product_id' => 100, 'qty' => 1.6, 'price' => 5000],
        ['product_id' => 200, 'qty' => 4, 'price' => 5000],
    ]);

    expect(app(BundlingStrategy::class)->computeDiscount($promo, $ctx))->toBe(9000.0);
});
