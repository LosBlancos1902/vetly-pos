<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Promo;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 4 — Bundling (beli A+B, dapat harga paket).
 * Placeholder. Belum diimplement.
 */
class BundlingStrategy implements PromoStrategy
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
