<?php

use App\Http\Controllers\POS\CashierController;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\PendingStockMovement;
use App\Models\Tenant\PriceTier;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductUnit;
use App\Models\Tenant\ProductUnitPrice;
use App\Models\Tenant\Sale;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\JournalEngine;
use App\Services\ServiceBundleService;
use App\Services\StockGuard;
use App\Services\StockMovement as StockMovementService;
use App\Services\UnitConverter;
use App\Services\VetlySyncService;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * F5 regression test: pastikan POS tier+unit selector tidak rusak
 * HPP/opname/freeze yg sudah teruji.
 */

function ownerForCashierMultiTier(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function setStockForMultiTier(int $productId, int $warehouseId, float $qty, float $costAvg): void
{
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        ['qty' => $qty, 'cost_avg' => $costAvg, 'updated_at' => now(), 'created_at' => now()],
    );
    Product::where('id', $productId)->update(['cost_avg' => $costAvg]);
}

function callCashierMultiTier(string $method, ?Request $request = null, $arg2 = null)
{
    $controller = app(CashierController::class);
    $request ??= Request::create('/', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    $stock = new StockMovementService(new HppCalculator, new UnitConverter);

    return match ($method) {
        'index' => $controller->index(),
        'scan' => $controller->scan($arg2, $request, new StockGuard($stock)),
        'search' => $controller->search($request),
        'store' => $controller->store(
            $request, $stock, new JournalEngine,
            new ServiceBundleService($stock, new UnitConverter), new VetlySyncService,
        ),
    };
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Cache::driver('array')->forget('price_tier:default_id');
    PendingStockMovement::query()->delete();
    StockOpname::query()->delete();
    Sale::query()->delete();
    StockMovement::query()->withoutGlobalScopes()->delete();

    // Reset tiers ke state baseline (Eceran default-only).
    PriceTier::query()->update(['is_default' => false]);
    PriceTier::updateOrCreate(['name' => 'Eceran'],
        ['sort_order' => 1, 'is_default' => true, 'is_active' => true]);
    PriceTier::where('name', '!=', 'Eceran')->delete();
});

afterEach(function () {
    PendingStockMovement::query()->delete();
    StockOpname::query()->delete();
    PriceTier::query()->update(['is_default' => false]);
    PriceTier::updateOrCreate(['name' => 'Eceran'],
        ['sort_order' => 1, 'is_default' => true, 'is_active' => true]);
    PriceTier::where('name', '!=', 'Eceran')->delete();
    Cache::driver('array')->forget('price_tier:default_id');
});

// ─────────────────────────────────────────────────────────────────────────

it('search response include units[] dgn prices map per tier (lengkap, sudah fallback)', function () {
    Auth::login(ownerForCashierMultiTier());
    $defaultId = PriceTier::where('is_default', true)->value('id');
    $grosir = PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);

    // Set explicit tier prices untuk SKU-001 (base unit) di tier Eceran only.
    // Grosir SENGAJA tidak diset → harus fallback ke Eceran di response.
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $baseUnit = $p->units()->where('level', 1)->firstOrFail();
    ProductUnitPrice::updateOrCreate(
        ['product_unit_id' => $baseUnit->id, 'price_tier_id' => $defaultId],
        ['price' => 12345],
    );
    ProductUnitPrice::where('product_unit_id', $baseUnit->id)
        ->where('price_tier_id', $grosir->id)->delete();

    $warehouse = Warehouse::firstOrFail();
    setStockForMultiTier($p->id, $warehouse->id, 100, 5000);

    $req = Request::create('/pos/products/search', 'GET', [
        'q' => 'SKU-001',
        'warehouse_id' => $warehouse->id,
    ]);
    $response = callCashierMultiTier('search', $req);
    $body = json_decode($response->getContent(), true);

    $first = collect($body['results'])->firstWhere('sku', 'SKU-001');
    expect($first)->not->toBeNull()
        ->and($first['units'])->toBeArray()
        ->and(count($first['units']))->toBeGreaterThan(0);

    $base = collect($first['units'])->firstWhere('level', 1);
    expect($base['prices'])->toHaveKey((string) $defaultId)
        ->and($base['prices'])->toHaveKey((string) $grosir->id)
        // Grosir kosong → fallback ke Eceran = 12345 (F2 accessor di server).
        ->and((float) $base['prices'][(string) $defaultId])->toBe(12345.0)
        ->and((float) $base['prices'][(string) $grosir->id])->toBe(12345.0);
});

it('store sale dgn price_tier_id → sale.price_tier_id tersimpan (audit trail)', function () {
    Auth::login(ownerForCashierMultiTier());
    $defaultId = PriceTier::where('is_default', true)->value('id');
    $grosir = PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);

    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForMultiTier($p->id, $warehouse->id, 100, 5000);

    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'price_tier_id' => $grosir->id,
        'items' => [[
            'product_id' => $p->id,
            'unit_id' => $p->base_unit_id,
            'qty' => 2,
            'price' => 9500, // harga grosir manual (server trust client)
            'discount_amount' => 0,
        ]],
        'payments' => [['method' => 'cash', 'amount' => 19000]],
    ]);
    callCashierMultiTier('store', $req);

    $sale = Sale::latest('id')->firstOrFail();
    expect($sale->price_tier_id)->toBe($grosir->id)
        ->and((float) $sale->total)->toBe(19000.0);
});

it('REGRESSION: store sale TANPA price_tier_id tetap valid (sale lama / mobile lama)', function () {
    Auth::login(ownerForCashierMultiTier());

    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForMultiTier($p->id, $warehouse->id, 100, 5000);

    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        // price_tier_id SENGAJA tidak dikirim
        'items' => [[
            'product_id' => $p->id,
            'unit_id' => $p->base_unit_id,
            'qty' => 3,
            'price' => 10000,
        ]],
        'payments' => [['method' => 'cash', 'amount' => 30000]],
    ]);
    callCashierMultiTier('store', $req);

    $sale = Sale::latest('id')->firstOrFail();
    expect($sale->price_tier_id)->toBeNull()
        ->and((float) $sale->total)->toBe(30000.0);

    // Stok turun seperti biasa (regression flow normal).
    $inv = Inventory::withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', $warehouse->id)->first();
    expect((float) $inv->qty)->toBe(97.0);
});

it('REGRESSION: HPP/cost_avg TIDAK dipengaruhi tier (tier hanya pengaruh harga jual)', function () {
    Auth::login(ownerForCashierMultiTier());
    $grosir = PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);

    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForMultiTier($p->id, $warehouse->id, 100, 5000); // cost_avg = 5000

    // Jual 2 unit di tier Grosir dgn harga 9500 (lebih rendah dari Eceran).
    $req = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'price_tier_id' => $grosir->id,
        'items' => [[
            'product_id' => $p->id,
            'unit_id' => $p->base_unit_id,
            'qty' => 2,
            'price' => 9500,
        ]],
        'payments' => [['method' => 'cash', 'amount' => 19000]],
    ]);
    callCashierMultiTier('store', $req);

    // cost_avg SAMA (tidak terpengaruh tier). HPP movement = qty × cost_avg.
    $inv = Inventory::withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', $warehouse->id)->first();
    expect((float) $inv->cost_avg)->toBe(5000.0); // unchanged

    $mv = StockMovement::withoutGlobalScopes()
        ->where('product_id', $p->id)->where('type', 'sale')->latest('id')->first();
    expect((float) $mv->cost)->toBe(5000.0)   // HPP pakai cost_avg, bukan tier
        ->and((float) $mv->qty)->toBe(2.0);
});

it('REGRESSION: SO freeze + pending tetap jalan saat tier diset (multi-tier × frozen)', function () {
    Auth::login(ownerForCashierMultiTier());
    $grosir = PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);

    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::firstOrFail();
    setStockForMultiTier($p->id, $warehouse->id, 100, 5000);

    // Bikin SO draft → snapshot produk SKU-001.
    $controller = app(\App\Http\Controllers\Inventory\StockOpnameController::class);
    $opReq = Request::create('/inventory/opnames', 'POST', [
        'warehouse_id' => $warehouse->id,
        'opname_date' => now()->toDateString(),
    ]);
    $opReq->setUserResolver(fn () => Auth::user());
    $controller->store($opReq);
    $opname = StockOpname::latest('id')->firstOrFail();

    // Sale dgn tier Grosir saat produk SEDANG di-snap.
    $saleReq = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'price_tier_id' => $grosir->id,
        'items' => [[
            'product_id' => $p->id,
            'unit_id' => $p->base_unit_id,
            'qty' => 5,
            'price' => 9500,
        ]],
        'payments' => [['method' => 'cash', 'amount' => 47500]],
    ]);
    callCashierMultiTier('store', $saleReq);

    // Stok TIDAK berkurang (frozen) — exactly seperti pre-tier behavior.
    $inv = Inventory::withoutGlobalScopes()
        ->where('product_id', $p->id)->where('warehouse_id', $warehouse->id)->first();
    expect((float) $inv->qty)->toBe(100.0);

    // Pending row dicatat dgn cost_per_base = product.cost_avg (BUKAN harga jual tier).
    $pending = PendingStockMovement::where('opname_id', $opname->id)->latest('id')->first();
    expect($pending)->not->toBeNull()
        ->and((float) $pending->qty_base)->toBe(5.0)
        ->and((float) $pending->cost_per_base)->toBe(5000.0); // HPP, bukan 9500

    // Sale tetap tercatat dgn tier audit.
    $sale = Sale::latest('id')->firstOrFail();
    expect($sale->price_tier_id)->toBe($grosir->id)
        ->and((float) $sale->total)->toBe(47500.0);
});
