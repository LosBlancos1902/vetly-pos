<?php

namespace App\Services;

use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records a stock movement atomically and keeps `inventories`
 * (qty + moving-average cost) consistent.
 */
class StockMovement
{
    /** Movement types that increase stock. */
    private const INBOUND = [
        'purchase', 'transfer_in', 'adjustment_plus', 'return_in', 'opname_plus',
    ];

    public function __construct(private readonly HppCalculator $hpp)
    {
    }

    /**
     * @param  array{ref_type?: string, ref_id?: int, notes?: string}  $ref
     */
    public function record(Product $product, Warehouse $warehouse, string $type, float $qty, float $cost, array $ref = []): void
    {
        DB::transaction(function () use ($product, $warehouse, $type, $qty, $cost, $ref) {
            /** @var Inventory $inventory */
            $inventory = Inventory::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                $inventory = new Inventory([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'qty' => 0,
                    'cost_avg' => $product->cost_avg ?? 0,
                ]);
                $inventory->save();
                $inventory = Inventory::whereKey($inventory->id)->lockForUpdate()->first();
            }

            $isInbound = in_array($type, self::INBOUND, true);
            $signedQty = $isInbound ? abs($qty) : -abs($qty);

            $recalc = $this->hpp->recalculate($inventory, $signedQty, $cost);

            $inventory->qty = $recalc['qty'];
            $inventory->cost_avg = $recalc['cost_avg'];
            $inventory->last_movement_at = now();
            $inventory->save();

            StockMovementModel::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'type' => $type,
                'qty' => number_format(abs($qty), 4, '.', ''),
                'cost' => number_format($cost, 2, '.', ''),
                'balance_qty_after' => $inventory->qty,
                'balance_cost_after' => $inventory->cost_avg,
                'ref_type' => $ref['ref_type'] ?? null,
                'ref_id' => $ref['ref_id'] ?? null,
                'notes' => $ref['notes'] ?? null,
                'user_id' => Auth::id(),
                'created_at' => now(),
            ]);
        });
    }
}
