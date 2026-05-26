<?php

namespace App\Services\Promo\Strategies;

use App\Models\Tenant\Promo;
use App\Services\Promo\Concerns\Qualifies5Dimensions;
use App\Services\Promo\Contracts\PromoStrategy;
use App\Services\Promo\PromoContext;

/**
 * TIPE 4 — Bundling.
 *
 * Beli kombinasi produk tertentu (mis. A + B) → dapat diskon paket.
 *
 * Config schema:
 *   {
 *     "bundle_rules": [
 *       {"product_id": 3, "qty": 1},
 *       {"product_id": 7, "qty": 2}
 *     ]
 *   }
 *
 * Diskon pakai discount_kind + discount_value standar:
 *   - nominal: setCount × discount_value
 *   - percent: setCount × (sum(requiredQty × cartPrice) × value%)
 *
 * Cap (max_discount_amount) PER SET — konsisten semantic "bundling repeatable".
 * 2 set kepicu → cap × 2.
 *
 * Detection:
 *   setCount = min over rules of floor(cartQty[pid] / requiredQty[pid])
 *   Kalau ada rule pid TIDAK di cart → setCount = 0 (bundle gugur).
 *
 * Items di sale_items TETAP di harga normal — diskon bundling muncul
 * sebagai diskon agregat ke total (pola sama dgn promo lain via
 * postSplitSaleWithPromo). Stok/COGS independent.
 */
class BundlingStrategy implements PromoStrategy
{
    use Qualifies5Dimensions;

    public function qualifies(Promo $promo, PromoContext $ctx): bool
    {
        if (! $this->qualifies5Dimensions($promo, $ctx)) {
            return false;
        }

        return $this->setCount($promo, $ctx) > 0;
    }

    public function computeDiscount(Promo $promo, PromoContext $ctx): float
    {
        $setCount = $this->setCount($promo, $ctx);
        if ($setCount <= 0) {
            return 0.0;
        }

        $rules = $this->bundleRules($promo);
        $perSetCap = $promo->max_discount_amount !== null
            ? (float) $promo->max_discount_amount
            : null;

        $kind = $promo->discount_kind;
        $value = (float) $promo->discount_value;

        $perSetDiscount = match ($kind) {
            'nominal' => $value,
            'percent' => $this->bundleValueAtCartPrices($rules, $ctx) * ($value / 100),
            default => 0.0,
        };

        if ($perSetCap !== null) {
            $perSetDiscount = min($perSetDiscount, $perSetCap);
        }
        $perSetDiscount = max(0, $perSetDiscount);

        return round($setCount * $perSetDiscount, 2);
    }

    /**
     * Berapa "set bundle" yang bisa di-extract dari cart sekarang.
     */
    private function setCount(Promo $promo, PromoContext $ctx): int
    {
        $rules = $this->bundleRules($promo);
        if ($rules === []) {
            return 0;
        }

        $cartQty = $this->sumQtyByProduct($ctx);

        $sets = PHP_INT_MAX;
        foreach ($rules as $r) {
            $requiredQty = (float) $r['qty'];
            if ($requiredQty <= 0) {
                continue; // defensive
            }
            $have = (float) ($cartQty[(int) $r['product_id']] ?? 0);
            if ($have <= 0) {
                return 0; // produk wajib tidak di cart → bundle gugur
            }
            $thisCanMake = (int) floor($have / $requiredQty);
            if ($thisCanMake < $sets) {
                $sets = $thisCanMake;
            }
        }

        return $sets === PHP_INT_MAX ? 0 : max(0, $sets);
    }

    /**
     * Nilai 1 set bundle pakai harga di cart (bukan price master).
     * Konsisten dgn line item — kalau ada price tier override.
     */
    private function bundleValueAtCartPrices(array $rules, PromoContext $ctx): float
    {
        $cartPrice = $this->priceByProduct($ctx);
        $sum = 0.0;
        foreach ($rules as $r) {
            $pid = (int) $r['product_id'];
            $sum += (float) $r['qty'] * (float) ($cartPrice[$pid] ?? 0);
        }

        return $sum;
    }

    /**
     * @return list<array{product_id:int, qty:float}>
     */
    private function bundleRules(Promo $promo): array
    {
        $config = $promo->config ?? [];
        $raw = $config['bundle_rules'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $r) {
            $pid = (int) ($r['product_id'] ?? 0);
            $qty = (float) ($r['qty'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $out[] = ['product_id' => $pid, 'qty' => $qty];
            }
        }

        return $out;
    }

    /**
     * @return array<int, float>
     */
    private function sumQtyByProduct(PromoContext $ctx): array
    {
        $sum = [];
        foreach ($ctx->items as $i) {
            $pid = (int) ($i['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $sum[$pid] = ($sum[$pid] ?? 0) + (float) ($i['qty'] ?? 0);
        }

        return $sum;
    }

    /**
     * Harga line item pertama per produk.
     *
     * @return array<int, float>
     */
    private function priceByProduct(PromoContext $ctx): array
    {
        $out = [];
        foreach ($ctx->items as $i) {
            $pid = (int) ($i['product_id'] ?? 0);
            if ($pid <= 0 || isset($out[$pid])) {
                continue;
            }
            $out[$pid] = (float) ($i['price'] ?? 0);
        }

        return $out;
    }
}
