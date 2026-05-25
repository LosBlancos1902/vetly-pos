<?php

use App\Http\Controllers\Inventory\AdjustmentController;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Journal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\StockOpnameItem;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\JournalEngine;
use App\Services\StockMovement;
use App\Services\UnitConverter;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Adjustment manual + jurnal posting (Batch 2).
 *
 * Bersih per-test: hapus stock_movements adjustment, journal dgn ref_type='adjustment',
 * reset inventory ke baseline known (qty=100 cost=5000).
 */

function ownerForAdj(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForAdj(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->first()
        ?? TenantUser::create([
            'name' => 'Test Cashier Adj',
            'email' => 'cashier-adj@test.local',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ])->assignRole('cashier');
}

function callAdjController(string $method, array $payload = [])
{
    $controller = app(AdjustmentController::class);
    $req = Request::create('/inventory/adjustments', $method === 'store' ? 'POST' : 'GET', $payload);
    $req->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($req),
        'store' => $controller->store($req),
        'preview' => $controller->preview($req),
    };
}

function adjResetState(int $productId, int $warehouseId, float $qty = 100, float $costAvg = 5000): void
{
    Inventory::query()->withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        ['qty' => $qty, 'cost_avg' => $costAvg, 'updated_at' => now(), 'created_at' => now()],
    );
    Product::where('id', $productId)->update(['cost_avg' => $costAvg]);

    // Bersihkan manual_adjustment movements + jurnalnya (ref_type='adjustment').
    StockMovementModel::query()->withoutGlobalScopes()
        ->where('product_id', $productId)
        ->where('ref_type', 'manual_adjustment')
        ->delete();

    $journalIds = Journal::where('ref_type', 'adjustment')->pluck('id');
    JournalEntry::whereIn('journal_id', $journalIds)->delete();
    Journal::whereIn('id', $journalIds)->delete();

    // Cancel SO aktif kalau ada (warisan test sebelumnya).
    StockOpname::whereIn('status', ['draft', 'counting'])
        ->where('warehouse_id', $warehouseId)
        ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_reason' => 'cleanup test']);
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForAdj());

    $this->product = Product::where('sku', 'SKU-001')->firstOrFail();
    $this->warehouse = Warehouse::query()->firstOrFail();
    adjResetState($this->product->id, $this->warehouse->id);
});

afterEach(function () {
    Auth::logout();
});

// ─── #1 MINUS posts jurnal balance ──────────────────────────────────────

it('MINUS: post jurnal D 5100 / C 1201, balance, amount = qty × cost_avg', function () {
    callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -3, // minus 3
        'reason' => 'rusak',
        'notes' => 'Test rusak',
    ]);

    // 3 × 5000 = 15000
    $journal = Journal::where('ref_type', 'adjustment')->latest('id')->first();
    expect($journal)->not->toBeNull();

    $entries = $journal->entries()->with('coa:id,code')->get();
    $debit = $entries->where('coa.code', '5100')->sum('debit');
    $credit = $entries->where('coa.code', '1201')->sum('credit');

    expect((float) $debit)->toBe(15000.0)
        ->and((float) $credit)->toBe(15000.0)
        ->and((float) $entries->sum('debit'))->toBe((float) $entries->sum('credit'));
});

// ─── #2 PLUS posts jurnal balance ────────────────────────────────────────

it('PLUS: post jurnal D 1201 / C 5100, balance, amount = qty × cost_avg', function () {
    callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => 4, // plus 4
        'reason' => 'koreksi',
    ]);

    // 4 × 5000 = 20000
    $journal = Journal::where('ref_type', 'adjustment')->latest('id')->first();
    $entries = $journal->entries()->with('coa:id,code')->get();
    $debit = $entries->where('coa.code', '1201')->sum('debit');
    $credit = $entries->where('coa.code', '5100')->sum('credit');

    expect((float) $debit)->toBe(20000.0)
        ->and((float) $credit)->toBe(20000.0);
});

// ─── #3 HPP per-warehouse (bukan Product.cost_avg global) ────────────────

it('amount pakai inventories.cost_avg per-warehouse, BUKAN Product.cost_avg', function () {
    // Bikin warehouse kedua + inventory dgn cost berbeda.
    $w2 = Warehouse::firstOrCreate(
        ['code' => 'WHT-ADJ2'],
        ['name' => 'WH Adj 2', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    Inventory::query()->withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $this->product->id, 'warehouse_id' => $w2->id],
        ['qty' => 100, 'cost_avg' => 7777, 'updated_at' => now(), 'created_at' => now()],
    );
    // Product.cost_avg DIBIARKAN beda (5000), supaya kalau bug, amount akan 5000*qty bukan 7777*qty.
    Product::where('id', $this->product->id)->update(['cost_avg' => 5000]);

    callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $w2->id,
        'qty' => -2,
        'reason' => 'hilang',
    ]);

    // Harus 2 × 7777 = 15554, NOT 2 × 5000 = 10000
    $journal = Journal::where('ref_type', 'adjustment')->latest('id')->first();
    $debit = $journal->entries()->with('coa:id,code')->get()
        ->where('coa.code', '5100')->sum('debit');

    expect((float) $debit)->toBe(15554.0);

    // Cleanup warehouse kedua.
    Inventory::query()->withoutGlobalScopes()
        ->where('warehouse_id', $w2->id)->delete();
    Warehouse::where('id', $w2->id)->delete();
});

// ─── #4 ATOMIC: jurnal gagal → rollback semua ────────────────────────────

it('ATOMIC: jurnal gagal → inventory.qty unchanged, no stock_movement, no journal', function () {
    $qtyBefore = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->value('qty');

    $movementCountBefore = StockMovementModel::query()->withoutGlobalScopes()
        ->where('product_id', $this->product->id)
        ->where('ref_type', 'manual_adjustment')->count();
    $journalCountBefore = Journal::where('ref_type', 'adjustment')->count();

    // Mock JournalEngine throw saat postAdjustment.
    $mock = Mockery::mock(JournalEngine::class);
    $mock->shouldReceive('postAdjustment')->andThrow(new RuntimeException('forced fail'));
    app()->instance(JournalEngine::class, $mock);

    expect(fn () => callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -5,
        'reason' => 'rusak',
    ]))->toThrow(RuntimeException::class);

    // Rebind original supaya test selanjutnya tidak ke-mock.
    app()->forgetInstance(JournalEngine::class);

    $qtyAfter = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->value('qty');

    expect($qtyAfter)->toBe($qtyBefore)
        ->and(StockMovementModel::query()->withoutGlobalScopes()
            ->where('product_id', $this->product->id)
            ->where('ref_type', 'manual_adjustment')->count())->toBe($movementCountBefore)
        ->and(Journal::where('ref_type', 'adjustment')->count())->toBe($journalCountBefore);
});

// ─── #5 reason persisted on stock_movement ──────────────────────────────

it('reason="expired" persisted di stock_movements row', function () {
    callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -1,
        'reason' => 'expired',
    ]);

    $mv = StockMovementModel::query()->withoutGlobalScopes()
        ->where('product_id', $this->product->id)
        ->where('ref_type', 'manual_adjustment')
        ->latest('id')->first();

    expect($mv)->not->toBeNull()
        ->and($mv->reason)->toBe('expired')
        ->and($mv->type)->toBe('adjustment_minus');
});

// ─── #6 reason wajib ─────────────────────────────────────────────────────

it('VALIDASI: reason wajib (kosong → ValidationException)', function () {
    expect(fn () => callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -1,
        // reason missing
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('VALIDASI: reason value invalid ditolak', function () {
    expect(fn () => callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -1,
        'reason' => 'ngacau', // invalid
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ─── #7 SO freeze guard ─────────────────────────────────────────────────

it('GUARD: produk yg sedang opname (SO aktif) → adjust ditolak 422', function () {
    // Bikin SO draft yang include produk ini.
    $opname = StockOpname::create([
        'opname_no' => 'SO-ADJ-FROZEN-'.uniqid('', true),
        'warehouse_id' => $this->warehouse->id,
        'opname_date' => now()->toDateString(),
        'status' => 'counting',
        'created_by' => ownerForAdj()->id,
    ]);
    StockOpnameItem::create([
        'opname_id' => $opname->id,
        'product_id' => $this->product->id,
        'qty_system' => 100,
    ]);

    expect(fn () => callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -1,
        'reason' => 'rusak',
    ]))->toThrow(HttpException::class);

    // Cleanup
    StockOpnameItem::where('opname_id', $opname->id)->delete();
    $opname->delete();
});

// ─── #8 cost_avg = 0 → stok berubah, jurnal di-skip ─────────────────────

it('EDGE: cost_avg=0 → stok ke-update, jurnal di-skip (zero amount)', function () {
    Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->update(['cost_avg' => 0]);

    $journalCountBefore = Journal::where('ref_type', 'adjustment')->count();

    callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -1,
        'reason' => 'koreksi',
    ]);

    // Stok turun, tapi tidak ada jurnal baru.
    $qty = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->value('qty');

    expect($qty)->toBe(99.0)
        ->and(Journal::where('ref_type', 'adjustment')->count())->toBe($journalCountBefore);
});

// ─── #9 Permission inventory.adjustment enforced ────────────────────────

it('PERM: user tanpa inventory.adjustment ditolak (cashier role)', function () {
    Auth::logout();
    Auth::login(cashierForAdj());

    expect(fn () => callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -1,
        'reason' => 'rusak',
    ]))->toThrow(AuthorizationException::class);
});

// ─── #10 Index listing — filter & permission ─────────────────────────────

it('INDEX: filter by warehouse + hanya manual_adjustment (bukan SO-driven)', function () {
    // Bikin 1 manual adjustment
    callAdjController('store', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -2,
        'reason' => 'rusak',
    ]);

    $response = callAdjController('index', ['warehouse_id' => $this->warehouse->id]);
    $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['movements']['data'])->not->toBeEmpty();
    foreach ($props['movements']['data'] as $row) {
        expect(in_array($row['type'], ['adjustment_plus', 'adjustment_minus'], true))->toBeTrue()
            ->and($row['warehouse_id'])->toBe($this->warehouse->id);
    }
});

// ─── #11 Preview endpoint ────────────────────────────────────────────────

it('PREVIEW: return cost_avg, current_qty, amount sesuai inventories', function () {
    $response = callAdjController('preview', [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'qty' => -3,
    ]);

    $data = json_decode($response->getContent(), true);
    expect((float) $data['cost_avg'])->toBe(5000.0)
        ->and((float) $data['current_qty'])->toBe(100.0)
        ->and((float) $data['amount'])->toBe(15000.0)
        ->and($data['is_plus'])->toBeFalse();
});
