<?php

use App\Models\Tenant\Inventory;
use App\Models\Tenant\Journal;
use App\Models\Tenant\Product;
use App\Models\Tenant\Promo;
use App\Models\Tenant\PromoApplication;
use App\Models\Tenant\Sale;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\JournalEngine;
use App\Services\Promo\PromoContext;
use App\Services\Promo\PromoResolver;
use App\Services\ServiceBundleService;
use App\Services\StockMovement;
use App\Services\UnitConverter;
use App\Services\VetlySyncService;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Bundling + Tebus Murah — integrasi e2e via CashierController.
 *
 * Yang divalidate:
 *   - sale.promo_discount_amount terisi sesuai diskon strategy
 *   - Jurnal BALANCE (debit = credit) via postSplitSaleWithPromo
 *   - PromoApplication tercatat dgn amount + coa_code
 *   - HPP TIDAK terpengaruh (cost_snapshot × qty independent)
 *   - Stacking: bundling stackable + periode stackable → keduanya apply
 *   - Exclusive: bundling vs periode → max amount wins
 */

function ownerForBT(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function freshInventory(Product $p, Warehouse $w, float $qty = 100, float $cost = 5000): void
{
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p->id, 'warehouse_id' => $w->id],
        ['qty' => $qty, 'cost_avg' => $cost, 'updated_at' => now(), 'created_at' => now()],
    );
    Product::where('id', $p->id)->update(['cost_avg' => $cost]);
}

function callCashierStoreBT(array $payload): Sale
{
    $controller = app(\App\Http\Controllers\POS\CashierController::class);
    $stock = new StockMovement(new HppCalculator, new UnitConverter);
    $req = Request::create('/pos/sales', 'POST', $payload);
    $req->setUserResolver(fn () => Auth::user());

    $controller->store($req, $stock, app(JournalEngine::class),
        new ServiceBundleService($stock, new UnitConverter),
        new VetlySyncService);

    return Sale::latest('id')->firstOrFail();
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForBT());
    Promo::query()->delete();
});

afterEach(function () {
    Promo::query()->delete();
});

// ─── BUNDLING — end-to-end ──────────────────────────────────────────

it('BUNDLING e2e: sale dgn bundle kepicu → diskon di total + jurnal balance + COA tercatat', function () {
    $w = Warehouse::firstOrFail();
    $a = Product::where('sku', 'SKU-001')->firstOrFail();
    $b = Product::where('sku', 'SKU-002')->firstOrFail();
    freshInventory($a, $w, 100, 3000);
    freshInventory($b, $w, 100, 4000);

    Promo::create([
        'name' => 'Bundle Test E2E',
        'type' => Promo::TYPE_BUNDLING,
        'discount_kind' => 'nominal',
        'discount_value' => 5000,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
        'is_stackable' => false,
        'config' => [
            'bundle_rules' => [
                ['product_id' => $a->id, 'qty' => 1],
                ['product_id' => $b->id, 'qty' => 1],
            ],
        ],
    ]);

    // Cart: A=2, B=2 → 2 set → diskon 2 × 5000 = 10000
    // Subtotal = 2×10000 + 2×8000 = 36000. Total = 36000 − 10000 = 26000.
    $sale = callCashierStoreBT([
        'warehouse_id' => $w->id,
        'items' => [
            ['product_id' => $a->id, 'unit_id' => $a->base_unit_id, 'qty' => 2, 'price' => 10000],
            ['product_id' => $b->id, 'unit_id' => $b->base_unit_id, 'qty' => 2, 'price' => 8000],
        ],
        'payment_method' => 'cash',
        'amount_paid' => 26000,
    ]);

    expect((float) $sale->subtotal)->toBe(36000.0)
        ->and((float) $sale->promo_discount_amount)->toBe(10000.0)
        ->and((float) $sale->total)->toBe(26000.0);

    // PromoApplication
    $app = PromoApplication::where('sale_id', $sale->id)->first();
    expect($app)->not->toBeNull()
        ->and((float) $app->discount_amount)->toBe(10000.0)
        ->and($app->coa_code)->toBe('4199');

    // Jurnal balance
    $journal = Journal::where('ref_type', Sale::class)->where('ref_id', $sale->id)->latest('id')->first();
    $entries = $journal->entries()->with('coa')->get();
    expect($entries->sum(fn ($e) => (float) $e->debit))
        ->toBe($entries->sum(fn ($e) => (float) $e->credit));

    // HPP unchanged: 2×3000 + 2×4000 = 14000 (terpisah di line 5100, semua retail)
    $hpp = $entries->firstWhere(fn ($e) => $e->coa->code === '5100');
    expect((float) $hpp->debit)->toBe(14000.0);

    // Diskon promo di 4199 (debit)
    $discountEntry = $entries->firstWhere(fn ($e) => $e->coa->code === '4199' && (float) $e->debit > 0);
    expect((float) $discountEntry->debit)->toBe(10000.0);
});

it('BUNDLING e2e: bundle GUGUR (B tidak di cart) → tidak ada diskon + jurnal pakai postSplitSale path', function () {
    $w = Warehouse::firstOrFail();
    $a = Product::where('sku', 'SKU-001')->firstOrFail();
    $b = Product::where('sku', 'SKU-002')->firstOrFail();
    freshInventory($a, $w, 100, 3000);
    freshInventory($b, $w, 100, 4000);

    Promo::create([
        'name' => 'Bundle Gugur',
        'type' => Promo::TYPE_BUNDLING,
        'discount_kind' => 'nominal',
        'discount_value' => 5000,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
        'config' => [
            'bundle_rules' => [
                ['product_id' => $a->id, 'qty' => 1],
                ['product_id' => $b->id, 'qty' => 1],
            ],
        ],
    ]);

    // Cart cuma A → bundle gugur
    $sale = callCashierStoreBT([
        'warehouse_id' => $w->id,
        'items' => [
            ['product_id' => $a->id, 'unit_id' => $a->base_unit_id, 'qty' => 5, 'price' => 10000],
        ],
        'payment_method' => 'cash',
        'amount_paid' => 50000,
    ]);

    expect((float) $sale->promo_discount_amount)->toBe(0.0)
        ->and((float) $sale->total)->toBe(50000.0);

    // No promo application
    expect(PromoApplication::where('sale_id', $sale->id)->count())->toBe(0);
});

// ─── TEBUS MURAH — end-to-end ────────────────────────────────────────

it('TEBUS e2e: sale dgn syarat OK + tebus di cart → diskon = qty × selisih + jurnal balance', function () {
    $w = Warehouse::firstOrFail();
    $qual = Product::where('sku', 'SKU-001')->firstOrFail();   // syarat
    $tebus = Product::where('sku', 'SKU-002')->firstOrFail();  // produk tebus
    freshInventory($qual, $w, 100, 3000);
    freshInventory($tebus, $w, 100, 4000);

    Promo::create([
        'name' => 'Tebus Test E2E',
        'type' => Promo::TYPE_TEBUS_MURAH,
        'discount_kind' => 'nominal',
        'discount_value' => 1,
        'min_purchase' => 50000,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
        'config' => [
            'qualifying_product_ids' => [],
            'qualifying_category_ids' => [],
            'qualifying_min_qty_per_set' => 1,
            'tebus_product_id' => $tebus->id,
            'tebus_price' => 5000,         // tebus murah jadi 5k
            'max_tebus_per_transaction' => null,
        ],
    ]);

    // Cart: syarat 6×10000=60k (≥ min_purchase 50k), tebus 1 unit @ 10k normal
    // Diskon = 1 × (10000 − 5000) = 5000.
    // Subtotal = 60k + 10k = 70k. Total = 70k − 5k = 65k.
    $sale = callCashierStoreBT([
        'warehouse_id' => $w->id,
        'items' => [
            ['product_id' => $qual->id, 'unit_id' => $qual->base_unit_id, 'qty' => 6, 'price' => 10000],
            ['product_id' => $tebus->id, 'unit_id' => $tebus->base_unit_id, 'qty' => 1, 'price' => 10000],
        ],
        'payment_method' => 'cash',
        'amount_paid' => 65000,
    ]);

    expect((float) $sale->subtotal)->toBe(70000.0)
        ->and((float) $sale->promo_discount_amount)->toBe(5000.0)
        ->and((float) $sale->total)->toBe(65000.0);

    $app = PromoApplication::where('sale_id', $sale->id)->first();
    expect($app)->not->toBeNull()
        ->and((float) $app->discount_amount)->toBe(5000.0);

    // Jurnal balance
    $journal = Journal::where('ref_type', Sale::class)->where('ref_id', $sale->id)->latest('id')->first();
    $entries = $journal->entries()->with('coa')->get();
    expect($entries->sum(fn ($e) => (float) $e->debit))
        ->toBe($entries->sum(fn ($e) => (float) $e->credit));

    // HPP utuh: 6×3000 + 1×4000 = 22000
    $hpp = $entries->firstWhere(fn ($e) => $e->coa->code === '5100');
    expect((float) $hpp->debit)->toBe(22000.0);
});

it('TEBUS e2e: syarat OK tapi tebus TIDAK di cart → tidak ada diskon', function () {
    $w = Warehouse::firstOrFail();
    $qual = Product::where('sku', 'SKU-001')->firstOrFail();
    $tebus = Product::where('sku', 'SKU-002')->firstOrFail();
    freshInventory($qual, $w, 100, 3000);
    freshInventory($tebus, $w, 100, 4000);

    Promo::create([
        'name' => 'Tebus Skip',
        'type' => Promo::TYPE_TEBUS_MURAH,
        'discount_kind' => 'nominal',
        'discount_value' => 1,
        'min_purchase' => 50000,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
        'config' => [
            'qualifying_product_ids' => [],
            'qualifying_category_ids' => [],
            'qualifying_min_qty_per_set' => 1,
            'tebus_product_id' => $tebus->id,
            'tebus_price' => 5000,
            'max_tebus_per_transaction' => null,
        ],
    ]);

    // Cart cuma syarat (tidak scan tebus)
    $sale = callCashierStoreBT([
        'warehouse_id' => $w->id,
        'items' => [
            ['product_id' => $qual->id, 'unit_id' => $qual->base_unit_id, 'qty' => 6, 'price' => 10000],
        ],
        'payment_method' => 'cash',
        'amount_paid' => 60000,
    ]);

    expect((float) $sale->promo_discount_amount)->toBe(0.0)
        ->and((float) $sale->total)->toBe(60000.0);
    expect(PromoApplication::where('sale_id', $sale->id)->count())->toBe(0);
});

// ─── STACKING — Bundling + Periode ─────────────────────────────────────

it('STACKING: bundling stackable + periode stackable → keduanya apply', function () {
    $w = Warehouse::firstOrFail();
    $a = Product::where('sku', 'SKU-001')->firstOrFail();
    $b = Product::where('sku', 'SKU-002')->firstOrFail();
    freshInventory($a, $w, 100, 3000);
    freshInventory($b, $w, 100, 4000);

    // Bundling stackable: A+B → 5000 per set
    Promo::create([
        'name' => 'Bundle Stack',
        'type' => Promo::TYPE_BUNDLING,
        'discount_kind' => 'nominal',
        'discount_value' => 5000,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
        'is_stackable' => true,
        'config' => ['bundle_rules' => [
            ['product_id' => $a->id, 'qty' => 1],
            ['product_id' => $b->id, 'qty' => 1],
        ]],
    ]);
    // Periode stackable 10%
    Promo::create([
        'name' => 'Periode Stack',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 10,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
        'is_stackable' => true,
    ]);

    // Cart: A=1 B=1, subtotal = 10k+8k = 18k.
    // Bundling 1 set = 5000. Periode 10% × 18000 = 1800. Total diskon = 6800.
    // Total = 18000 − 6800 = 11200.
    $sale = callCashierStoreBT([
        'warehouse_id' => $w->id,
        'items' => [
            ['product_id' => $a->id, 'unit_id' => $a->base_unit_id, 'qty' => 1, 'price' => 10000],
            ['product_id' => $b->id, 'unit_id' => $b->base_unit_id, 'qty' => 1, 'price' => 8000],
        ],
        'payment_method' => 'cash',
        'amount_paid' => 11200,
    ]);

    expect((float) $sale->subtotal)->toBe(18000.0)
        ->and((float) $sale->promo_discount_amount)->toBe(6800.0)
        ->and((float) $sale->total)->toBe(11200.0);

    // 2 promo applications
    expect(PromoApplication::where('sale_id', $sale->id)->count())->toBe(2);

    // Jurnal balance
    $journal = Journal::where('ref_type', Sale::class)->where('ref_id', $sale->id)->latest('id')->first();
    $entries = $journal->entries;
    expect($entries->sum(fn ($e) => (float) $e->debit))
        ->toBe($entries->sum(fn ($e) => (float) $e->credit));
});

// ─── EXCLUSIVE: Bundling vs Periode (head-to-head pick max) ──────────────

it('EXCLUSIVE: bundling vs periode (keduanya exclusive) → resolver pick max amount', function () {
    $w = Warehouse::firstOrFail();
    $a = Product::where('sku', 'SKU-001')->firstOrFail();
    $b = Product::where('sku', 'SKU-002')->firstOrFail();
    freshInventory($a, $w, 100, 3000);
    freshInventory($b, $w, 100, 4000);

    // Bundling exclusive: 3000 per set (kecil)
    Promo::create([
        'name' => 'Bundle Excl Small',
        'type' => Promo::TYPE_BUNDLING,
        'discount_kind' => 'nominal',
        'discount_value' => 3000,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
        'is_stackable' => false,
        'config' => ['bundle_rules' => [
            ['product_id' => $a->id, 'qty' => 1],
            ['product_id' => $b->id, 'qty' => 1],
        ]],
    ]);
    // Periode exclusive 20% → 18000 × 20% = 3600 (lebih besar)
    Promo::create([
        'name' => 'Periode Excl Big',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 20,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
        'is_stackable' => false,
    ]);

    $sale = callCashierStoreBT([
        'warehouse_id' => $w->id,
        'items' => [
            ['product_id' => $a->id, 'unit_id' => $a->base_unit_id, 'qty' => 1, 'price' => 10000],
            ['product_id' => $b->id, 'unit_id' => $b->base_unit_id, 'qty' => 1, 'price' => 8000],
        ],
        'payment_method' => 'cash',
        'amount_paid' => 14400,
    ]);

    // Pick periode 3600 (bukan bundling 3000)
    expect((float) $sale->promo_discount_amount)->toBe(3600.0)
        ->and((float) $sale->total)->toBe(14400.0);
    expect(PromoApplication::where('sale_id', $sale->id)->count())->toBe(1);
    expect(PromoApplication::where('sale_id', $sale->id)->first()->promo->name)
        ->toBe('Periode Excl Big');
});
