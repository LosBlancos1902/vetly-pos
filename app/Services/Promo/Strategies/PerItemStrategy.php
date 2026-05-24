<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Promo;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 2 — Diskon per-barang (placeholder).
 *
 * Belum diimplement. Selalu return qualify=false supaya tidak ke-apply.
 * Tipe ini akan: apply diskon ke item spesifik berdasarkan
 * product_id/category_id (di-config via Promo.config JSON).
 */
class PerItemStrategy implements PromoStrategy
{
    public function qualifies(Promo $promo, PromoContext $ctx): bool
    {
        return false; // not yet implemented
    }

    public function computeDiscount(Promo $promo, PromoContext $ctx): float
    {
        return 0.0;
    }
}
