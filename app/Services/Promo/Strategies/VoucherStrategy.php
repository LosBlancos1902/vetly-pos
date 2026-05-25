<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Promo;
use App\Services\Promo\Concerns\Qualifies5Dimensions;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 3 — Voucher (kode-based).
 *
 * Beda dari tipe 1 (auto) dan tipe 2 (per-item auto-match):
 * voucher ONLY apply kalau kasir input kode yg match. Customer kasih
 * kode ke kasir → kasir ketik → diskon apply.
 *
 * Diskon dihitung pada NET subtotal (qty × price − manual discount),
 * sama dgn tipe 1 (whole-transaction). Cap honored.
 *
 * Single-use voucher dihandle via 5-dimensi kuota (set quota_total=1).
 */
class VoucherStrategy implements PromoStrategy
{
    use Qualifies5Dimensions;

    public function qualifies(Promo $promo, PromoContext $ctx): bool
    {
        // Kalau kasir tidak input kode → voucher tidak applicable
        if ($ctx->voucherCode === null || $ctx->voucherCode === '') {
            return false;
        }

        // Kode harus match (case-insensitive — server normalize ke UPPERCASE)
        $promoCode = strtoupper(trim((string) $promo->voucher_code));
        $inputCode = strtoupper(trim($ctx->voucherCode));
        if ($promoCode === '' || $promoCode !== $inputCode) {
            return false;
        }

        // Lalu cek 5 dimensi (periode, cabang, syarat, kuota)
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

        // Cap whole-transaction (sama dgn tipe 1, beda dari tipe 2 yg cap per-item)
        if ($promo->max_discount_amount !== null) {
            $discount = min($discount, (float) $promo->max_discount_amount);
        }

        $discount = min($discount, $net);

        return round(max(0, $discount), 2);
    }
}
