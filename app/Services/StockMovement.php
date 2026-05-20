<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records a stock movement atomically and keeps `inventories`
 * (qty + moving-average cost) consistent.
 *
 * Concurrency:
 *   - DB transaction + lockForUpdate on the inventory row, so two parallel
 *     sales of the last unit cannot both succeed.
 *   - Stock check happens AFTER the lock (and after unit conversion to base).
 *   - Throws InsufficientStockException unless override is granted.
 *
 * Units:
 *   - Caller passes qty in whatever unit the user typed (`unit_id_input`,
 *     `qty_input`). We convert to base via UnitConverter before touching
 *     inventory. The base qty is what lives in `inventories.qty` and what
 *     `stock_movements.qty` reflects. The original input is preserved in
 *     `stock_movements.unit_id_input` / `qty_input` for the kartu stok.
 */
class StockMovement
{
    /** Movement types that increase stock. */
    private const INBOUND = [
        'purchase', 'transfer_in', 'adjustment_plus', 'return_in', 'opname_plus',
    ];

    public const PERM_STOCK_MINUS = 'pos.sell.stock_minus';

    public function __construct(
        private readonly HppCalculator $hpp,
        private readonly UnitConverter $units,
    ) {
    }

    /**
     * Record a stock movement.
     *
     * @param  array{
     *   ref_type?: string,
     *   ref_id?: int,
     *   notes?: string,
     *   unit_id_input?: int,
     *   qty_input?: float|string,
     *   allow_minus?: bool,
     * }  $options
     */
    public function record(
        Product $product,
        Warehouse $warehouse,
        string $type,
        float|string $qty,
        float|string $cost,
        array $options = [],
    ): StockMovementModel {
        $isInbound = in_array($type, self::INBOUND, true);

        // Resolve input unit & convert to base. If caller didn't pass unit_id_input,
        // treat the qty as already-in-base.
        $unitIdInput = $options['unit_id_input'] ?? null;
        $qtyInput = $options['qty_input'] ?? $qty;
        $baseQtyAbs = $unitIdInput
            ? $this->units->toBase($product, abs((float) $qtyInput), $unitIdInput)
            : $this->normalize(abs((float) $qty));

        return DB::transaction(function () use (
            $product, $warehouse, $type, $cost, $options,
            $isInbound, $unitIdInput, $qtyInput, $baseQtyAbs,
        ) {
            /** @var Inventory $inventory */
            $inventory = Inventory::query()
                ->withoutGlobalScopes()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                Inventory::query()->insertOrIgnore([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'qty' => 0,
                    'cost_avg' => $product->cost_avg ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inventory = Inventory::query()
                    ->withoutGlobalScopes()
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // Re-check stock AFTER the lock (race-safe).
            if (! $isInbound) {
                $available = $this->normalize((float) $inventory->qty);
                if (bccomp($baseQtyAbs, $available, UnitConverter::SCALE) === 1) {
                    if (! $this->mayOverride($product, $options)) {
                        throw new InsufficientStockException(
                            productId: $product->id,
                            warehouseId: $warehouse->id,
                            availableBaseQty: $available,
                            requestedBaseQty: $baseQtyAbs,
                        );
                    }
                }
            }

            $signedQty = $isInbound ? (float) $baseQtyAbs : -((float) $baseQtyAbs);

            $recalc = $this->hpp->recalculate($inventory, $signedQty, (float) $cost);

            $inventory->qty = $recalc['qty'];
            $inventory->cost_avg = $recalc['cost_avg'];
            $inventory->last_movement_at = now();
            $inventory->save();

            return StockMovementModel::query()->withoutGlobalScopes()->create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'type' => $type,
                'qty' => $baseQtyAbs,
                'cost' => number_format((float) $cost, 2, '.', ''),
                'balance_qty_after' => $inventory->qty,
                'balance_cost_after' => $inventory->cost_avg,
                'unit_id_input' => $unitIdInput,
                'qty_input' => $unitIdInput
                    ? number_format((float) $qtyInput, UnitConverter::SCALE, '.', '')
                    : null,
                'ref_type' => $options['ref_type'] ?? null,
                'ref_id' => $options['ref_id'] ?? null,
                'notes' => $options['notes'] ?? null,
                'user_id' => Auth::id(),
                'created_at' => now(),
            ]);
        });
    }

    private function mayOverride(Product $product, array $options): bool
    {
        if (! empty($options['allow_minus'])) {
            return true;
        }
        if ((bool) ($product->allow_stock_minus ?? false)) {
            return true;
        }
        $user = Auth::user();

        return $user !== null && $user->can(self::PERM_STOCK_MINUS);
    }

    private function normalize(float $value): string
    {
        return number_format($value, UnitConverter::SCALE, '.', '');
    }
}
