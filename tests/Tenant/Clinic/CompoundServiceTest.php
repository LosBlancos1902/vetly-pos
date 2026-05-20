<?php

use App\Exceptions\CompoundExecutionException;
use App\Models\Tenant\CompoundRecipe;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\CompoundService;
use App\Services\HppCalculator;
use App\Services\StockMovement;
use App\Services\UnitConverter;
use Illuminate\Support\Facades\Auth;

function resetClinicState(): array
{
    $warehouse = Warehouse::query()->firstOrFail();
    $recipe = CompoundRecipe::with('components')->firstOrFail();

    // Reset raw material stock + cost.
    $rawStocks = [
        'RAW-AMOX' => ['qty' => 50000, 'cost' => 10],
        'RAW-AQUA' => ['qty' => 2000, 'cost' => 50],
        'RAW-BOTOL60' => ['qty' => 50, 'cost' => 1500],
        'RAW-VAKRAB' => ['qty' => 30, 'cost' => 75000],
        'RAW-SPUIT3' => ['qty' => 100, 'cost' => 2500],
        'RAW-KAPAS' => ['qty' => 5, 'cost' => 35000],
        'CPD-AMOXSIR' => ['qty' => 0, 'cost' => 0],
    ];
    foreach ($rawStocks as $sku => $state) {
        $pid = Product::where('sku', $sku)->value('id');
        Inventory::query()->withoutGlobalScopes()
            ->where('product_id', $pid)
            ->where('warehouse_id', $warehouse->id)
            ->update(['qty' => $state['qty'], 'cost_avg' => $state['cost']]);
    }

    StockMovementModel::query()->withoutGlobalScopes()->delete();

    Auth::login(TenantUser::query()->first());

    return [$recipe, $warehouse];
}

function makeCompoundService(): CompoundService
{
    return new CompoundService(
        new StockMovement(new HppCalculator, new UnitConverter),
        new UnitConverter,
    );
}

it('calculates cost: 1500mg amoxicillin + 60ml aquadest + 1 botol = 19500', function () {
    [$recipe, $warehouse] = resetClinicState();

    $costing = makeCompoundService()->calculateCost($recipe, $warehouse);

    // 1500 × 10 + 60 × 50 + 1 × 1500 = 15000 + 3000 + 1500 = 19500
    expect((float) $costing['cost_total'])->toBe(19500.0)
        ->and((float) $costing['yield_base'])->toBe(60.0)
        ->and((float) $costing['cost_per_yield_base'])->toBe(325.0);
});

it('suggests price: (cost+racik) × (1+markup) / yield', function () {
    [$recipe, $warehouse] = resetClinicState();

    // (19500 + 5000) × 1.5 / 60 = 36750 / 60 = 612.50
    expect((string) makeCompoundService()->suggestPrice($recipe, $warehouse))->toBe('612.50');
});

it('execute to_stock: consumes raw + produces compound_in with cost-per-yield', function () {
    [$recipe, $warehouse] = resetClinicState();
    $svc = makeCompoundService();

    $result = $svc->execute($recipe, $warehouse, qtyBatch: 1, user: Auth::user(), mode: CompoundService::MODE_TO_STOCK);

    // Cost per yield base = 19500 / 60 = 325.00
    expect((float) $result['cost_total'])->toBe(19500.0)
        ->and((float) $result['yield_base_total'])->toBe(60.0)
        ->and((string) $result['suggested_price'])->toBe('612.50');

    // Stock berkurang.
    $amoxQty = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', Product::where('sku', 'RAW-AMOX')->value('id'))
        ->where('warehouse_id', $warehouse->id)->value('qty');
    expect($amoxQty)->toBe(50000.0 - 1500.0);

    // Compound product punya stok baru 60ml dengan cost_avg 325.
    $cpdInv = Inventory::query()->withoutGlobalScopes()
        ->where('product_id', Product::where('sku', 'CPD-AMOXSIR')->value('id'))
        ->where('warehouse_id', $warehouse->id)->first();
    expect((float) $cpdInv->qty)->toBe(60.0)
        ->and((float) $cpdInv->cost_avg)->toBe(325.0);

    // 4 movements: 3 compound_out + 1 compound_in.
    expect(StockMovementModel::query()->withoutGlobalScopes()->count())->toBe(4);
});

it('execute direct_sale: consumes raw WITHOUT producing compound_in', function () {
    [$recipe, $warehouse] = resetClinicState();

    makeCompoundService()->execute($recipe, $warehouse, 1, Auth::user(), CompoundService::MODE_DIRECT_SALE);

    $cpdInv = Inventory::query()->withoutGlobalScopes()
        ->where('product_id', Product::where('sku', 'CPD-AMOXSIR')->value('id'))
        ->where('warehouse_id', $warehouse->id)->first();
    expect((float) $cpdInv->qty)->toBe(0.0);

    expect(StockMovementModel::query()->withoutGlobalScopes()->where('type', 'compound_in')->count())->toBe(0)
        ->and(StockMovementModel::query()->withoutGlobalScopes()->where('type', 'compound_out')->count())->toBe(3);
});

it('rolls back atomically when one component is short', function () {
    [$recipe, $warehouse] = resetClinicState();

    // Botol kosong: tinggal 0.
    Inventory::query()->withoutGlobalScopes()
        ->where('product_id', Product::where('sku', 'RAW-BOTOL60')->value('id'))
        ->where('warehouse_id', $warehouse->id)
        ->update(['qty' => 0]);

    expect(fn () => makeCompoundService()->execute($recipe, $warehouse, 1, Auth::user()))
        ->toThrow(CompoundExecutionException::class);

    // Amoxicillin TIDAK boleh berkurang (atomic rollback).
    $amoxQty = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', Product::where('sku', 'RAW-AMOX')->value('id'))
        ->where('warehouse_id', $warehouse->id)->value('qty');
    expect($amoxQty)->toBe(50000.0);

    expect(StockMovementModel::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('execute with qty_batch=2 doubles consumption and yield', function () {
    [$recipe, $warehouse] = resetClinicState();

    $result = makeCompoundService()->execute($recipe, $warehouse, qtyBatch: 2, user: Auth::user());

    expect((float) $result['cost_total'])->toBe(39000.0)     // 2× 19500
        ->and((float) $result['yield_base_total'])->toBe(120.0); // 2× 60ml

    $amoxQty = (float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', Product::where('sku', 'RAW-AMOX')->value('id'))
        ->where('warehouse_id', $warehouse->id)->value('qty');
    expect($amoxQty)->toBe(50000.0 - 3000.0);
});
