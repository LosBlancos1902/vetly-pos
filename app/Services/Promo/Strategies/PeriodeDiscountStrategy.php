<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Promo;
use App\Services\Promo\Concerns\Qualifies5Dimensions;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 1 — Diskon Periode (full transaksi).
 *
 * Diskon dihitung pada NET subtotal (qty × price − manual discount).
 * Tipe ini full-transaction; per-item match dipakai oleh Tipe 2
 * (PerItemStrategy). 5 dimensi check di-share via trait.
 */
class PeriodeDiscountStrategy implements PromoStrategy
{
    use Qualifies5Dimensions;

    public function qualifies(Promo $promo, PromoContext $ctx): bool
    {
        return $this->qualifies5Dimensions($promo, $ctx);
    }

    public function computeDiscount(Promo $promo, PromoContext $ctx): float
    {
        $net = $ctx->netSubtotal();
        if ($net <= 0) {
            return 0.0;
        }

        $discount = match ($promo->discount_kind) {
            'percent' => $net * ((float) $promo->discount_value / 100),
            'nominal' => (float) $promo->discount_value,
            default => 0.0,
        };

        // Cap utk % kalau owner set max_discount_amount (whole-transaction cap)
        if ($promo->max_discount_amount !== null) {
            $discount = min($discount, (float) $promo->max_discount_amount);
        }

        // Jangan diskon lebih besar dari net subtotal
        $discount = min($discount, $net);

        return round(max(0, $discount), 2);
    }
}
