<?php

namespace App\Http\Controllers\Pharmacy;

use App\Exceptions\CompoundExecutionException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\CompoundRecipe;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Warehouse;
use App\Services\CompoundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pharmacy compound execution UI.
 *
 * - GET  /pharmacy/compound          → page (recipe + warehouse picker, preview, execute)
 * - GET  /pharmacy/compound/preview  → JSON cost + per-component availability
 * - POST /pharmacy/compound/execute  → call CompoundService::execute
 */
class CompoundController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('pharmacy.compound');

        $recipes = CompoundRecipe::with([
            'product:id,sku,name',
            'yieldUnit:id,code,name',
            'components.component:id,sku,name',
            'components.unit:id,code,name',
        ])->where('is_active', true)->orderBy('name')->get();

        // Show all active warehouses; staff pinned to one warehouse will
        // already be filtered by the global scope on most queries.
        $warehouses = Warehouse::query()->withoutGlobalScopes()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return Inertia::render('Pharmacy/Compound', [
            'recipes' => $recipes,
            'warehouses' => $warehouses,
            'defaultWarehouseId' => $request->user()?->warehouse_id,
        ]);
    }

    public function preview(Request $request, CompoundService $service): JsonResponse
    {
        $this->authorize('pharmacy.compound');

        $data = $request->validate([
            'recipe_id' => ['required', 'integer', 'exists:compound_recipes,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'qty_batch' => ['required', 'integer', 'min:1'],
        ]);

        $recipe = CompoundRecipe::with(['components.component.units', 'product.units', 'yieldUnit'])
            ->findOrFail($data['recipe_id']);
        $warehouse = Warehouse::query()->withoutGlobalScopes()->findOrFail($data['warehouse_id']);

        $costing = $service->calculateCost($recipe, $warehouse);
        $suggested = $service->suggestPrice($recipe, $warehouse);

        // Per-component availability (base unit).
        $batch = $data['qty_batch'];
        $components = [];
        foreach ($costing['components'] as $row) {
            $available = (string) (Inventory::query()->withoutGlobalScopes()
                ->where('product_id', $row['product_id'])
                ->where('warehouse_id', $warehouse->id)
                ->value('qty') ?? '0');

            $requiredBase = bcmul($row['base_qty'], (string) $batch, 4);
            $isShort = bccomp($requiredBase, $available, 4) === 1;

            $components[] = [
                'product_id' => $row['product_id'],
                'cost_avg' => $row['cost_avg'],
                'required_base' => $requiredBase,
                'available_base' => $available,
                'is_short' => $isShort,
                'line_cost_per_batch' => $row['line_cost'],
            ];
        }

        return response()->json([
            'recipe' => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'yield_qty' => $recipe->yield_qty,
                'yield_unit' => $recipe->yieldUnit?->code,
                'racik_fee' => $recipe->racik_fee,
                'markup_percent' => $recipe->markup_percent,
            ],
            'batch' => $batch,
            'cost_total_per_batch' => $costing['cost_total'],
            'cost_total' => bcmul($costing['cost_total'], (string) $batch, 4),
            'yield_base_total' => bcmul($costing['yield_base'], (string) $batch, 4),
            'cost_per_yield_base' => $costing['cost_per_yield_base'],
            'suggested_price' => $suggested,
            'components' => $components,
            'has_shortage' => collect($components)->contains('is_short', true),
        ]);
    }

    public function execute(Request $request, CompoundService $service): RedirectResponse
    {
        $this->authorize('pharmacy.compound');

        $data = $request->validate([
            'recipe_id' => ['required', 'integer', 'exists:compound_recipes,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'qty_batch' => ['required', 'integer', 'min:1'],
            'mode' => ['required', 'in:to_stock,direct_sale'],
            'override_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $recipe = CompoundRecipe::with(['components.component.units', 'product.units'])
            ->findOrFail($data['recipe_id']);
        $warehouse = Warehouse::query()->withoutGlobalScopes()->findOrFail($data['warehouse_id']);

        try {
            $result = $service->execute(
                recipe: $recipe,
                warehouse: $warehouse,
                qtyBatch: (int) $data['qty_batch'],
                user: $request->user(),
                mode: $data['mode'],
                overridePrice: isset($data['override_price']) ? (string) $data['override_price'] : null,
                options: ['notes' => $data['notes'] ?? null],
            );
        } catch (CompoundExecutionException $e) {
            return back()->withErrors([
                'execution' => 'Stok komponen tidak cukup. Cek preview untuk detail.',
            ])->withInput();
        }

        return back()->with('success', sprintf(
            'Racikan %s x%d berhasil (mode: %s, cost total: %s).',
            $recipe->name,
            (int) $data['qty_batch'],
            $data['mode'],
            $result['cost_total'],
        ));
    }
}
