<?php

namespace App\Services\Promo;

use App\Models\Tenant\Warehouse;
use Carbon\CarbonInterface;

/**
 * Input DTO ke PromoResolver. Self-contained snapshot dari cart state
 * + meta transaksi, supaya strategy bisa qualify/compute tanpa nyentuh
 * DB / framework state.
 */
class PromoContext
{
    /**
     * @param  array<int, array{product_id:int,unit_id:int,qty:float,price:float,discount_amount?:float}>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly Warehouse $warehouse,
        public readonly ?int $customerId,
        public readonly CarbonInterface $datetime,
        public readonly float $subtotal,         // sum(qty × price)
        public readonly float $manualDiscount,   // sum(items.discount_amount) — diskon manual per-item
    ) {
    }

    public function netSubtotal(): float
    {
        return $this->subtotal - $this->manualDiscount;
    }

    public function totalQty(): float
    {
        $sum = 0.0;
        foreach ($this->items as $i) {
            $sum += (float) ($i['qty'] ?? 0);
        }

        return $sum;
    }
}
