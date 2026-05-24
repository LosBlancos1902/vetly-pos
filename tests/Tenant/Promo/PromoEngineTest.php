<?php

use App\Models\Tenant\Coa;
use App\Models\Tenant\Journal;
use App\Models\Tenant\Product;
use App\Models\Tenant\Promo;
use App\Models\Tenant\Sale;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\JournalEngine;
use App\Services\Promo\PromoContext;
use App\Services\Promo\PromoResolver;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Support\Facades\Auth;

/**
 * F2 engine test: resolver pipeline + strategy logic + JournalEngine
 * balance integration.
 *
 * Test pakai fixture promo per-case (create + cleanup) supaya state
 * tidak nyangkut antar test di tenant DB persistent.
 */

function ownerForPromo(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function makeCtx(Warehouse $warehouse, float $subtotal, float $totalQty = 5): PromoContext
{
    return new PromoContext(
        items: [
            ['product_id' => 1, 'unit_id' => 1, 'qty' => $totalQty, 'price' => $subtotal / max(1, $totalQty), 'discount_amount' => 0],
        ],
        warehouse: $warehouse,
        customerId: null,
        datetime: now(),
        subtotal: $subtotal,
        manualDiscount: 0,
    );
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForPromo());
    Promo::query()->delete(); // bersih
});

afterEach(function () {
    Promo::query()->delete();
});

// ─── RESOLVER QUALIFICATION ─────────────────────────────────────────────

it('RESOLVER: promo aktif dalam periode → applicable', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => 'Test Periode',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 10,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);

    $result = app(PromoResolver::class)->resolve(makeCtx($w, 100000));

    expect($result->applied)->toHaveCount(1)
        ->and($result->totalDiscount)->toBe(10000.0);
});

it('RESOLVER: promo periode lewat → NOT applicable', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => 'Expired',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 10,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(1),
        'is_active' => true,
    ]);

    $result = app(PromoResolver::class)->resolve(makeCtx($w, 100000));
    expect($result->applied)->toBe([]);
});

it('RESOLVER: hari spesifik mismatch → NOT applicable', function () {
    $w = Warehouse::firstOrFail();
    $todaySlug = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'][now()->dayOfWeekIso - 1];
    // Pakai hari LAIN dari hari ini
    $otherDays = array_filter(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], fn ($d) => $d !== $todaySlug);
    Promo::create([
        'name' => 'Weekend Only',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 20,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'days_of_week' => [reset($otherDays)],
        'is_active' => true,
    ]);

    $result = app(PromoResolver::class)->resolve(makeCtx($w, 100000));
    expect($result->applied)->toBe([]);
});

it('RESOLVER: jam spesifik di luar happy hour → NOT applicable', function () {
    $w = Warehouse::firstOrFail();
    // Happy hour 1 jam ke belakang (sudah lewat)
    Promo::create([
        'name' => 'Happy Hour Past',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 15,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'time_start' => now()->subHours(3)->format('H:i:s'),
        'time_end' => now()->subHours(2)->format('H:i:s'),
        'is_active' => true,
    ]);

    $result = app(PromoResolver::class)->resolve(makeCtx($w, 100000));
    expect($result->applied)->toBe([]);
});

it('RESOLVER: cabang spesifik match → applicable, mismatch → NOT', function () {
    $w = Warehouse::firstOrFail();
    $other = Warehouse::firstOrCreate(
        ['code' => 'WH-PROMO-X'],
        ['name' => 'Test WH X', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );

    $promo = Promo::create([
        'name' => 'Cabang Specific',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'nominal',
        'discount_value' => 5000,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    $promo->warehouses()->attach($other->id);

    // ctx pakai warehouse $w (BUKAN $other) → mismatch
    $resultMismatch = app(PromoResolver::class)->resolve(makeCtx($w, 100000));
    expect($resultMismatch->applied)->toBe([]);

    // ctx pakai $other → match
    $resultMatch = app(PromoResolver::class)->resolve(makeCtx($other, 100000));
    expect($resultMatch->applied)->toHaveCount(1)
        ->and($resultMatch->totalDiscount)->toBe(5000.0);
});

it('RESOLVER: min_purchase tidak terpenuhi → NOT applicable', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => 'Min 100rb',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 10,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'min_purchase' => 100000,
        'is_active' => true,
    ]);

    // subtotal 50rb < 100rb
    $result = app(PromoResolver::class)->resolve(makeCtx($w, 50000));
    expect($result->applied)->toBe([]);
});

it('RESOLVER: min_qty tidak terpenuhi → NOT applicable', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => 'Min 10 pcs',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 10,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'min_qty' => 10,
        'is_active' => true,
    ]);

    // totalQty 5 < 10
    $result = app(PromoResolver::class)->resolve(makeCtx($w, 100000, totalQty: 5));
    expect($result->applied)->toBe([]);
});

it('RESOLVER: kuota habis → NOT applicable (filter di SQL level)', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => 'Exhausted',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 10,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'quota_total' => 5,
        'quota_used' => 5,
        'is_active' => true,
    ]);

    $result = app(PromoResolver::class)->resolve(makeCtx($w, 100000));
    expect($result->applied)->toBe([]);
});

it('RESOLVER: is_active=false → NOT applicable', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => 'Inactive',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 10,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => false,
    ]);

    $result = app(PromoResolver::class)->resolve(makeCtx($w, 100000));
    expect($result->applied)->toBe([]);
});

// ─── COMPUTE ────────────────────────────────────────────────────────────

it('COMPUTE: percent 10% dari 100rb = 10rb', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => '10%', 'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent', 'discount_value' => 10,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    expect(app(PromoResolver::class)->resolve(makeCtx($w, 100000))->totalDiscount)->toBe(10000.0);
});

it('COMPUTE: percent dgn cap → ke-cap', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => '10% cap 50k', 'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent', 'discount_value' => 10,
        'max_discount_amount' => 50000,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    // 10% dari 1jt = 100rb, tapi cap 50rb
    expect(app(PromoResolver::class)->resolve(makeCtx($w, 1000000))->totalDiscount)->toBe(50000.0);
});

it('COMPUTE: nominal 25rb → 25rb (subtotal cukup)', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => 'Nominal 25k', 'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'nominal', 'discount_value' => 25000,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    expect(app(PromoResolver::class)->resolve(makeCtx($w, 100000))->totalDiscount)->toBe(25000.0);
});

it('COMPUTE: nominal > subtotal → di-clamp ke subtotal', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => 'Nominal 200k', 'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'nominal', 'discount_value' => 200000,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    // Diskon clamp = subtotal 100rb (jangan lebih besar)
    expect(app(PromoResolver::class)->resolve(makeCtx($w, 100000))->totalDiscount)->toBe(100000.0);
});

it('STACK ALL: 2 promo applicable → total = sum', function () {
    $w = Warehouse::firstOrFail();
    Promo::create([
        'name' => 'A 10%', 'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent', 'discount_value' => 10,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    Promo::create([
        'name' => 'B 5k', 'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'nominal', 'discount_value' => 5000,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    // 10% × 100rb = 10rb + nominal 5rb = 15rb total
    $result = app(PromoResolver::class)->resolve(makeCtx($w, 100000));
    expect($result->applied)->toHaveCount(2)
        ->and($result->totalDiscount)->toBe(15000.0);
});

// ─── JOURNAL INTEGRATION ────────────────────────────────────────────────

it('JOURNAL: postSplitSaleWithPromo → balance debit=credit + COA promo benar', function () {
    $w = Warehouse::firstOrFail();
    $sale = Sale::create([
        'invoice_no' => 'INV-PROMO-JNL-'.uniqid(),
        'date' => now(),
        'warehouse_id' => $w->id,
        'cashier_id' => Auth::id(),
        'subtotal' => 100000, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => 90000,
        'promo_discount_amount' => 10000,
        'payment_status' => 'paid', 'status' => 'completed',
    ]);

    $journal = app(JournalEngine::class)->postSplitSaleWithPromo(
        sale: $sale,
        retailSubtotal: 100000,
        retailDiscount: 0,
        retailCogs: 60000,
        serviceSubtotal: 0,
        serviceCogs: 0,
        tax: 0,
        promoDiscount: 10000,
        promoCoaCode: '4199',
    );

    $entries = $journal->entries()->with('coa')->get();
    $totalDebit = $entries->sum(fn ($e) => (float) $e->debit);
    $totalCredit = $entries->sum(fn ($e) => (float) $e->credit);
    expect($totalDebit)->toBe($totalCredit)
        ->and($totalDebit)->toBe(160000.0); // subtotal 100k + cogs 60k

    // Cash entry = total customer bayar = 90rb
    $cashEntry = $entries->firstWhere(fn ($e) => $e->coa->code === '1101');
    expect((float) $cashEntry->debit)->toBe(90000.0);

    // Diskon promo masuk ke 4199 = 10rb
    $promoEntries = $entries->where(fn ($e) => $e->coa->code === '4199');
    expect($promoEntries->sum(fn ($e) => (float) $e->debit))->toBe(10000.0);
});

it('JOURNAL: postSplitSaleWithPromo dgn COA berbeda (4198) → balance + COA benar', function () {
    $w = Warehouse::firstOrFail();
    // Bikin COA test khusus 4198
    Coa::firstOrCreate(
        ['code' => '4198'],
        ['name' => 'Diskon Promo Test', 'type' => 'revenue', 'normal_balance' => 'debit',
         'parent_id' => Coa::where('code', '4100')->value('id'), 'level' => 2, 'is_active' => true],
    );

    $sale = Sale::create([
        'invoice_no' => 'INV-PROMO-COA-'.uniqid(),
        'date' => now(),
        'warehouse_id' => $w->id,
        'cashier_id' => Auth::id(),
        'subtotal' => 50000, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => 40000,
        'promo_discount_amount' => 10000,
        'payment_status' => 'paid', 'status' => 'completed',
    ]);

    $journal = app(JournalEngine::class)->postSplitSaleWithPromo(
        sale: $sale,
        retailSubtotal: 50000, retailDiscount: 0, retailCogs: 0,
        serviceSubtotal: 0, serviceCogs: 0, tax: 0,
        promoDiscount: 10000, promoCoaCode: '4198',
    );

    $entries = $journal->entries()->with('coa')->get();
    $promoEntry = $entries->firstWhere(fn ($e) => $e->coa->code === '4198');
    expect((float) $promoEntry->debit)->toBe(10000.0);

    // Balance
    expect($entries->sum(fn ($e) => (float) $e->debit))
        ->toBe($entries->sum(fn ($e) => (float) $e->credit));
});

it('JOURNAL: HPP tidak terpengaruh meski promo besar', function () {
    $w = Warehouse::firstOrFail();
    $sale = Sale::create([
        'invoice_no' => 'INV-PROMO-HPP-'.uniqid(),
        'date' => now(),
        'warehouse_id' => $w->id,
        'cashier_id' => Auth::id(),
        'subtotal' => 100000, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => 1000,
        'promo_discount_amount' => 99000,
        'payment_status' => 'paid', 'status' => 'completed',
    ]);

    $journal = app(JournalEngine::class)->postSplitSaleWithPromo(
        sale: $sale,
        retailSubtotal: 100000, retailDiscount: 0,
        retailCogs: 60000,  // HPP fixed 60rb meski promo 99rb
        serviceSubtotal: 0, serviceCogs: 0, tax: 0,
        promoDiscount: 99000, promoCoaCode: '4199',
    );

    $entries = $journal->entries()->with('coa')->get();
    $hppEntry = $entries->firstWhere(fn ($e) => $e->coa->code === '5100');
    expect((float) $hppEntry->debit)->toBe(60000.0); // unchanged dari input
});

it('INTEGRATION: sale via CashierController dgn promo → total dipotong, jurnal balance, HPP utuh, applications tercatat', function () {
    $w = Warehouse::firstOrFail();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    \App\Models\Tenant\Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p->id, 'warehouse_id' => $w->id],
        ['qty' => 100, 'cost_avg' => 5000, 'updated_at' => now(), 'created_at' => now()],
    );
    Product::where('id', $p->id)->update(['cost_avg' => 5000]);

    Promo::create([
        'name' => 'Integ 10% off',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 10,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
        'quota_total' => 5,
    ]);

    $controller = app(\App\Http\Controllers\POS\CashierController::class);
    $stock = new \App\Services\StockMovement(new \App\Services\HppCalculator, new \App\Services\UnitConverter);

    $req = \Illuminate\Http\Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $w->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 2, 'price' => 10000]], // subtotal 20rb → diskon 10% = 2rb → total 18rb
        'payment_method' => 'cash',
        'amount_paid' => 20000, // bayar lebih → kembalian = 20rb - 18rb = 2rb
    ]);
    $req->setUserResolver(fn () => Auth::user());

    $controller->store($req, $stock, app(\App\Services\JournalEngine::class),
        new \App\Services\ServiceBundleService($stock, new \App\Services\UnitConverter),
        new \App\Services\VetlySyncService);

    $sale = Sale::latest('id')->firstOrFail();
    expect((float) $sale->subtotal)->toBe(20000.0)
        ->and((float) $sale->promo_discount_amount)->toBe(2000.0)
        ->and((float) $sale->total)->toBe(18000.0)
        ->and((float) $sale->change_amount)->toBe(2000.0); // 20rb - 18rb

    // Promo application tercatat
    $app = \App\Models\Tenant\PromoApplication::where('sale_id', $sale->id)->first();
    expect($app)->not->toBeNull()
        ->and((float) $app->discount_amount)->toBe(2000.0);

    // Quota incremented
    expect(Promo::where('name', 'Integ 10% off')->value('quota_used'))->toBe(1);

    // Journal balance + ada baris di 4199 (COA default krn promo tidak set spesifik)
    $journal = Journal::where('ref_type', \App\Models\Tenant\Sale::class)
        ->where('ref_id', $sale->id)->latest('id')->first();
    $entries = $journal->entries()->with('coa')->get();
    expect($entries->sum(fn ($e) => (float) $e->debit))
        ->toBe($entries->sum(fn ($e) => (float) $e->credit));

    // HPP entry unchanged (2 unit × 5000 = 10rb)
    $hpp = $entries->firstWhere(fn ($e) => $e->coa->code === '5100');
    expect((float) $hpp->debit)->toBe(10000.0);

    // Diskon promo entry di 4199
    $discountEntry = $entries->firstWhere(fn ($e) => $e->coa->code === '4199' && (float) $e->debit > 0);
    expect((float) $discountEntry->debit)->toBe(2000.0);
});

it('INTEGRATION: sale TANPA promo aktif → tetap pakai postSplitSale (path lama), regression', function () {
    $w = Warehouse::firstOrFail();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    \App\Models\Tenant\Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p->id, 'warehouse_id' => $w->id],
        ['qty' => 100, 'cost_avg' => 5000, 'updated_at' => now(), 'created_at' => now()],
    );
    Product::where('id', $p->id)->update(['cost_avg' => 5000]);

    // No promo active

    $controller = app(\App\Http\Controllers\POS\CashierController::class);
    $stock = new \App\Services\StockMovement(new \App\Services\HppCalculator, new \App\Services\UnitConverter);

    $req = \Illuminate\Http\Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $w->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 1, 'price' => 10000]],
        'payment_method' => 'cash',
        'amount_paid' => 10000,
    ]);
    $req->setUserResolver(fn () => Auth::user());

    $controller->store($req, $stock, app(\App\Services\JournalEngine::class),
        new \App\Services\ServiceBundleService($stock, new \App\Services\UnitConverter),
        new \App\Services\VetlySyncService);

    $sale = Sale::latest('id')->firstOrFail();
    expect((float) $sale->promo_discount_amount)->toBe(0.0)
        ->and((float) $sale->total)->toBe(10000.0);

    // Tidak ada promo_application
    expect(\App\Models\Tenant\PromoApplication::where('sale_id', $sale->id)->count())->toBe(0);
});

it('REGRESSION: postSplitSale (tanpa promo) tetap balance + format sama', function () {
    $w = Warehouse::firstOrFail();
    $sale = Sale::create([
        'invoice_no' => 'INV-NO-PROMO-'.uniqid(),
        'date' => now(),
        'warehouse_id' => $w->id,
        'cashier_id' => Auth::id(),
        'subtotal' => 50000, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => 50000,
        'payment_status' => 'paid', 'status' => 'completed',
    ]);

    $journal = app(JournalEngine::class)->postSplitSale(
        sale: $sale,
        retailSubtotal: 50000, retailDiscount: 0, retailCogs: 30000,
        serviceSubtotal: 0, serviceCogs: 0,
    );

    $entries = $journal->entries()->with('coa')->get();
    expect($entries->sum(fn ($e) => (float) $e->debit))
        ->toBe($entries->sum(fn ($e) => (float) $e->credit));
    // Tidak ada baris promo (cuma 4199 dgn 0 → skipped by post helper)
    expect($entries->where(fn ($e) => $e->coa->code === '4199')->count())->toBe(0);
});
