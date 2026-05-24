<?php

use App\Http\Controllers\POS\CashierController;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\PendingStockMovement;
use App\Models\Tenant\Product;
use App\Models\Tenant\Sale;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\JournalEngine;
use App\Services\ServiceBundleService;
use App\Services\StockMovement as StockMovementService;
use App\Services\UnitConverter;
use App\Services\VetlySyncService;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * F1 — Total auto + Payment Flow.
 *
 * Fokus:
 *   - Anti-tamper: server selalu recompute total dari items, abaikan
 *     'total' field yg dikirim client.
 *   - Validasi cash >= total, transfer/qris = total exact.
 *   - Save flat fields (payment_method, amount_paid, change_amount) di
 *     sales + tetap insert 1 row di sales_payments (source of truth).
 *   - Backward compat: payment payload lama (payments[]) tetap valid.
 *   - REGRESSION: jurnal & stock movement tidak berubah.
 */

function ownerForPayment(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function setStockForPayment(int $productId, int $warehouseId, float $qty, float $costAvg): void
{
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        ['qty' => $qty, 'cost_avg' => $costAvg, 'updated_at' => now(), 'created_at' => now()],
    );
    Product::where('id', $productId)->update(['cost_avg' => $costAvg]);
}

function callCashierStore(Request $request)
{
    $controller = app(CashierController::class);
    $stock = new StockMovementService(new HppCalculator, new UnitConverter);
    $request->setUserResolver(fn () => Auth::user());

    return $controller->store(
        $request, $stock, new JournalEngine,
        new ServiceBundleService($stock, new UnitConverter), new VetlySyncService,
    );
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    PendingStockMovement::query()->delete();
    StockOpname::query()->delete();
    Sale::query()->delete();
    StockMovement::query()->withoutGlobalScopes()->delete();
});

// ─────────────────────────────────────────────────────────────────────────

it('CASH: amount_paid > total → change_amount = paid - total', function () {
    Auth::login(ownerForPayment());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForPayment($p->id, $warehouse->id, 100, 5000);

    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 2, 'price' => 10000]],
        'payment_method' => 'cash',
        'amount_paid' => 25000,
    ]);
    callCashierStore($req);

    $sale = Sale::latest('id')->firstOrFail();
    expect((float) $sale->total)->toBe(20000.0)
        ->and((float) $sale->amount_paid)->toBe(25000.0)
        ->and((float) $sale->change_amount)->toBe(5000.0)
        ->and($sale->payment_method)->toBe('cash');
});

it('CASH: amount_paid exact = total → change = 0', function () {
    Auth::login(ownerForPayment());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForPayment($p->id, $warehouse->id, 100, 5000);

    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 1, 'price' => 10000]],
        'payment_method' => 'cash',
        'amount_paid' => 10000,
    ]);
    callCashierStore($req);

    $sale = Sale::latest('id')->firstOrFail();
    expect((float) $sale->change_amount)->toBe(0.0);
});

it('CASH: amount_paid < total → 422 (cash insufficient)', function () {
    Auth::login(ownerForPayment());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForPayment($p->id, $warehouse->id, 100, 5000);

    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 1, 'price' => 10000]],
        'payment_method' => 'cash',
        'amount_paid' => 8000,
    ]);

    expect(fn () => callCashierStore($req))->toThrow(HttpException::class);
    expect(Sale::count())->toBe(0); // sale tidak tersimpan
});

it('TRANSFER: amount_paid harus exact = total (selisih → 422)', function () {
    Auth::login(ownerForPayment());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForPayment($p->id, $warehouse->id, 100, 5000);

    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 1, 'price' => 10000]],
        'payment_method' => 'transfer',
        'amount_paid' => 11000, // selisih 1000 — tidak boleh
    ]);

    expect(fn () => callCashierStore($req))->toThrow(HttpException::class);
});

it('QRIS: amount_paid exact = total → sukses, change = 0', function () {
    Auth::login(ownerForPayment());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForPayment($p->id, $warehouse->id, 100, 5000);

    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 1, 'price' => 10000]],
        'payment_method' => 'qris',
        'amount_paid' => 10000,
    ]);
    callCashierStore($req);

    $sale = Sale::latest('id')->firstOrFail();
    expect($sale->payment_method)->toBe('qris')
        ->and((float) $sale->change_amount)->toBe(0.0);
});

it('ANTI-TAMPER: client kirim total palsu → server abaikan, hitung ulang dari items', function () {
    Auth::login(ownerForPayment());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForPayment($p->id, $warehouse->id, 100, 5000);

    // Client malicious kirim 'total' = 1 di body. Server harusnya abaikan
    // dan hitung ulang dari sum(qty × price) = 3 × 10000 = 30000.
    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'total' => 1,         // ← FAKE
        'subtotal' => 1,      // ← FAKE
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 3, 'price' => 10000]],
        'payment_method' => 'cash',
        'amount_paid' => 30000,
    ]);
    callCashierStore($req);

    $sale = Sale::latest('id')->firstOrFail();
    expect((float) $sale->total)->toBe(30000.0)        // server-computed
        ->and((float) $sale->subtotal)->toBe(30000.0); // server-computed
});

it('ANTI-TAMPER: client kirim amount_paid bohong (< computed total) → 422 cash insufficient', function () {
    Auth::login(ownerForPayment());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForPayment($p->id, $warehouse->id, 100, 5000);

    // Client mau "trik": kirim total=5000 (palsu) + amount_paid=5000.
    // Server hitung ulang total = 30000, validasi cash 5000 < 30000 → 422.
    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'total' => 5000,      // ← FAKE total
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 3, 'price' => 10000]],
        'payment_method' => 'cash',
        'amount_paid' => 5000, // ← mau match total palsu
    ]);
    expect(fn () => callCashierStore($req))->toThrow(HttpException::class);
});

it('BACKWARD-COMPAT: payment payload lama (payments[]) tetap valid (existing test format)', function () {
    Auth::login(ownerForPayment());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForPayment($p->id, $warehouse->id, 100, 5000);

    // Format LAMA: tidak ada payment_method / amount_paid flat, cuma payments[].
    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 2, 'price' => 10000]],
        'payments' => [['method' => 'cash', 'amount' => 20000]],
    ]);
    callCashierStore($req);

    $sale = Sale::latest('id')->with('payments')->firstOrFail();
    expect($sale->payments)->toHaveCount(1)
        ->and((float) $sale->total)->toBe(20000.0)
        // payment_method denorm di-resolve dari payments[0]
        ->and($sale->payment_method)->toBe('cash');
});

it('REGRESSION: stock movement & journal tetap tercatat (jangan rusak engine HPP)', function () {
    Auth::login(ownerForPayment());
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForPayment($p->id, $warehouse->id, 100, 5000);

    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_id,
            'qty' => 4, 'price' => 12000]],
        'payment_method' => 'cash',
        'amount_paid' => 50000,
    ]);
    callCashierStore($req);

    // Stok turun 4 unit (regression flow normal — unchanged dari pre-F1)
    $inv = Inventory::withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', $warehouse->id)->first();
    expect((float) $inv->qty)->toBe(96.0);

    // Stock movement type=sale tercatat dgn qty + cost yg bener
    $mv = StockMovement::withoutGlobalScopes()
        ->where('product_id', $p->id)->where('type', 'sale')->latest('id')->first();
    expect($mv)->not->toBeNull()
        ->and((float) $mv->qty)->toBe(4.0)
        ->and((float) $mv->cost)->toBe(5000.0); // HPP, bukan price jual

    // sales_payments tetap insert 1 row dgn amount = total (bukan amount_paid)
    $sale = Sale::latest('id')->with('payments')->first();
    expect($sale->payments)->toHaveCount(1)
        ->and((float) $sale->payments->first()->amount)->toBe(48000.0); // sales.total
});
