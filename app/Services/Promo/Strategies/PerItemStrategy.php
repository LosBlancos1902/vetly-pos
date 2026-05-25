<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Product;
use App\Models\Tenant\Promo;
use App\Services\Promo\Concerns\Qualifies5Dimensions;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 2 — Diskon Per-Barang.
 *
 * Diskon berlaku ke ITEM yg match config (product_ids ATAU category_ids),
 * bukan total transaksi. Item non-match = harga normal.
 *
 * Config schema:
 *   { "product_ids": [3, 7], "category_ids": [1, 5] }
 *
 * Cap interpretation: max_discount_amount = batas PER ITEM match (bukan
 * cap total transaksi — konsisten semantic "per-barang").
 *
 * 5 dimensi check di-share via trait.
 */
class PerItemStrategy implements PromoStrategy
{
    use Qualifies5Dimensions;

    public function qualifies(Promo $promo, PromoContext $ctx): bool
    {
        if (! $this->qualifies5Dimensions($promo, $ctx)) {
            return false;
        }

        // Tipe-spesifik: minimal 1 item di cart match config
        return $this->getMatchingItems($promo, $ctx) !== [];
    }

    public function computeDiscount(Promo $promo, PromoContext $ctx): float
    {
        $matches = $this->getMatchingItems($promo, $ctx);
        if ($matches === []) {
            return 0.0;
        }

        $cap = $promo->max_discount_amount !== null
            ? (float) $promo->max_discount_amount
            : null;
        $kind = $promo->discount_kind;
        $value = (float) $promo->discount_value;

        $total = 0.0;
        foreach ($matches as $item) {
            $lineSubtotal = (float) $item['qty'] * (float) $item['price']
                - (float) ($item['discount_amount'] ?? 0);
            if ($lineSubtotal <= 0) {
                continue;
            }

            $lineDiscount = match ($kind) {
                'percent' => $lineSubtotal * ($value / 100),
                'nominal' => $value,
                default => 0.0,
            };

            // Cap PER ITEM (konsisten dgn nama "per-barang")
            if ($cap !== null) {
                $lineDiscount = min($lineDiscount, $cap);
            }
            // Jangan diskon line > line subtotal
            $lineDiscount = min($lineDiscount, $lineSubtotal);

            $total += max(0, $lineDiscount);
        }

        return round($total, 2);
    }

    /**
     * Return subset items dari ctx yg match config.product_ids ATAU
     * config.category_ids. Batch-load category_id supaya 1 query saja.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getMatchingItems(Promo $promo, PromoContext $ctx): array
    {
        $config = $promo->config ?? [];
        $productIds = array_map('intval', $config['product_ids'] ?? []);
        $categoryIds = array_map('intval', $config['category_ids'] ?? []);

        if ($productIds === [] && $categoryIds === []) {
            return []; // config kosong = invalid (validator harusnya cegah)
        }

        // Batch-load category_id per product di cart — 1 query saja
        $cartProductIds = array_unique(array_map(
            fn ($i) => (int) ($i['product_id'] ?? 0),
            $ctx->items,
        ));
        $productCategoryMap = Product::whereIn('id', $cartProductIds)
            ->pluck('category_id', 'id')
            ->all();

        $matched = [];
        foreach ($ctx->items as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $cid = (int) ($productCategoryMap[$pid] ?? 0);

            if (in_array($pid, $productIds, true)) {
                $matched[] = $item;

                continue;
            }
            if ($cid && in_array($cid, $categoryIds, true)) {
                $matched[] = $item;
            }
        }

        return $matched;
    }
}
