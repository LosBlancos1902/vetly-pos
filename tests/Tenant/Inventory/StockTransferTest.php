<?php

use App\Http\Controllers\Inventory\StockTransferController;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Journal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\StockOpnameItem;
use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\JournalEngine;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Stock Transfer 2-step business test (Batch 3 gudang).
 *
 * Setup: source WHT-TRF-SRC + dest WHT-TRF-DST, baseline inventory consistent
 * (source: 100 @ 5000, dest: 50 @ 6000), reset per test via beforeEach.
 *
 * Cleanup: hapus transfer + jurnal terkait (ref_type transfer_*) sebelum tiap test.
 */

function ownerForTransfer(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForTransfer(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->first()
        ?? TenantUser::create([
            'name' => 'Test Cashier Trf',
            'email' => 'cashier-trf@test.local',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ])->assignRole('cashier');
}

function sourceTrfWh(): Warehouse
{
    return Warehouse::firstOrCreate(
        ['code' => 'WHT-TRF-SRC'],
        ['name' => 'Transfer Source WH', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
}

function destTrfWh(): Warehouse
{
    return Warehouse::firstOrCreate(
        ['code' => 'WHT-TRF-DST'],
        ['name' => 'Transfer Dest WH', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
}

function callTrfController(string $method, array $payload = [], ?StockTransfer $transfer = null)
{
    $controller = app(StockTransferController::class);
    $verb = in_array($method, ['store', 'receive'], true) ? 'POST' : 'GET';
    $req = Request::create('/inventory/transfers', $verb, $payload);
    $req->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($req),
        'store' => $controller->store($req),
        'receive' => $controller->receive($req, $transfer),
        'show' => $controller->show($transfer),
    };
}

function resetTransferState(): void
{
    $src = sourceTrfWh();
    $dst = destTrfWh();

    // Hapus transfer + movements + jurnalnya.
    $transferIds = StockTransfer::whereIn('source_warehouse_id', [$src->id])
        ->orWhereIn('dest_warehouse_id', [$src->id, $dst->id])
        ->pluck('id');

    StockMovementModel::query()->withoutGlobalScopes()
        ->whereIn('warehouse_id', [$src->id, $dst->id])
        ->where('ref_type', StockTransfer::class)->delete();

    $journalIds = Journal::whereIn('ref_type', ['transfer_ship', 'transfer_receive'])->pluck('id');
    JournalEntry::whereIn('journal_id', $journalIds)->delete();
    Journal::whereIn('id', $journalIds)->delete();

    StockTransfer::whereIn('id', $transferIds)->delete(); // cascade items

    // Reset inventories
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    Inventory::query()->withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p1->id, 'warehouse_id' => $src->id],
        ['qty' => 100, 'cost_avg' => 5000, 'updated_at' => now(), 'created_at' => now()],
    );
    Inventory::query()->withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $p1->id, 'warehouse_id' => $dst->id],
        ['qty' => 50, 'cost_avg' => 6000, 'updated_at' => now(), 'created_at' => now()],
    );

    // Cancel SO aktif kalau ada
    StockOpname::whereIn('status', ['draft', 'counting'])
        ->whereIn('warehouse_id', [$src->id, $dst->id])
        ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_reason' => 'cleanup test']);
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForTransfer());
    resetTransferState();
});

afterEach(function () {
    Auth::logout();
});

// ─── #1 SHIP basic: stok asal turun, source.cost_avg PRESERVED ──────────

it('SHIP: stok asal turun (qty_sent), source.cost_avg PRESERVED', function () {
    $src = sourceTrfWh();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => $src->id,
        'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 30]],
    ]);

    $inv = Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', $src->id)->first();

    expect((float) $inv->qty)->toBe(70.0)
        ->and((float) $inv->cost_avg)->toBe(5000.0); // PRESERVED OUT semantics
});

// ─── #2 SHIP jurnal balance ────────────────────────────────────────────

it('SHIP: jurnal D 1203 BDP / C 1201 = TOTAL_SENT, balance', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id,
        'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 30]],
    ]);

    // 30 × 5000 = 150_000
    $journal = Journal::where('ref_type', 'transfer_ship')->latest('id')->first();
    expect($journal)->not->toBeNull();

    $entries = $journal->entries()->with('coa:id,code')->get();
    $debit1203 = (float) $entries->where('coa.code', '1203')->sum('debit');
    $credit1201 = (float) $entries->where('coa.code', '1201')->sum('credit');

    expect($debit1203)->toBe(150_000.0)
        ->and($credit1201)->toBe(150_000.0)
        ->and((float) $entries->sum('debit'))->toBe((float) $entries->sum('credit'));
});

// ─── #3 BDP saldo naik setelah ship ────────────────────────────────────

it('SHIP: BDP saldo naik = TOTAL_SENT setelah ship', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    $coa1203 = Coa::where('code', '1203')->firstOrFail();
    $before = (float) JournalEntry::where('coa_id', $coa1203->id)->sum('debit')
        - (float) JournalEntry::where('coa_id', $coa1203->id)->sum('credit');

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id,
        'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 10]],
    ]);

    $after = (float) JournalEntry::where('coa_id', $coa1203->id)->sum('debit')
        - (float) JournalEntry::where('coa_id', $coa1203->id)->sum('credit');

    expect($after - $before)->toBe(50_000.0); // 10 × 5000
});

// ─── #4 RECEIVE FULL: stok tujuan naik, dest.cost_avg recalc moving avg ──

it('RECEIVE FULL: stok tujuan naik + dest.cost_avg recalc moving avg eksak', function () {
    $src = sourceTrfWh();
    $dst = destTrfWh();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => $src->id, 'dest_warehouse_id' => $dst->id,
        'items' => [['product_id' => $p->id, 'qty' => 20]],
    ]);

    $transfer = StockTransfer::latest('id')->first();
    $item = $transfer->items()->first();

    callTrfController('receive', [
        'items' => [['id' => $item->id, 'qty_received' => 20]],
    ], $transfer);

    $destInv = Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', $dst->id)->first();

    // dest before: 50 @ 6000 → 300_000
    // incoming:    20 @ 5000 → 100_000
    // new qty 70, new avg = (300_000 + 100_000) / 70 = 5714.285714... → round 2 = 5714.29
    expect((float) $destInv->qty)->toBe(70.0)
        ->and((float) $destInv->cost_avg)->toBe(5714.29);
});

// ─── #5 RECEIVE FULL jurnal: D 1201 / C 1203 (no 5100 line, no loss) ────

it('RECEIVE FULL: jurnal D 1201 / C 1203 = TOTAL_SENT, no 5100 line (loss=0)', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 20]],
    ]);

    $transfer = StockTransfer::latest('id')->first();
    $item = $transfer->items()->first();

    callTrfController('receive', [
        'items' => [['id' => $item->id, 'qty_received' => 20]],
    ], $transfer);

    $journal = Journal::where('ref_type', 'transfer_receive')->latest('id')->first();
    $entries = $journal->entries()->with('coa:id,code')->get();

    expect((float) $entries->where('coa.code', '1201')->sum('debit'))->toBe(100_000.0)
        ->and((float) $entries->where('coa.code', '1203')->sum('credit'))->toBe(100_000.0)
        ->and($entries->where('coa.code', '5100')->count())->toBe(0) // zero-line skipped
        ->and((float) $entries->sum('debit'))->toBe((float) $entries->sum('credit'));
});

// ─── #6 BDP NET 0 setelah full receive ────────────────────────────────

it('RECEIVE FULL: BDP net 0 setelah selesai', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 15]],
    ]);
    $transfer = StockTransfer::latest('id')->first();
    callTrfController('receive', [
        'items' => [['id' => $transfer->items->first()->id, 'qty_received' => 15]],
    ], $transfer);

    // BDP debit dari ship - BDP credit dari receive untuk transfer ini = 0
    $coa1203 = Coa::where('code', '1203')->firstOrFail();
    $shipJ = $transfer->fresh()->journal_ship_id;
    $recvJ = $transfer->fresh()->journal_receive_id;

    $bdpFromShip = (float) JournalEntry::where('coa_id', $coa1203->id)
        ->where('journal_id', $shipJ)->sum('debit');
    $bdpFromRecv = (float) JournalEntry::where('coa_id', $coa1203->id)
        ->where('journal_id', $recvJ)->sum('credit');

    expect($bdpFromShip - $bdpFromRecv)->toBe(0.0)
        ->and($bdpFromShip)->toBe(75_000.0); // 15 × 5000
});

// ─── #7 RECEIVE PARTIAL: stok hanya qty_received, dest.cost_avg pakai qty_received ──

it('RECEIVE PARTIAL: stok tujuan naik HANYA qty_received, dest.cost_avg pakai qty_received', function () {
    $dst = destTrfWh();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => $dst->id,
        'items' => [['product_id' => $p->id, 'qty' => 20]],
    ]);
    $transfer = StockTransfer::latest('id')->first();

    // Kirim 20, terima 15 (selisih 5)
    callTrfController('receive', [
        'items' => [['id' => $transfer->items->first()->id, 'qty_received' => 15]],
    ], $transfer);

    $destInv = Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', $dst->id)->first();

    // dest before: 50 @ 6000 → 300_000
    // incoming:    15 @ 5000 →  75_000
    // new qty 65, new avg = (300_000 + 75_000) / 65 = 5769.2307... → round = 5769.23
    expect((float) $destInv->qty)->toBe(65.0)
        ->and((float) $destInv->cost_avg)->toBe(5769.23);
});

// ─── #8 RECEIVE PARTIAL jurnal: D 1201 + D 5100 + C 1203, balance ──

it('RECEIVE PARTIAL: jurnal D 1201 (received) + D 5100 (loss) + C 1203 (sum), balance', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 20]],
    ]);
    $transfer = StockTransfer::latest('id')->first();
    callTrfController('receive', [
        'items' => [['id' => $transfer->items->first()->id, 'qty_received' => 15]],
    ], $transfer);

    $journal = Journal::where('ref_type', 'transfer_receive')->latest('id')->first();
    $entries = $journal->entries()->with('coa:id,code')->get();

    // received = 15 × 5000 = 75_000
    // loss     =  5 × 5000 = 25_000
    // total    = 100_000 (= ship)
    expect((float) $entries->where('coa.code', '1201')->sum('debit'))->toBe(75_000.0)
        ->and((float) $entries->where('coa.code', '5100')->sum('debit'))->toBe(25_000.0)
        ->and((float) $entries->where('coa.code', '1203')->sum('credit'))->toBe(100_000.0)
        ->and((float) $entries->sum('debit'))->toBe((float) $entries->sum('credit'));
});

// ─── #9 PARTIAL: BDP net 0 + konservasi nilai total ──────────────────────

it('RECEIVE PARTIAL: BDP net 0 + konservasi nilai (received + loss = ship)', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 20]],
    ]);
    $transfer = StockTransfer::latest('id')->first();
    callTrfController('receive', [
        'items' => [['id' => $transfer->items->first()->id, 'qty_received' => 15]],
    ], $transfer);

    $transfer->refresh();
    $coa1203 = Coa::where('code', '1203')->firstOrFail();
    $shipDebit = (float) JournalEntry::where('coa_id', $coa1203->id)
        ->where('journal_id', $transfer->journal_ship_id)->sum('debit');
    $recvCredit = (float) JournalEntry::where('coa_id', $coa1203->id)
        ->where('journal_id', $transfer->journal_receive_id)->sum('credit');

    expect($shipDebit)->toBe($recvCredit) // BDP net 0
        ->and($shipDebit)->toBe(100_000.0); // 20 × 5000
});

// ─── #10 Guard: same warehouse ────────────────────────────────────────

it('GUARD: source = dest → 422', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    expect(fn () => callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id,
        'dest_warehouse_id' => sourceTrfWh()->id, // same
        'items' => [['product_id' => $p->id, 'qty' => 5]],
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ─── #11 Guard: over-transfer ────────────────────────────────────────

it('GUARD: qty_sent > source.qty → InsufficientStockException + atomic rollback', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $transferCountBefore = StockTransfer::count();
    $journalCountBefore = Journal::where('ref_type', 'transfer_ship')->count();

    expect(fn () => callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id,
        'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 999]], // > 100
    ]))->toThrow(\App\Exceptions\InsufficientStockException::class);

    // Atomic: no transfer row, no journal
    expect(StockTransfer::count())->toBe($transferCountBefore)
        ->and(Journal::where('ref_type', 'transfer_ship')->count())->toBe($journalCountBefore);

    // Source untouched
    $srcQty = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $p->id)
        ->where('warehouse_id', sourceTrfWh()->id)->value('qty');
    expect($srcQty)->toBe(100.0);
});

// ─── #12 Guard: qty_received > qty_sent ────────────────────────────────

it('GUARD: qty_received > qty_sent → 422', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 10]],
    ]);
    $transfer = StockTransfer::latest('id')->first();

    expect(fn () => callTrfController('receive', [
        'items' => [['id' => $transfer->items->first()->id, 'qty_received' => 15]], // > 10
    ], $transfer))->toThrow(HttpException::class);
});

// ─── #13 Guard: receive 2x ──────────────────────────────────────────────

it('GUARD: receive 2x → 422 (status sudah completed)', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 10]],
    ]);
    $transfer = StockTransfer::latest('id')->first();
    $itemId = $transfer->items->first()->id;

    callTrfController('receive', [
        'items' => [['id' => $itemId, 'qty_received' => 10]],
    ], $transfer);

    expect(fn () => callTrfController('receive', [
        'items' => [['id' => $itemId, 'qty_received' => 10]],
    ], $transfer->fresh()))->toThrow(HttpException::class);
});

// ─── #14 Guard: SO freeze source saat ship ──────────────────────────────

it('GUARD: SO freeze source saat ship → 422', function () {
    $src = sourceTrfWh();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    $opname = StockOpname::create([
        'opname_no' => 'SO-TRF-FRZ-'.uniqid('', true),
        'warehouse_id' => $src->id,
        'opname_date' => now()->toDateString(),
        'status' => 'counting',
        'created_by' => ownerForTransfer()->id,
    ]);
    StockOpnameItem::create([
        'opname_id' => $opname->id,
        'product_id' => $p->id,
        'qty_system' => 100,
    ]);

    expect(fn () => callTrfController('store', [
        'source_warehouse_id' => $src->id, 'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 5]],
    ]))->toThrow(HttpException::class);

    StockOpnameItem::where('opname_id', $opname->id)->delete();
    $opname->delete();
});

// ─── #15 ATOMIC: ship jurnal gagal → semua rollback ────────────────────

it('ATOMIC SHIP: jurnal throw → no transfer row, no movement, source untouched', function () {
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    $transferCountBefore = StockTransfer::count();
    $movementCountBefore = StockMovementModel::query()->withoutGlobalScopes()
        ->where('ref_type', StockTransfer::class)->count();
    $qtyBefore = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', sourceTrfWh()->id)->value('qty');

    $mock = Mockery::mock(JournalEngine::class);
    $mock->shouldReceive('postTransferShip')->andThrow(new RuntimeException('forced fail'));
    app()->instance(JournalEngine::class, $mock);

    expect(fn () => callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 10]],
    ]))->toThrow(RuntimeException::class);

    app()->forgetInstance(JournalEngine::class);

    $qtyAfter = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', sourceTrfWh()->id)->value('qty');

    expect($qtyAfter)->toBe($qtyBefore)
        ->and(StockTransfer::count())->toBe($transferCountBefore)
        ->and(StockMovementModel::query()->withoutGlobalScopes()
            ->where('ref_type', StockTransfer::class)->count())->toBe($movementCountBefore);
});

// ─── #16 ATOMIC: receive jurnal gagal → status stay in_transit ─────────

it('ATOMIC RECEIVE: jurnal throw → dest untouched, status tetap in_transit', function () {
    $dst = destTrfWh();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => $dst->id,
        'items' => [['product_id' => $p->id, 'qty' => 10]],
    ]);
    $transfer = StockTransfer::latest('id')->first();

    $destQtyBefore = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', $dst->id)->value('qty');

    $real = app(JournalEngine::class);
    $mock = Mockery::mock(JournalEngine::class);
    // postTransferShip sudah pass dari setup; cuma intercept postTransferReceive
    $mock->shouldReceive('postTransferReceive')->andThrow(new RuntimeException('forced fail'));
    app()->instance(JournalEngine::class, $mock);

    expect(fn () => callTrfController('receive', [
        'items' => [['id' => $transfer->items->first()->id, 'qty_received' => 10]],
    ], $transfer))->toThrow(RuntimeException::class);

    app()->forgetInstance(JournalEngine::class);
    app()->instance(JournalEngine::class, $real);

    $destQtyAfter = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', $dst->id)->value('qty');

    expect($destQtyAfter)->toBe($destQtyBefore)
        ->and($transfer->fresh()->status)->toBe(StockTransfer::STATUS_IN_TRANSIT)
        ->and($transfer->fresh()->journal_receive_id)->toBeNull();
});

// ─── #17 Permission ────────────────────────────────────────────────────

it('PERM: cashier (tanpa inventory.transfer) → AuthorizationException', function () {
    Auth::logout();
    Auth::login(cashierForTransfer());

    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    expect(fn () => callTrfController('store', [
        'source_warehouse_id' => sourceTrfWh()->id, 'dest_warehouse_id' => destTrfWh()->id,
        'items' => [['product_id' => $p->id, 'qty' => 5]],
    ]))->toThrow(AuthorizationException::class);
});

// ─── #18 No double-receive race smoke (sequential check supplements #13) ──

// (covered by #13 via status guard)

// ─── #19 Konservasi nilai total persediaan ─────────────────────────────

it('KONSERVASI NILAI: total asset (1201+1203) − loss = sebelum total', function () {
    $src = sourceTrfWh();
    $dst = destTrfWh();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();

    // Baseline: src 100×5000=500_000, dst 50×6000=300_000 → total inventory 800_000
    $totalBefore = (float) Inventory::query()->withoutGlobalScopes()
        ->whereIn('warehouse_id', [$src->id, $dst->id])
        ->where('product_id', $p->id)
        ->selectRaw('SUM(qty * cost_avg) as v')->value('v');

    callTrfController('store', [
        'source_warehouse_id' => $src->id, 'dest_warehouse_id' => $dst->id,
        'items' => [['product_id' => $p->id, 'qty' => 20]],
    ]);
    $transfer = StockTransfer::latest('id')->first();
    callTrfController('receive', [
        'items' => [['id' => $transfer->items->first()->id, 'qty_received' => 15]],
    ], $transfer);

    // After: src 80×5000=400_000, dst 65×5769.23=375_000 (rounding +/- 0.05)
    // Total inventory 775_000, loss 25_000 → SUM = 800_000 ✓
    $totalAfter = (float) Inventory::query()->withoutGlobalScopes()
        ->whereIn('warehouse_id', [$src->id, $dst->id])
        ->where('product_id', $p->id)
        ->selectRaw('SUM(qty * cost_avg) as v')->value('v');

    $loss = 25_000.0; // 5 × 5000

    // Allow small rounding tolerance pada cost_avg displayed (DECIMAL(15,2)).
    expect(abs($totalAfter + $loss - $totalBefore))->toBeLessThan(1.0);
});
