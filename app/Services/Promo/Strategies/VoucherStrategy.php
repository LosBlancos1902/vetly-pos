<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Promo;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 3 — Kode voucher (placeholder).
 *
 * Owner generate code; customer kasih code ke kasir; kasir input → match.
 * Belum diimplement.
 */
class VoucherStrategy implements PromoStrategy
{
    public function qualifies(Promo $promo, PromoContext $ctx): bool
    {
        return false;
    }

    public function computeDiscount(Promo $promo, PromoContext $ctx): float
    {
        return 0.0;
    }
}
