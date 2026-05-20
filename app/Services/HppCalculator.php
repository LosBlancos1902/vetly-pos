<?php

namespace App\Services;

use App\Models\Tenant\Inventory;

/**
 * Moving Average cost.
 *
 * IN : new_avg = ((qty*avg) + (in_qty*in_cost)) / (qty + in_qty)
 * OUT: cost_avg unchanged.
 */
class HppCalculator
{
    /**
     * @return array{qty: string, cost_avg: string}
     */
    public function recalculate(Inventory $inventory, float $incomingQty, float $incomingCost): array
    {
        $currentQty = (float) $inventory->qty;
        $currentAvg = (float) $inventory->cost_avg;

        if ($incomingQty > 0) {
            $totalQty = $currentQty + $incomingQty;
            $newAvg = $totalQty > 0
                ? (($currentQty * $currentAvg) + ($incomingQty * $incomingCost)) / $totalQty
                : 0.0;

            return [
                'qty' => number_format($totalQty, 4, '.', ''),
                'cost_avg' => number_format($newAvg, 2, '.', ''),
            ];
        }

        // OUT (incomingQty negative) — average is preserved.
        return [
            'qty' => number_format($currentQty + $incomingQty, 4, '.', ''),
            'cost_avg' => number_format($currentAvg, 2, '.', ''),
        ];
    }
}
