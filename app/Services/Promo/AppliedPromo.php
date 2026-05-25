<?php

namespace App\Services\Promo;

use App\Models\Tenant\Promo;

/**
 * Per-promo breakdown di dalam PromoResult.
 * `coaCode` di-snapshot di sini supaya JournalEngine bisa post
 * tepat ke COA owner-chosen tanpa relookup.
 */
class AppliedPromo
{
    public function __construct(
        public readonly Promo $promo,
        public readonly float $amount,
        public readonly string $coaCode,
    ) {
    }
}
