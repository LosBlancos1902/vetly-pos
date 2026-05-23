<?php

use App\Http\Controllers\Inventory\StockOpnameController;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Journal;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\StockOpnameItem;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\JournalEngine;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function callOpnameController(string $method, ?Request $request = null, ?StockOpname $opname = null)
{
    $controller = app(StockOpnameController::class);
    $request ??= Request::create('/inventory/opnames', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($request),
        'store' => $controller->store($request),
        'show' => $controller->show($request, $opname),
        'update_items' => $controller->updateItems($request, $opname),
        'complete' => $controller->complete($request, $opname),
        'cancel' => $controller->cancel($request, $opname),
    };
}

function ownerForOpname(): TenantUser
{
    return TenantUser::query()
        ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
        ->firstOrFail();
}

function presetInventory(int $productId, int $warehouseId, float $qty, float $costAvg): void
{
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        ['qty' => $qty, 'cost_avg' => $costAvg, 'updated_at' => now(), 'created_at' => now()],
    );
}

function currentInventory(int $productId, int $warehouseId): ?Inventory
{
    return Inventory::withoutGlobalScopes()
        ->where('product_id', $productId)
        ->where('warehouse_id', $warehouseId)
        ->first();
}

function createOpnameWithSnapshot(int $warehouseId): StockOpname
{
    Auth::login(ownerForOpname());
    $req = Request::create('/inventory/opnames', 'POST', [
        'warehouse_id' => $warehouseId,
        'opname_date' => now()->toDateString(),
    ]);
    callOpnameController('store', $req);

    return StockOpname::latest('id')->firstOrFail();
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();

    StockOpname::query()->delete();
    StockMovement::query()->withoutGlobalScopes()
        ->where('ref_type', StockOpname::class)->delete();
    Journal::where('ref_type', 'adjustment')->delete();
});

it('create opname → snapshot qty_system bener dari inventory saat ini', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail();

    presetInventory($p1->id, $warehouse->id, 50, 1000);
    presetInventory($p2->id, $warehouse->id, 25, 500);

    $opname = createOpnameWithSnapshot($warehouse->id);

    $items = $opname->items()->get()->keyBy('product_id');
    expect($items)->toHaveCount($opname->items()->count())
        ->and((float) $items[$p1->id]->qty_system)->toBe(50.0)
        ->and((float) $items[$p2->id]->qty_system)->toBe(25.0)
        ->and($items[$p1->id]->qty_physical)->toBeNull()
        ->and($items[$p1->id]->qty_diff)->toBeNull();
});

it('updateItems → qty_diff = qty_physical - qty_system exact', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    presetInventory($p1->id, $warehouse->id, 100, 1000);

    $opname = createOpnameWithSnapshot($warehouse->id);
    $item = $opname->items()->where('product_id', $p1->id)->firstOrFail();

    $req = Request::create("/inventory/opnames/{$opname->id}/items", 'PUT', [
        'items' => [['id' => $item->id, 'qty_physical' => 95]],
    ]);
    callOpnameController('update_items', $req, $opname);

    $item->refresh();
    expect((float) $item->qty_physical)->toBe(95.0)
        ->and((float) $item->qty_diff)->toBe(-5.0)
        ->and($opname->refresh()->status)->toBe('counting'); // auto-transition
});

it('complete dengan selisih PLUS → qty naik + adjustment_plus + jurnal D 1201/C 5100 exact', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    presetInventory($p1->id, $warehouse->id, 100, 2000); // cost_avg 2000

    $opname = createOpnameWithSnapshot($warehouse->id);
    $item = $opname->items()->where('product_id', $p1->id)->firstOrFail();

    // Fisik = 110 → diff = +10, value = 10 * 2000 = 20000.
    callOpnameController('update_items', Request::create('', 'PUT', [
        'items' => [['id' => $item->id, 'qty_physical' => 110]],
    ]), $opname);
    callOpnameController('complete', Request::create('', 'POST'), $opname);

    $inv = currentInventory($p1->id, $warehouse->id);
    expect((float) $inv->qty)->toBe(110.0)
        ->and((float) $inv->cost_avg)->toBe(2000.0); // tetap

    $journal = Journal::where('ref_type', 'adjustment')->latest('id')->with('entries.coa')->first();
    $byCoa = $journal->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);
    expect($byCoa)->toHaveKeys(['1201', '5100'])
        ->and($byCoa['1201']['debit'])->toBe(20000.0)
        ->and($byCoa['5100']['credit'])->toBe(20000.0);

    $movement = StockMovement::withoutGlobalScopes()
        ->where('ref_type', StockOpname::class)
        ->where('ref_id', $opname->id)
        ->first();
    expect($movement->type)->toBe('adjustment_plus')
        ->and((float) $movement->qty)->toBe(10.0);
});

it('complete dengan selisih MINUS → qty turun + adjustment_minus + jurnal D 5100/C 1201 exact', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    presetInventory($p1->id, $warehouse->id, 100, 3000);

    $opname = createOpnameWithSnapshot($warehouse->id);
    $item = $opname->items()->where('product_id', $p1->id)->firstOrFail();

    // Fisik = 92 → diff = -8, value = 8 * 3000 = 24000.
    callOpnameController('update_items', Request::create('', 'PUT', [
        'items' => [['id' => $item->id, 'qty_physical' => 92]],
    ]), $opname);
    callOpnameController('complete', Request::create('', 'POST'), $opname);

    $inv = currentInventory($p1->id, $warehouse->id);
    expect((float) $inv->qty)->toBe(92.0)
        ->and((float) $inv->cost_avg)->toBe(3000.0); // minus juga preserve avg

    $journal = Journal::where('ref_type', 'adjustment')->latest('id')->with('entries.coa')->first();
    $byCoa = $journal->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);
    expect($byCoa)->toHaveKeys(['5100', '1201'])
        ->and($byCoa['5100']['debit'])->toBe(24000.0)
        ->and($byCoa['1201']['credit'])->toBe(24000.0);

    $movement = StockMovement::withoutGlobalScopes()
        ->where('ref_type', StockOpname::class)
        ->where('ref_id', $opname->id)
        ->first();
    expect($movement->type)->toBe('adjustment_minus')
        ->and((float) $movement->qty)->toBe(8.0);
});

it('item tanpa selisih (diff=0) → no movement no jurnal entry', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    presetInventory($p1->id, $warehouse->id, 100, 1000);

    $opname = createOpnameWithSnapshot($warehouse->id);
    $item = $opname->items()->where('product_id', $p1->id)->firstOrFail();

    callOpnameController('update_items', Request::create('', 'PUT', [
        'items' => [['id' => $item->id, 'qty_physical' => 100]], // sama dengan sistem
    ]), $opname);
    callOpnameController('complete', Request::create('', 'POST'), $opname);

    $inv = currentInventory($p1->id, $warehouse->id);
    expect((float) $inv->qty)->toBe(100.0); // tidak berubah

    $movementCount = StockMovement::withoutGlobalScopes()
        ->where('ref_type', StockOpname::class)->where('ref_id', $opname->id)->count();
    expect($movementCount)->toBe(0);

    $journalCount = Journal::where('ref_type', 'adjustment')->count();
    expect($journalCount)->toBe(0);
});

it('complete 2x ditolak (status guard)', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    presetInventory($p1->id, $warehouse->id, 50, 1000);

    $opname = createOpnameWithSnapshot($warehouse->id);
    $item = $opname->items()->where('product_id', $p1->id)->firstOrFail();
    callOpnameController('update_items', Request::create('', 'PUT', [
        'items' => [['id' => $item->id, 'qty_physical' => 55]],
    ]), $opname);
    callOpnameController('complete', Request::create('', 'POST'), $opname);

    expect(fn () => callOpnameController('complete', Request::create('', 'POST'), $opname->fresh()))
        ->toThrow(HttpException::class);
});

it('cancel draft → no efek stok', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    presetInventory($p1->id, $warehouse->id, 100, 1000);

    $opname = createOpnameWithSnapshot($warehouse->id);

    callOpnameController('cancel', Request::create('', 'POST', [
        'cancelled_reason' => 'Salah gudang',
    ]), $opname);

    $opname->refresh();
    expect($opname->status)->toBe('cancelled');

    $inv = currentInventory($p1->id, $warehouse->id);
    expect((float) $inv->qty)->toBe(100.0); // tidak berubah

    $movementCount = StockMovement::withoutGlobalScopes()
        ->where('ref_type', StockOpname::class)->where('ref_id', $opname->id)->count();
    expect($movementCount)->toBe(0);
});

it('user tanpa inventory.opname ditolak (owner-only default)', function () {
    $manager = TenantUser::firstOrCreate(['email' => 'opname-mgr@vetly.id'], [
        'name' => 'Opname Manager',
        'password' => bcrypt('test'),
        'is_active' => true,
    ]);
    $manager->syncRoles(['manager']);
    Role::findByName('manager')->revokePermissionTo('inventory.opname');

    Auth::login($manager);
    expect(fn () => callOpnameController('index'))->toThrow(AuthorizationException::class);
});

it('multi-item mixed plus/minus/nol → semua kekoreksi + 2 jurnal aggregate', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail(); // plus
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail(); // minus
    $p3 = Product::where('sku', 'SKU-003')->firstOrFail(); // nol

    presetInventory($p1->id, $warehouse->id, 100, 1000); // +5 plus: 5*1000=5000
    presetInventory($p2->id, $warehouse->id, 50, 2000);  // -3 minus: 3*2000=6000
    presetInventory($p3->id, $warehouse->id, 30, 500);   // nol

    $opname = createOpnameWithSnapshot($warehouse->id);
    $items = $opname->items()->get()->keyBy('product_id');

    callOpnameController('update_items', Request::create('', 'PUT', [
        'items' => [
            ['id' => $items[$p1->id]->id, 'qty_physical' => 105],
            ['id' => $items[$p2->id]->id, 'qty_physical' => 47],
            ['id' => $items[$p3->id]->id, 'qty_physical' => 30],
        ],
    ]), $opname);
    callOpnameController('complete', Request::create('', 'POST'), $opname);

    expect((float) currentInventory($p1->id, $warehouse->id)->qty)->toBe(105.0)
        ->and((float) currentInventory($p2->id, $warehouse->id)->qty)->toBe(47.0)
        ->and((float) currentInventory($p3->id, $warehouse->id)->qty)->toBe(30.0);

    // 2 jurnal aggregate: 1 plus (5000) + 1 minus (6000).
    $journals = Journal::where('ref_type', 'adjustment')->with('entries.coa')->get();
    expect($journals)->toHaveCount(2);

    $plusJournal = $journals->first(fn ($j) => $j->entries
        ->first(fn ($e) => $e->coa->code === '1201' && (float) $e->debit > 0));
    $minusJournal = $journals->first(fn ($j) => $j->entries
        ->first(fn ($e) => $e->coa->code === '5100' && (float) $e->debit > 0));

    expect($plusJournal)->not->toBeNull()
        ->and($minusJournal)->not->toBeNull();

    $plusByCoa = $plusJournal->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);
    $minusByCoa = $minusJournal->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);

    expect($plusByCoa['1201']['debit'])->toBe(5000.0)
        ->and($plusByCoa['5100']['credit'])->toBe(5000.0)
        ->and($minusByCoa['5100']['debit'])->toBe(6000.0)
        ->and($minusByCoa['1201']['credit'])->toBe(6000.0);

    // p3 (nol) tidak punya stock_movement.
    $p3Movement = StockMovement::withoutGlobalScopes()
        ->where('ref_type', StockOpname::class)->where('ref_id', $opname->id)
        ->where('product_id', $p3->id)->first();
    expect($p3Movement)->toBeNull();
});

it('transaction rollback bersih kalau gagal di tengah (mock JournalEngine throw)', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    presetInventory($p1->id, $warehouse->id, 100, 1000);

    $opname = createOpnameWithSnapshot($warehouse->id);
    $item = $opname->items()->where('product_id', $p1->id)->firstOrFail();
    callOpnameController('update_items', Request::create('', 'PUT', [
        'items' => [['id' => $item->id, 'qty_physical' => 110]],
    ]), $opname);

    // Mock JournalEngine throw saat postAdjustment.
    $mock = \Mockery::mock(JournalEngine::class);
    $mock->shouldReceive('postAdjustment')
        ->andThrow(new \RuntimeException('Simulated journal failure'));
    app()->instance(JournalEngine::class, $mock);

    expect(fn () => callOpnameController('complete', Request::create('', 'POST'), $opname->fresh()))
        ->toThrow(\RuntimeException::class);

    // Inventory tidak berubah (rollback bersih).
    $inv = currentInventory($p1->id, $warehouse->id);
    expect((float) $inv->qty)->toBe(100.0); // tetap 100, bukan 110

    // Tidak ada stock_movement yang nyangkut.
    $movementCount = StockMovement::withoutGlobalScopes()
        ->where('ref_type', StockOpname::class)->where('ref_id', $opname->id)->count();
    expect($movementCount)->toBe(0);

    // Opname status tetap counting (belum jadi completed).
    expect($opname->refresh()->status)->toBe('counting');
});
