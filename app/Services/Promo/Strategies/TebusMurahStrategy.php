<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Product;
use App\Models\Tenant\Promo;
use App\Services\Promo\Concerns\Qualifies5Dimensions;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 5 — Tebus Murah.
 *
 * Beli produk syarat (atau penuhi min belanja/qty) → boleh tebus produk Y
 * dengan harga khusus (tebus_price). Kasir SCAN MANUAL produk tebus di cart
 * dengan harga normal — server otomatis kasih diskon sebesar selisih.
 *
 * Customer kontrol penuh:
 *   - Mau tebus = kasir scan produk tebus → dapat diskon
 *   - Nggak mau = tidak scan → tidak ada diskon (valid, opsional)
 *
 * Config schema:
 *   {
 *     "qualifying_product_ids": [10, 15],       // opsional
 *     "qualifying_category_ids": [3],           // opsional
 *     "qualifying_min_qty_per_set": 1,          // qty syarat per 1 set tebus
 *     "tebus_product_id": 42,                   // WAJIB
 *     "tebus_price": 5000,                      // WAJIB (per unit)
 *     "max_tebus_per_transaction": null         // null = unlimited (cap by setCount)
 *   }
 *
 * Logika:
 *   1. 5-dim (periode/cabang/min_purchase/min_qty/kuota) lewat trait
 *   2. Hitung setQualifying:
 *      - qualifying products/categories ada → floor(qualifyingQty / qty_per_set)
 *      - kalau kosong → ∞ (rely pada 5-dim min_purchase/min_qty)
 *   3. tebusInCart = sum qty product=tebus_product_id di items
 *   4. tebusInCart=0 → diskon 0 (customer skip, valid)
 *   5. effective = min(tebusInCart, setQualifying, max_tebus_per_transaction ?? ∞)
 *   6. Diskon = effective × max(0, cartPriceTebus − tebus_price)
 *
 * Edge:
 *   - tebus_price > harga normal (owner typo) → diskon clamp 0
 *   - syarat OK + 3 tebus tapi setQualifying=1 → diskon utk 1 unit, sisa
 *     bayar normal (kasir bisa split line manual kalau mau struk rapi)
 *   - stok tebus habis → bukan domain promo, di-handle StockMovement layer
 */
class TebusMurahStrategy implements PromoStrategy
{
    use Qualifies5Dimensions;

    public function qualifies(Promo $promo, PromoContext $ctx): bool
    {
        if (! $this->qualifies5Dimensions($promo, $ctx)) {
            return false;
        }

        $config = $this->config($promo);
        $tebusPid = (int) ($config['tebus_product_id'] ?? 0);
        if ($tebusPid <= 0) {
            return false;
        }

        // Tebus harus di cart — kalau tidak, customer skip, diskon 0.
        if ($this->qtyOfProduct($ctx, $tebusPid) <= 0) {
            return false;
        }

        // Qualifying sub-condition — kalau dikonfigur, harus min 1 set
        return $this->setQualifying($promo, $ctx) >= 1;
    }

    public function computeDiscount(Promo $promo, PromoContext $ctx): float
    {
        $config = $this->config($promo);
        $tebusPid = (int) ($config['tebus_product_id'] ?? 0);
        $tebusPrice = (float) ($config['tebus_price'] ?? 0);
        if ($tebusPid <= 0) {
            return 0.0;
        }

        $tebusInCart = $this->qtyOfProduct($ctx, $tebusPid);
        if ($tebusInCart <= 0) {
            return 0.0;
        }

        $setQualifying = $this->setQualifying($promo, $ctx);
        if ($setQualifying <= 0) {
            return 0.0;
        }

        $maxPerTrx = $config['max_tebus_per_transaction'] ?? null;
        $effective = $tebusInCart;
        $effective = min($effective, (float) $setQualifying);
        if ($maxPerTrx !== null) {
            $effective = min($effective, (float) $maxPerTrx);
        }
        if ($effective <= 0) {
            return 0.0;
        }

        $cartPriceTebus = $this->priceOfProduct($ctx, $tebusPid);
        $perUnitDiscount = max(0, $cartPriceTebus - $tebusPrice);

        return round($effective * $perUnitDiscount, 2);
    }

    // ─── helpers ────────────────────────────────────────────────

    /**
     * Berapa "set" syarat kualifikasi yang dipenuhi cart.
     * Kalau qualifying_product_ids & qualifying_category_ids kosong → PHP_INT_MAX
     * (5-dim min_purchase/min_qty saja yang jadi syarat).
     */
    private function setQualifying(Promo $promo, PromoContext $ctx): int
    {
        $config = $this->config($promo);
        $productIds = array_map('intval', $config['qualifying_product_ids'] ?? []);
        $categoryIds = array_map('intval', $config['qualifying_category_ids'] ?? []);
        $minPerSet = max(1, (int) ($config['qualifying_min_qty_per_set'] ?? 1));

        if ($productIds === [] && $categoryIds === []) {
            return PHP_INT_MAX;
        }

        // Batch-load category_id untuk produk di cart (1 query)
        $cartProductIds = array_unique(array_map(
            fn ($i) => (int) ($i['product_id'] ?? 0),
            $ctx->items,
        ));
        $productCategoryMap = Product::whereIn('id', $cartProductIds)
            ->pluck('category_id', 'id')
            ->all();

        $qualifyingQty = 0.0;
        foreach ($ctx->items as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $cid = (int) ($productCategoryMap[$pid] ?? 0);
            $matches = in_array($pid, $productIds, true)
                || ($cid && in_array($cid, $categoryIds, true));

            if ($matches) {
                $qualifyingQty += (float) ($item['qty'] ?? 0);
            }
        }

        return (int) floor($qualifyingQty / $minPerSet);
    }

    private function qtyOfProduct(PromoContext $ctx, int $pid): float
    {
        $sum = 0.0;
        foreach ($ctx->items as $i) {
            if ((int) ($i['product_id'] ?? 0) === $pid) {
                $sum += (float) ($i['qty'] ?? 0);
            }
        }

        return $sum;
    }

    private function priceOfProduct(PromoContext $ctx, int $pid): float
    {
        foreach ($ctx->items as $i) {
            if ((int) ($i['product_id'] ?? 0) === $pid) {
                return (float) ($i['price'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Promo $promo): array
    {
        return is_array($promo->config) ? $promo->config : [];
    }
}
