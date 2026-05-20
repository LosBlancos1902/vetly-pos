<?php

namespace App\Services;

/**
 * Promotion / discount engine.
 *
 * Scaffold: returns the cart unchanged with a zero discount. Extend with
 * rule types (buy X get Y, % off category, member price, bundle, ...).
 */
class PromoEngine
{
    /**
     * @param  array<int, array{product_id:int, qty:float, price:float, subtotal:float}>  $items
     * @return array{items: array, discount_total: float, applied: array}
     */
    public function apply(array $items, ?int $customerId = null): array
    {
        return [
            'items' => $items,
            'discount_total' => 0.0,
            'applied' => [],
        ];
    }
}
