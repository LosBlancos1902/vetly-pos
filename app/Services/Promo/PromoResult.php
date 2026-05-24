<?php

namespace App\Services\Promo;

/**
 * Output dari PromoResolver: total diskon + breakdown per promo yg ke-apply.
 * Dipakai CashierController utk update sale, insert promo_applications,
 * dan kirim {amount, coa_code} ke JournalEngine.
 */
class PromoResult
{
    /**
     * @param  list<AppliedPromo>  $applied
     */
    public function __construct(
        public readonly float $totalDiscount,
        public readonly array $applied,
    ) {
    }

    public static function empty(): self
    {
        return new self(0.0, []);
    }

    public function isEmpty(): bool
    {
        return $this->totalDiscount <= 0;
    }
}

