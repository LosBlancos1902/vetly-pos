<?php

namespace App\Services;

use App\Exceptions\CompoundExecutionException;
use App\Models\Tenant\CompoundRecipe;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Compound (racikan) engine.
 *
 * Costing: sums each component's per-warehouse `cost_avg` × required base qty.
 * Pricing: suggested = (total_cost + racik_fee) × (1 + markup%) / total_yield_base.
 *          Stored on the compound product's `products.price` (caller decides
 *          when to persist — `execute()` reports it via the result array).
 *
 * Execution modes:
 *   - 'to_stock'    → emits `compound_out` per component AND a single
 *                     `compound_in` on the compound product (cost per yield
 *                     unit = total_cost / total_yield_base).
 *   - 'direct_sale' → emits only `compound_out`. Caller posts the revenue
 *                     journal at the sale layer; nothing lands in inventory.
 */
class CompoundService
{
    public const MODE_TO_STOCK = 'to_stock';
    public const MODE_DIRECT_SALE = 'direct_sale';

    public function __construct(
        private readonly StockMovement $stock,
        private readonly UnitConverter $units,
    ) {
    }

    /**
     * Total component cost for ONE batch (1 × yield).
     *
     * @return array{
     *   cost_total: string,
     *   yield_base: string,
     *   cost_per_yield_base: string,
     *   components: array<int, array{product_id:int, base_qty:string, cost_avg:string, line_cost:string}>
     * }
     */
    public function calculateCost(CompoundRecipe $recipe, Warehouse $warehouse): array
    {
        $recipe->loadMissing(['components.component.units', 'product']);

        $costTotal = '0.0000';
        $rows = [];

        foreach ($recipe->components as $comp) {
            $component = $comp->component;
            $baseQty = $this->units->toBase($component, (float) $comp->qty, $comp->unit_id);

            // Per-warehouse moving-avg cost.
            $costAvg = (string) (Inventory::query()->withoutGlobalScopes()
                ->where('product_id', $component->id)
                ->where('warehouse_id', $warehouse->id)
                ->value('cost_avg') ?? '0');
            $costAvg = $this->normalize($costAvg);

            $lineCost = bcmul($baseQty, $costAvg, UnitConverter::SCALE);
            $costTotal = bcadd($costTotal, $lineCost, UnitConverter::SCALE);

            $rows[] = [
                'product_id' => $component->id,
                'base_qty' => $baseQty,
                'cost_avg' => $costAvg,
                'line_cost' => $lineCost,
            ];
        }

        $yieldBase = $this->units->toBase($recipe->product, (float) $recipe->yield_qty, $recipe->yield_unit_id);
        $costPerYield = bccomp($yieldBase, '0', UnitConverter::SCALE) === 1
            ? bcdiv($costTotal, $yieldBase, UnitConverter::SCALE)
            : '0.0000';

        return [
            'cost_total' => $costTotal,
            'yield_base' => $yieldBase,
            'cost_per_yield_base' => $costPerYield,
            'components' => $rows,
        ];
    }

    /**
     * Suggested per-yield-base-unit sale price:
     *   (cost_total + racik_fee) × (1 + markup%) / yield_base
     *
     * Returns a string (use as `products.price`). For 60ml batch with
     * cost=10000, racik=5000, markup=50% → 22500/60 = "375.00".
     */
    public function suggestPrice(CompoundRecipe $recipe, Warehouse $warehouse): string
    {
        $costing = $this->calculateCost($recipe, $warehouse);
        if (bccomp($costing['yield_base'], '0', UnitConverter::SCALE) !== 1) {
            return '0.00';
        }

        $costPlusFee = bcadd($costing['cost_total'], (string) $recipe->racik_fee, 2);
        $markupFactor = bcadd('1', bcdiv((string) $recipe->markup_percent, '100', 4), 4);
        $batchPrice = bcmul($costPlusFee, $markupFactor, 2);

        return bcdiv($batchPrice, $costing['yield_base'], 2);
    }

    /**
     * Execute `qty_batch` batches of the recipe in an atomic transaction.
     *
     * @param  array{notes?: string, ref_type?: string, ref_id?: int}  $options
     * @return array{
     *   cost_total: string,
     *   yield_base_total: string,
     *   suggested_price: string,
     *   final_price: ?string,
     *   movements: array<int, int>
     * }
     */
    public function execute(
        CompoundRecipe $recipe,
        Warehouse $warehouse,
        int $qtyBatch,
        \App\Models\User $user,
        string $mode = self::MODE_TO_STOCK,
        ?string $overridePrice = null,
        array $options = [],
    ): array {
        if ($qtyBatch < 1) {
            throw new \InvalidArgumentException('qty_batch must be at least 1.');
        }
        if (! in_array($mode, [self::MODE_TO_STOCK, self::MODE_DIRECT_SALE], true)) {
            throw new \InvalidArgumentException("Unknown mode '{$mode}'.");
        }

        $recipe->loadMissing(['components.component.units', 'product.units']);
        $batchMultiplier = (string) $qtyBatch;

        return DB::transaction(function () use ($recipe, $warehouse, $qtyBatch, $user, $mode, $overridePrice, $options, $batchMultiplier) {
            // 1. Pre-flight shortage check (advisory; the real lock happens inside record()).
            $shortages = [];
            foreach ($recipe->components as $comp) {
                $component = $comp->component;
                $perBatchBase = $this->units->toBase($component, (float) $comp->qty, $comp->unit_id);
                $totalBase = bcmul($perBatchBase, $batchMultiplier, UnitConverter::SCALE);

                $available = $this->normalize((string) (Inventory::query()->withoutGlobalScopes()
                    ->where('product_id', $component->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->value('qty') ?? '0'));

                if (bccomp($totalBase, $available, UnitConverter::SCALE) === 1) {
                    $shortages[$component->id] = [
                        'product_id' => $component->id,
                        'available' => $available,
                        'required_base' => $totalBase,
                    ];
                }
            }
            if ($shortages !== []) {
                throw new CompoundExecutionException($shortages);
            }

            // 2. Consume each component → compound_out (record() does its own lock + recheck).
            $movements = [];
            $costTotal = '0.0000';
            foreach ($recipe->components as $comp) {
                $component = $comp->component;
                $qtyInputTotal = bcmul((string) $comp->qty, $batchMultiplier, UnitConverter::SCALE);
                $baseQty = $this->units->toBase($component, (float) $qtyInputTotal, $comp->unit_id);

                $costAvg = $this->normalize((string) (Inventory::query()->withoutGlobalScopes()
                    ->where('product_id', $component->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->value('cost_avg') ?? '0'));

                $costTotal = bcadd($costTotal, bcmul($baseQty, $costAvg, UnitConverter::SCALE), UnitConverter::SCALE);

                $mv = $this->stock->record(
                    product: $component,
                    warehouse: $warehouse,
                    type: 'compound_out',
                    qty: $qtyInputTotal,
                    cost: $costAvg,
                    options: [
                        'unit_id_input' => $comp->unit_id,
                        'qty_input' => $qtyInputTotal,
                        'ref_type' => $options['ref_type'] ?? CompoundRecipe::class,
                        'ref_id' => $options['ref_id'] ?? $recipe->id,
                        'notes' => $options['notes'] ?? "Racik {$recipe->name} x{$qtyBatch}",
                    ],
                );
                $movements[] = $mv->id;
            }

            // 3. Mode = to_stock: produce compound_in for the compound product.
            $yieldBaseTotal = bcmul(
                $this->units->toBase($recipe->product, (float) $recipe->yield_qty, $recipe->yield_unit_id),
                $batchMultiplier,
                UnitConverter::SCALE,
            );

            if ($mode === self::MODE_TO_STOCK) {
                $costPerUnit = bccomp($yieldBaseTotal, '0', UnitConverter::SCALE) === 1
                    ? bcdiv($costTotal, $yieldBaseTotal, 2)
                    : '0.00';

                $mv = $this->stock->record(
                    product: $recipe->product,
                    warehouse: $warehouse,
                    type: 'compound_in',
                    qty: $yieldBaseTotal,
                    cost: $costPerUnit,
                    options: [
                        'unit_id_input' => $recipe->yield_unit_id,
                        'qty_input' => bcmul((string) $recipe->yield_qty, $batchMultiplier, UnitConverter::SCALE),
                        'ref_type' => $options['ref_type'] ?? CompoundRecipe::class,
                        'ref_id' => $options['ref_id'] ?? $recipe->id,
                        'notes' => $options['notes'] ?? "Racik hasil {$recipe->name} x{$qtyBatch}",
                    ],
                );
                $movements[] = $mv->id;
            }

            // 4. Pricing report (suggested vs actual override).
            $costPlusFee = bcadd($costTotal, bcmul((string) $recipe->racik_fee, $batchMultiplier, 2), 2);
            $markupFactor = bcadd('1', bcdiv((string) $recipe->markup_percent, '100', 4), 4);
            $batchPrice = bcmul($costPlusFee, $markupFactor, 2);
            $suggested = bccomp($yieldBaseTotal, '0', UnitConverter::SCALE) === 1
                ? bcdiv($batchPrice, $yieldBaseTotal, 2)
                : '0.00';

            return [
                'cost_total' => $costTotal,
                'yield_base_total' => $yieldBaseTotal,
                'suggested_price' => $suggested,
                'final_price' => $overridePrice,
                'movements' => $movements,
            ];
        });
    }

    private function normalize(string $value): string
    {
        return number_format((float) $value, UnitConverter::SCALE, '.', '');
    }
}
