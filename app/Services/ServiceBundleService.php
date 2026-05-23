<?php

namespace App\Services;

use App\Exceptions\CompoundExecutionException;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\PendingStockMovement;
use App\Models\Tenant\ServiceBundle;
use App\Models\Tenant\ServiceBundleItem;
use App\Models\Tenant\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Service bundle engine.
 *
 * Handles BOTH pure services (no consumed materials — bundle has no items, or
 * all items skipped) and services with consumption (vaksin, sterilisasi).
 *
 * Pricing: `service_fee` on the bundle IS the sale price — already includes
 * margin. The engine does NOT compute a suggested price; it only computes
 * actual cost so the caller can derive margin.
 *
 * Inventory: consumed components emit `service_consumption` movements. The
 * service product itself never receives a movement (services don't track
 * inventory).
 */
class ServiceBundleService
{
    public function __construct(
        private readonly StockMovement $stock,
        private readonly UnitConverter $units,
    ) {
    }

    /**
     * Total cost of the bundle for ONE execution at this warehouse.
     *
     * @param  array<int>|null  $optionalIncluded  Component product ids of optional items to include.
     *                                             Pass null to include all (mandatory + optional).
     * @return array{
     *   cost_total: string,
     *   items: array<int, array{product_id:int, base_qty:string, cost_avg:string, line_cost:string, optional:bool, included:bool}>
     * }
     */
    public function calculateCost(ServiceBundle $bundle, Warehouse $warehouse, ?array $optionalIncluded = null): array
    {
        $bundle->loadMissing('items.component.units');

        $costTotal = '0.0000';
        $rows = [];

        foreach ($bundle->items as $item) {
            $included = $this->isIncluded($item, $optionalIncluded);
            $component = $item->component;

            $baseQty = $this->units->toBase($component, (float) $item->qty, $item->unit_id);
            $costAvg = $this->normalize((string) (Inventory::query()->withoutGlobalScopes()
                ->where('product_id', $component->id)
                ->where('warehouse_id', $warehouse->id)
                ->value('cost_avg') ?? '0'));
            $lineCost = bcmul($baseQty, $costAvg, UnitConverter::SCALE);

            if ($included) {
                $costTotal = bcadd($costTotal, $lineCost, UnitConverter::SCALE);
            }

            $rows[] = [
                'product_id' => $component->id,
                'base_qty' => $baseQty,
                'cost_avg' => $costAvg,
                'line_cost' => $lineCost,
                'optional' => (bool) $item->is_optional,
                'included' => $included,
            ];
        }

        return [
            'cost_total' => $costTotal,
            'items' => $rows,
        ];
    }

    /**
     * Execute the service: consume each included component atomically.
     *
     * @param  array<int>|null  $optionalIncluded  Optional component product ids to include.
     * @param  array{notes?: string, ref_type?: string, ref_id?: int}  $options
     * @return array{
     *   cost_total: string,
     *   service_fee: string,
     *   margin: string,
     *   movements: array<int, int>
     * }
     */
    public function execute(
        ServiceBundle $bundle,
        Warehouse $warehouse,
        \App\Models\User $user,
        ?array $optionalIncluded = null,
        array $options = [],
    ): array {
        $bundle->loadMissing('items.component.units', 'product');

        return DB::transaction(function () use ($bundle, $warehouse, $optionalIncluded, $options) {
            // Pre-flight shortage check across all included items (advisory).
            $shortages = [];
            foreach ($bundle->items as $item) {
                if (! $this->isIncluded($item, $optionalIncluded)) {
                    continue;
                }
                $component = $item->component;
                $baseNeeded = $this->units->toBase($component, (float) $item->qty, $item->unit_id);
                $available = $this->normalize((string) (Inventory::query()->withoutGlobalScopes()
                    ->where('product_id', $component->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->value('qty') ?? '0'));

                if (bccomp($baseNeeded, $available, UnitConverter::SCALE) === 1) {
                    $shortages[$component->id] = [
                        'product_id' => $component->id,
                        'available' => $available,
                        'required_base' => $baseNeeded,
                    ];
                }
            }
            if ($shortages !== []) {
                throw new CompoundExecutionException($shortages, 'Service bundle execution aborted: insufficient components.');
            }

            // Optional frozen-context from caller (Cashier): map [product_id => opname_id].
            // Untuk komponen yang frozen, defer ke pending_stock_movements; sisanya
            // tetap consume via StockMovement::record seperti sebelumnya.
            $frozenContext = $options['frozen_context'] ?? [];
            $saleId = ($options['ref_type'] ?? null) === \App\Models\Tenant\Sale::class
                ? ($options['ref_id'] ?? null)
                : null;
            $saleItemId = $options['sale_item_id'] ?? null;

            $movements = [];
            $costTotal = '0.0000';
            foreach ($bundle->items as $item) {
                if (! $this->isIncluded($item, $optionalIncluded)) {
                    continue;
                }
                $component = $item->component;
                $baseQty = $this->units->toBase($component, (float) $item->qty, $item->unit_id);
                $costAvg = $this->normalize((string) (Inventory::query()->withoutGlobalScopes()
                    ->where('product_id', $component->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->value('cost_avg') ?? '0'));

                $costTotal = bcadd($costTotal, bcmul($baseQty, $costAvg, UnitConverter::SCALE), UnitConverter::SCALE);

                // Komponen frozen + dipanggil dari konteks sale → defer ke pending.
                if (isset($frozenContext[$component->id]) && $saleId !== null) {
                    PendingStockMovement::create([
                        'opname_id' => $frozenContext[$component->id],
                        'sale_id' => $saleId,
                        'sale_item_id' => $saleItemId,
                        'product_id' => $component->id,
                        'warehouse_id' => $warehouse->id,
                        'type' => 'service_consumption',
                        'qty_base' => $baseQty,
                        'cost_per_base' => $costAvg,
                        'notes' => $options['notes'] ?? "Tindakan {$bundle->name} (pending)",
                        'created_at' => now(),
                    ]);
                    continue;
                }

                $mv = $this->stock->record(
                    product: $component,
                    warehouse: $warehouse,
                    type: 'service_consumption',
                    qty: $item->qty,
                    cost: $costAvg,
                    options: [
                        'unit_id_input' => $item->unit_id,
                        'qty_input' => $item->qty,
                        'ref_type' => $options['ref_type'] ?? ServiceBundle::class,
                        'ref_id' => $options['ref_id'] ?? $bundle->id,
                        'notes' => $options['notes'] ?? "Tindakan {$bundle->name}",
                    ],
                );
                $movements[] = $mv->id;
            }

            $serviceFee = $this->normalize((string) $bundle->service_fee);
            $margin = bcsub($serviceFee, $costTotal, 2);

            return [
                'cost_total' => $costTotal,
                'service_fee' => $serviceFee,
                'margin' => $margin,
                'movements' => $movements,
            ];
        });
    }

    private function isIncluded(ServiceBundleItem $item, ?array $optionalIncluded): bool
    {
        if (! $item->is_optional) {
            return true;
        }
        // Optional default: include only if explicitly listed.
        return $optionalIncluded !== null && in_array((int) $item->component_product_id, $optionalIncluded, true);
    }

    private function normalize(string $value): string
    {
        return number_format((float) $value, UnitConverter::SCALE, '.', '');
    }
}
