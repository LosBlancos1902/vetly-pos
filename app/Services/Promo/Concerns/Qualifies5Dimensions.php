<?php

namespace App\Services\Promo\Concerns;

use App\Models\Tenant\Promo;
use App\Services\Promo\PromoContext;

/**
 * Shared kualifikasi 5 dimensi (periode + cabang + syarat + kuota).
 * Dipakai oleh semua strategy yg butuh check baseline ini sebelum
 * tipe-spesifik check.
 *
 * Periode + kuota sebagian sudah di-pre-filter di PromoResolver SQL,
 * tapi strategy DOUBLE-CHECK utk safety + handle dimensi yg tidak bisa
 * di SQL (days_of_week + time_start/end karena bisa wrap-around).
 *
 * Tipe spesifik (mis. PerItemStrategy match cart items, BundlingStrategy
 * detect rules) tetap di method strategy masing-masing, di-call SETELAH
 * qualifies5Dimensions() lulus.
 */
trait Qualifies5Dimensions
{
    /** map Carbon dayOfWeekIso (1=Mon..7=Sun) → slug yg disimpan owner */
    private function dowMap(): array
    {
        return [
            1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu',
            5 => 'fri', 6 => 'sat', 7 => 'sun',
        ];
    }

    protected function qualifies5Dimensions(Promo $promo, PromoContext $ctx): bool
    {
        // PERIODE (dimensi 1): safety re-check (resolver sudah filter)
        if ($promo->starts_at && $ctx->datetime->lt($promo->starts_at)) {
            return false;
        }
        if ($promo->ends_at && $ctx->datetime->gt($promo->ends_at)) {
            return false;
        }

        // HARI spesifik
        if (! empty($promo->days_of_week)) {
            $todaySlug = $this->dowMap()[$ctx->datetime->dayOfWeekIso] ?? null;
            if (! in_array($todaySlug, $promo->days_of_week, true)) {
                return false;
            }
        }

        // JAM spesifik (support wrap-around)
        if ($promo->time_start !== null && $promo->time_end !== null) {
            $nowTime = $ctx->datetime->format('H:i:s');
            $start = substr((string) $promo->time_start, 0, 8);
            $end = substr((string) $promo->time_end, 0, 8);
            if ($start <= $end) {
                if ($nowTime < $start || $nowTime > $end) {
                    return false;
                }
            } else {
                // Wrap-around (mis. 22:00 → 02:00)
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
}
