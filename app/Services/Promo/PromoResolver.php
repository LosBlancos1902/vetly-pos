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
 * SELECTION LOGIC (bukan auto-sum-semua):
 *   1. Compute candidate discount untuk SEMUA promo applicable
 *   2. Partition: exclusive (is_stackable=false) vs stackable (true)
 *   3. Dari pool EXCLUSIVE: pick HANYA SATU dgn amount terbesar
 *      (tie-break: promo.id terkecil = first-created wins)
 *   4. Total = chosenExclusive.amount + sum(stackable.amount)
 *   5. Clamp total ≤ netSubtotal (safety: over-discount prevention)
 *
 * Apple-to-apple comparison: setiap strategy compute return Rp number
 * final ke-customer. Compare langsung tanpa normalize per-tipe.
 *
 * Extensibility: tipe baru = tambah kelas implement PromoStrategy +
 * register di $registry. Selection logic universal.
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

        // ── Step 1-2: Build candidates (qualify + compute), partition ──
        /** @var list<AppliedPromo> $exclusive */
        $exclusive = [];
        /** @var list<AppliedPromo> $stackable */
        $stackable = [];

        foreach ($active as $promo) {
            $strategyClass = $this->registry[$promo->type] ?? null;
            if ($strategyClass === null) {
                continue;
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

            $candidate = new AppliedPromo(
                promo: $promo,
                amount: $discount,
                coaCode: $promo->effectiveCoaCode(),
            );

            if ($promo->is_stackable) {
                $stackable[] = $candidate;
            } else {
                $exclusive[] = $candidate;
            }
        }

        // ── Step 3: Pick 1 dari exclusive (max amount, tie-break ID kecil) ──
        $chosenExclusive = null;
        foreach ($exclusive as $cand) {
            if ($chosenExclusive === null
                || $cand->amount > $chosenExclusive->amount
                || ($cand->amount === $chosenExclusive->amount
                    && $cand->promo->id < $chosenExclusive->promo->id)
            ) {
                $chosenExclusive = $cand;
            }
        }

        // ── Step 4: Bangun applied list + total ──
        $applied = [];
        $total = 0.0;

        if ($chosenExclusive !== null) {
            $applied[] = $chosenExclusive;
            $total += $chosenExclusive->amount;
        }
        foreach ($stackable as $cand) {
            $applied[] = $cand;
            $total += $cand->amount;
        }

        // ── Step 5: Clamp ke netSubtotal (safety) ──
        $netSubtotal = $ctx->netSubtotal();
        if ($total > $netSubtotal && $netSubtotal >= 0) {
            // Proportional scale down supaya audit trail (promo_applications)
            // tetap konsisten dgn total final
            $ratio = $netSubtotal / $total;
            $applied = array_map(fn (AppliedPromo $a) => new AppliedPromo(
                promo: $a->promo,
                amount: round($a->amount * $ratio, 2),
                coaCode: $a->coaCode,
            ), $applied);
            $total = $netSubtotal;
        }

        return new PromoResult(round($total, 2), $applied);
    }
}
