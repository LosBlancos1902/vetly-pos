<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Promo;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 5 — Tebus murah (beli X dgn syarat, dapat Y harga khusus).
 * Placeholder. Belum diimplement.
 */
class TebusMurahStrategy implements PromoStrategy
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
