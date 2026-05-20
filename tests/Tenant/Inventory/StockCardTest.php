<?php

use App\Models\Tenant\Inventory;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\StockCard;
use App\Services\StockMovement;
use App\Services\UnitConverter;

beforeEach(function () {
    $this->product = Product::with('units')->where('sku', 'SKU-001')->firstOrFail();
    $this->warehouse = Warehouse::query()->firstOrFail();

    Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->update(['qty' => 100, 'cost_avg' => 95000]);

    StockMovementModel::query()->withoutGlobalScopes()
        ->where('product_id', $this->product->id)
        ->delete();
});

it('records unit_id_input and qty_input alongside the base-unit qty', function () {
    $pcs = MasterUnit::where('code', 'pcs')->firstOrFail();
    $service = new StockMovement(new HppCalculator, new UnitConverter);

    $movement = $service->record(
        product: $this->product,
        warehouse: $this->warehouse,
        type: 'sale',
        qty: 3,
        cost: 0,
        options: ['unit_id_input' => $pcs->id, 'qty_input' => 3],
    );

    expect((string) $movement->qty)->toBe('3.0000')
        ->and((int) $movement->unit_id_input)->toBe($pcs->id)
        ->and((string) $movement->qty_input)->toBe('3.0000')
        ->and((string) $movement->balance_qty_after)->toBe('97.0000');
});

it('returns kartu stok rows in chronological order with running balance', function () {
    $service = new StockMovement(new HppCalculator, new UnitConverter);

    $service->record($this->product, $this->warehouse, 'sale', 2, 0);
    $service->record($this->product, $this->warehouse, 'sale', 5, 0);
    $service->record($this->product, $this->warehouse, 'adjustment_plus', 10, 95000);

    $card = (new StockCard)->for($this->product, $this->warehouse);

    expect($card)->toHaveCount(3);
    expect((string) $card[0]->balance_qty_after)->toBe('98.0000');
    expect((string) $card[1]->balance_qty_after)->toBe('93.0000');
    expect((string) $card[2]->balance_qty_after)->toBe('103.0000');
});
