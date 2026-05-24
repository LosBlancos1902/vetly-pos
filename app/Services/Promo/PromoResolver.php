<?php

namespace App\Services\Promo;

use App\Models\Tenant\Promo;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\AppliedPromo;
use App\Services\Promo\Strategies\BundlingStrategy;
use App\Services\Promo\Strategies\PeriodeDiscountStrategy;
use App\Services\Promo\Strategies\PerItemStrategy;
use App\Services\Promo\Strategies\TebusMurahStrategy;
use App\Services\Promo\Strategies\VoucherStrategy;

/**
 * Entry point promo engine. Dipanggil CashierController di:
 *   - preview() — display real-time di kasir (no DB write)
 *   - store()   — saat commit transaksi (DB write + lock kuota)
 *
 * Behavior: STACK ALL applicable promo (sum semua diskon). Owner control
 * via aktif/nonaktif + periode. Future flag is_stackable bisa nyusul.
 *
 * Extensibility: tipe baru = tambah kelas implement PromoStrategy +
 * register di $registry. Tidak perlu ubah method resolve().
 */
class PromoResolver
{
    /** @var array<string, class-string<PromoStrategy>> */
    private array $registry = [
        Promo::TYPE_PERIODE => PeriodeDiscountStrategy::class,
        Promo::TYPE_PER_ITEM => PerItemStrategy::class,
        Promo::TYPE_VOUCHER => VoucherStrategy::class,
        Promo::TYPE_BUNDLING => BundlingStrategy::class,
        Promo::TYPE_TEBUS_MURAH => TebusMurahStrategy::class,
    ];

    public function resolve(PromoContext $ctx): PromoResult
    {
        $active = Promo::query()
            ->with(['warehouses:id', 'discountCoa:id,code'])
            ->where('is_active', true)
            ->where('starts_at', '<=', $ctx->datetime)
            ->where('ends_at', '>=', $ctx->datetime)
            // Quota: unlimited (null) atau masih ada slot
            ->where(function ($q) {
                $q->whereNull('quota_total')
                    ->orWhereColumn('quota_used', '<', 'quota_total');
            })
            ->get();

        $applied = [];
        $total = 0.0;

        foreach ($active as $promo) {
            $strategyClass = $this->registry[$promo->type] ?? null;
            if ($strategyClass === null) {
                continue; // tipe unknown — skip silent
            }
            /** @var PromoStrategy $strategy */
            $strategy = app($strategyClass);

            if (! $strategy->qualifies($promo, $ctx)) {
                continue;
            }

            $discount = $strategy->computeDiscount($promo, $ctx);
            if ($discount <= 0) {
                continue;
            }

            $applied[] = new AppliedPromo(
                promo: $promo,
                amount: $discount,
                coaCode: $promo->effectiveCoaCode(),
            );
            $total += $discount;
        }

        return new PromoResult(round($total, 2), $applied);
    }
}
