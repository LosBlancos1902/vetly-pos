<?php

use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Journal;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function callGrController(string $method, ?Request $request = null, ?PurchaseOrder $po = null)
{
    $controller = app(GoodsReceiptController::class);
    $request ??= Request::create('/purchasing/receipts', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($request),
        'create' => $controller->create($request, $po),
        'store' => $controller->store($request),
    };
}

function ownerForGr(): TenantUser
{
    return TenantUser::query()
        ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
        ->firstOrFail();
}

function makeApprovedPo(array $itemSpecs, string $paymentType = 'cash', int $termDays = 0): PurchaseOrder
{
    $supplier = Supplier::firstOrCreate(
        ['code' => 'GR-TEST-SUP'],
        ['name' => 'GR Test Supplier', 'payment_term_days' => 0, 'is_active' => true],
    );
    $warehouse = Warehouse::query()->firstOrFail();

    $subtotal = collect($itemSpecs)
        ->sum(fn ($s) => $s['qty_ordered'] * $s['unit_price']);

    $po = PurchaseOrder::create([
        'po_no' => 'PO-GRTEST-'.uniqid(),
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'payment_type' => $paymentType,
        'payment_term_days' => $termDays,
        'status' => 'approved',
        'subtotal' => $subtotal,
        'total' => $subtotal,
        'created_by' => Auth::id() ?? ownerForGr()->id,
        'approved_by' => Auth::id() ?? ownerForGr()->id,
        'approved_at' => now(),
    ]);

    foreach ($itemSpecs as $s) {
        $po->items()->create([
            'product_id' => $s['product_id'],
            'unit_id' => $s['unit_id'],
            'qty_ordered' => $s['qty_ordered'],
            'qty_received' => 0,
            'unit_price' => $s['unit_price'],
            'subtotal' => $s['qty_ordered'] * $s['unit_price'],
        ]);
    }

    return $po->fresh('items');
}

/**
 * Reset inventory + cost_avg untuk product di warehouse — supaya moving-avg
 * test mulai dari kondisi diketahui.
 */
function resetInventory(int $productId, int $warehouseId, float $qty = 0, float $costAvg = 0): void
{
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        ['qty' => $qty, 'cost_avg' => $costAvg, 'updated_at' => now(), 'created_at' => now()],
    );
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();

    // Bersihkan state akumulatif yang dibuat test sebelumnya (urut FK: AP→GR→PO).
    \App\Models\Tenant\ApPayment::query()->delete();
    \App\Models\Tenant\AccountsPayable::query()->delete();
    GoodsReceipt::query()->delete(); // cascade ke items via FK
    PurchaseOrder::query()->delete();
    StockMovement::query()->withoutGlobalScopes()->where('ref_type', GoodsReceipt::class)->delete();
    Journal::where('ref_type', 'purchase')->delete();
    Supplier::query()->where('code', 'like', 'GR-TEST-%')->delete();
});

it('inventory qty bertambah by base qty saat receive', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id, 0, 0);

    $po = makeApprovedPo([
        ['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 100, 'unit_price' => 5000],
    ]);

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [
            ['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 30],
        ],
    ]);
    callGrController('store', $request);

    $inv = Inventory::withoutGlobalScopes()
        ->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first();
    expect((float) $inv->qty)->toBe(30.0);
});

it('cost_avg dihitung dengan moving-average formula', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id, 10, 100); // start 10@100

    $po = makeApprovedPo([
        ['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 100, 'unit_price' => 150],
    ]);

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [
            ['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 20],
        ],
    ]);
    callGrController('store', $request);

    $inv = Inventory::withoutGlobalScopes()
        ->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first();

    // (10*100 + 20*150) / 30 = 4000 / 30 = 133.33
    expect((float) $inv->qty)->toBe(30.0)
        ->and((float) $inv->cost_avg)->toBe(133.33);
});

it('journal D 1201 / C 1101 untuk cash PO', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id);

    $po = makeApprovedPo(
        [['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 100, 'unit_price' => 5000]],
        paymentType: 'cash',
    );

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 10]],
    ]);
    callGrController('store', $request);

    $gr = GoodsReceipt::latest('id')->with('journal.entries.coa')->first();
    expect($gr->journal)->not->toBeNull();

    $byCoa = $gr->journal->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);

    expect($byCoa)->toHaveKeys(['1201', '1101'])
        ->and($byCoa['1201']['debit'])->toBe(50000.0)
        ->and($byCoa['1201']['credit'])->toBe(0.0)
        ->and($byCoa['1101']['credit'])->toBe(50000.0)
        ->and($byCoa['1101']['debit'])->toBe(0.0);
});

it('journal D 1201 / C 2101 untuk tempo PO', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id);

    $po = makeApprovedPo(
        [['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 100, 'unit_price' => 5000]],
        paymentType: 'tempo',
        termDays: 30,
    );

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 10]],
    ]);
    callGrController('store', $request);

    $gr = GoodsReceipt::latest('id')->with('journal.entries.coa')->first();
    $byCoa = $gr->journal->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);

    expect($byCoa)->toHaveKeys(['1201', '2101'])
        ->and($byCoa['1201']['debit'])->toBe(50000.0)
        ->and($byCoa['2101']['credit'])->toBe(50000.0);
});

it('journal balance: total debit = total credit', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id);

    $po = makeApprovedPo([['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 7, 'unit_price' => 12500]], 'tempo', 14);

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 7]],
    ]);
    callGrController('store', $request);

    $gr = GoodsReceipt::latest('id')->with('journal.entries')->first();
    $debit = $gr->journal->entries->sum(fn ($e) => (float) $e->debit);
    $credit = $gr->journal->entries->sum(fn ($e) => (float) $e->credit);

    expect((float) $gr->total)->toBe(87500.0)
        ->and($debit)->toBe(87500.0)
        ->and($credit)->toBe(87500.0);
});

it('PO status flip ke received saat semua item fully received', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id);

    $po = makeApprovedPo([['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 50, 'unit_price' => 1000]]);

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 50]],
    ]);
    callGrController('store', $request);

    expect($po->refresh()->status)->toBe('received');
});

it('PO tetap approved saat partial receipt', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id);

    $po = makeApprovedPo([['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 50, 'unit_price' => 1000]]);

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 30]],
    ]);
    callGrController('store', $request);

    expect($po->refresh()->status)->toBe('approved')
        ->and((float) $po->items[0]->fresh()->qty_received)->toBe(30.0);
});

it('stock movement record dengan type=purchase + ref_type=GoodsReceipt', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id);

    $po = makeApprovedPo([['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 10, 'unit_price' => 1000]]);

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 10]],
    ]);
    callGrController('store', $request);

    $gr = GoodsReceipt::latest('id')->first();
    $movement = StockMovement::withoutGlobalScopes()
        ->where('ref_type', GoodsReceipt::class)
        ->where('ref_id', $gr->id)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe('purchase')
        ->and((float) $movement->qty)->toBe(10.0)
        ->and((float) $movement->cost)->toBe(1000.0);
});

it('tidak bisa receive PO non-approved', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();

    $po = makeApprovedPo([['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 10, 'unit_price' => 1000]]);
    $po->update(['status' => 'draft']);

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 10]],
    ]);

    expect(fn () => callGrController('store', $request))->toThrow(HttpException::class);
});

it('tidak bisa over-receive (qty melebihi ordered)', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id);

    $po = makeApprovedPo([['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 50, 'unit_price' => 1000]]);

    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 51]],
    ]);

    expect(fn () => callGrController('store', $request))->toThrow(HttpException::class);
});

it('user tanpa purchasing.receive ditolak', function () {
    $manager = TenantUser::firstOrCreate(['email' => 'gr-mgr@vetly.id'], [
        'name' => 'GR Manager',
        'password' => bcrypt('test'),
        'is_active' => true,
    ]);
    $manager->syncRoles(['manager']);

    // Pastikan manager nggak punya receive (P3+P4 default owner-only).
    Role::findByName('manager')->revokePermissionTo('purchasing.receive');

    Auth::login($manager);
    expect(fn () => callGrController('index'))->toThrow(AuthorizationException::class);
});

it('unit conversion: receive di unit non-base → qty di-convert ke base', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    $baseUnit = MasterUnit::find($product->base_unit_id);
    $dusUnit = MasterUnit::where('code', 'dus')->firstOrFail();

    // Tambah product_unit "dus" = 12 pcs. Pakai upsert.
    DB::table('product_units')->updateOrInsert(
        ['product_id' => $product->id, 'unit_id' => $dusUnit->id],
        [
            'level' => 2,
            'conversion_to_base' => 12,
            'is_purchase_unit' => true,
            'is_sale_unit' => false,
            'price' => null,
            'updated_at' => now(),
            'created_at' => now(),
        ],
    );

    resetInventory($product->id, $warehouse->id, 0, 0);

    // PO ordered 5 dus (= 60 pcs base) @ 60000/dus (= 5000/pcs).
    $po = makeApprovedPo([['product_id' => $product->id, 'unit_id' => $dusUnit->id, 'qty_ordered' => 5, 'unit_price' => 60000]]);

    // Terima 5 dus.
    $request = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $dusUnit->id, 'qty_received' => 5]],
    ]);
    callGrController('store', $request);

    $inv = Inventory::withoutGlobalScopes()
        ->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first();

    // 5 dus * 12 pcs/dus = 60 pcs base. cost per base = 60000/12 = 5000.
    expect((float) $inv->qty)->toBe(60.0)
        ->and((float) $inv->cost_avg)->toBe(5000.0);

    // Journal total = 5 * 60000 = 300000.
    $gr = GoodsReceipt::latest('id')->first();
    expect((float) $gr->total)->toBe(300000.0);
});

it('multiple receipts: akumulasi qty + 2 jurnal terpisah + status switch hanya di receipt terakhir', function () {
    Auth::login(ownerForGr());
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();
    resetInventory($product->id, $warehouse->id);

    $po = makeApprovedPo([['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'qty_ordered' => 100, 'unit_price' => 1000]]);

    // Receipt 1: 40
    $req1 = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 40]],
    ]);
    callGrController('store', $req1);

    expect($po->refresh()->status)->toBe('approved')
        ->and((float) $po->items[0]->fresh()->qty_received)->toBe(40.0);

    // Receipt 2: 60
    $req2 = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $po->items[0]->id, 'unit_id' => $product->base_unit_id, 'qty_received' => 60]],
    ]);
    callGrController('store', $req2);

    expect($po->refresh()->status)->toBe('received');

    $inv = Inventory::withoutGlobalScopes()
        ->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first();
    expect((float) $inv->qty)->toBe(100.0);

    $journalsCount = Journal::where('ref_type', 'purchase')->count();
    expect($journalsCount)->toBe(2);

    $grCount = GoodsReceipt::where('po_id', $po->id)->count();
    expect($grCount)->toBe(2);
});
