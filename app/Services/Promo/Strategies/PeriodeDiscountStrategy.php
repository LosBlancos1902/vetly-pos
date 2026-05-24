<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Promo;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 1 — Diskon Periode.
 *
 * Aktif dalam rentang starts_at..ends_at, opsional dipersempit:
 *   - days_of_week (mis. cuma Sabtu-Minggu)
 *   - time_start..time_end (happy hour)
 *
 * Diskon `percent` (dgn cap optional) atau `nominal` flat. Diskon
 * tidak melebihi net subtotal (qty × price − diskon manual per-item).
 */
class PeriodeDiscountStrategy implements PromoStrategy
{
    /** map carbon dayOfWeekIso (1=Mon..7=Sun) → slug yg disimpan owner */
    private const DOW_MAP = [
        1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu',
        5 => 'fri', 6 => 'sat', 7 => 'sun',
    ];

    public function qualifies(Promo $promo, PromoContext $ctx): bool
    {
        // PERIODE — double-check (resolver sudah filter tapi safety net):
        if ($promo->starts_at && $ctx->datetime->lt($promo->starts_at)) {
            return false;
        }
        if ($promo->ends_at && $ctx->datetime->gt($promo->ends_at)) {
            return false;
        }

        // HARI spesifik
        if (! empty($promo->days_of_week)) {
            $todaySlug = self::DOW_MAP[$ctx->datetime->dayOfWeekIso] ?? null;
            if (! in_array($todaySlug, $promo->days_of_week, true)) {
                return false;
            }
        }

        // JAM spesifik (happy hour)
        if ($promo->time_start !== null && $promo->time_end !== null) {
            $nowTime = $ctx->datetime->format('H:i:s');
            // Normalize TIME column ke H:i:s
            $start = substr((string) $promo->time_start, 0, 8);
            $end = substr((string) $promo->time_end, 0, 8);
            // Support wrap-around (mis. 22:00 → 02:00 keesokan hari)
            if ($start <= $end) {
                if ($nowTime < $start || $nowTime > $end) {
                    return false;
                }
            } else {
                // Wrap-around: in-range kalau >= start OR <= end
                if ($nowTime < $start && $nowTime > $end) {
                    return false;
                }
            }
        }

        // CABANG (dimensi 2): pivot kosong = semua cabang
        $warehouseIds = $promo->warehouses->pluck('id')->all();
        if ($warehouseIds !== [] && ! in_array($ctx->warehouse->id, $warehouseIds, true)) {
            return false;
        }

        // SYARAT (dimensi 4)
        if ((float) $promo->min_purchase > 0 && $ctx->netSubtotal() < (float) $promo->min_purchase) {
            return false;
        }
        if ((int) $promo->min_qty > 0 && $ctx->totalQty() < (int) $promo->min_qty) {
            return false;
        }

        return true;
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

        // Cap utk % kalau owner set max_discount_amount
        if ($promo->max_discount_amount !== null) {
            $discount = min($discount, (float) $promo->max_discount_amount);
        }

        // Jangan diskon lebih besar dari net subtotal
        $discount = min($discount, $net);

        return round(max(0, $discount), 2);
    }
}
