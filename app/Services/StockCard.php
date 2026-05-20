<?php

namespace App\Services;

use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-side helper for "kartu stok" — a chronological ledger of every
 * stock movement for a product within a warehouse, with the running base-unit
 * balance after each movement already stored on the row.
 *
 * The UI layer can render this directly. We deliberately do NOT compute the
 * running balance here — `stock_movements.balance_qty_after` already holds
 * the post-lock authoritative value at the time the movement happened.
 */
class StockCard
{
    /**
     * @return Collection<int, StockMovementModel>
     */
    public function for(Product $product, Warehouse $warehouse, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $query = StockMovementModel::query()
            ->withoutGlobalScopes()
            ->with('unitInput:id,code,name')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query->get();
    }
}
