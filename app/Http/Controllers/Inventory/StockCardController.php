<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\Warehouse;
use App\Services\StockCard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kartu stok — chronological movement ledger per (product, warehouse).
 *
 * Reads via the StockCard service (Phase A); does not recompute balances
 * (stock_movements.balance_qty_after holds the authoritative post-lock
 * value at the moment the movement was recorded).
 */
class StockCardController extends Controller
{
    public function show(Request $request, Product $product, StockCard $stockCard): Response
    {
        $this->authorize('inventory.view');

        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $warehouses = Warehouse::query()->withoutGlobalScopes()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        // Default: first warehouse with any inventory row, else first active.
        $warehouseId = $filters['warehouse_id']
            ?? Inventory::query()->withoutGlobalScopes()
                ->where('product_id', $product->id)
                ->value('warehouse_id')
            ?? $warehouses->first()?->id;

        $movements = collect();
        $currentBalance = null;
        $currentWarehouse = null;

        if ($warehouseId) {
            $currentWarehouse = $warehouses->firstWhere('id', (int) $warehouseId);

            $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
            $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;

            $movements = $stockCard
                ->for($product, $currentWarehouse ?? new Warehouse(['id' => $warehouseId]), $from, $to)
                ->load('user:id,name');

            // Current inventory snapshot (not necessarily the same as last
            // movement's balance_qty_after if filtering crops the tail).
            $currentBalance = Inventory::query()->withoutGlobalScopes()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->first(['qty', 'cost_avg']);
        }

        return Inertia::render('Inventory/StockCard', [
            'product' => $product->only(['id', 'sku', 'name', 'type', 'base_unit_id']),
            'warehouses' => $warehouses,
            'currentWarehouseId' => $warehouseId,
            'currentWarehouse' => $currentWarehouse,
            'currentBalance' => $currentBalance,
            'movements' => $movements,
            'filters' => [
                'warehouse_id' => $warehouseId,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
        ]);
    }
}
