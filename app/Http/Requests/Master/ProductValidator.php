<?php

namespace App\Http\Requests\Master;

use App\Models\Tenant\PriceTier;
use App\Models\Tenant\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;

/**
 * Plain validator class untuk Store/Update Product. Bukan FormRequest —
 * dipanggil eksplisit dari controller supaya test bisa pakai Request
 * biasa tanpa harus jalan lewat HTTP kernel (Tenant tests run di luar
 * kernel HTTP per design — lihat TenantTestCase docblock).
 *
 * Validasi struktur dasar pakai array rules; aturan kompleks
 * (exactly 1 base unit, harga default wajib di base unit) pakai
 * after-callback supaya bisa pakai context PriceTier.
 */
class ProductValidator
{
    public static function validate(Request $request, ?Product $product = null): array
    {
        $rules = self::rules($product);
        $validator = ValidatorFacade::make($request->all(), $rules);
        self::addAfterRules($validator);

        return $validator->validate();
    }

    private static function rules(?Product $product): array
    {
        $skuRule = $product
            ? ['sometimes', 'required', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($product->id)]
            : ['required', 'string', 'max:64', Rule::unique('products', 'sku')];

        return [
            'sku' => $skuRule,
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'type' => ['required', Rule::in([
                Product::TYPE_SALEABLE_RETAIL,
                Product::TYPE_COMPOUNDABLE_DRUG,
                Product::TYPE_SERVICE,
                Product::TYPE_SERVICE_WITH_CONSUMPTION,
                Product::TYPE_RAW_MATERIAL,
            ])],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],

            'units' => ['required', 'array', 'min:1'],
            'units.*.unit_id' => ['required', 'integer', 'exists:master_units,id', 'distinct'],
            'units.*.level' => ['required', 'integer', 'min:1'],
            // 0.01 supaya konsisten dgn QTY_TYPO_THRESHOLD fix desimal.
            'units.*.conversion_to_base' => ['required', 'numeric', 'min:0.01'],
            'units.*.is_purchase_unit' => ['nullable', 'boolean'],
            'units.*.is_sale_unit' => ['nullable', 'boolean'],
            'units.*.barcode_per_unit' => ['nullable', 'string', 'max:64'],

            'units.*.prices' => ['nullable', 'array'],
            'units.*.prices.*.price_tier_id' => ['required', 'integer', 'exists:price_tiers,id'],
            'units.*.prices.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }

    private static function addAfterRules(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $units = $v->getData()['units'] ?? [];
            if (! is_array($units) || $units === []) {
                return; // baseline rules sudah catch ini
            }

            // Exactly 1 base unit (level=1) dgn conversion=1.
            $baseCount = collect($units)->where('level', 1)->count();
            if ($baseCount !== 1) {
                $v->errors()->add('units', 'Harus ada tepat 1 satuan base (level=1).');

                return;
            }

            $baseIdx = collect($units)->search(fn ($u) => (int) ($u['level'] ?? 0) === 1);
            $baseConv = (float) ($units[$baseIdx]['conversion_to_base'] ?? 0);
            if (abs($baseConv - 1.0) > 0.0001) {
                $v->errors()->add("units.{$baseIdx}.conversion_to_base", 'Base unit harus rasio = 1.');
            }

            // Harga tier default WAJIB di base unit.
            $defaultTierId = PriceTier::where('is_default', true)->value('id');
            if ($defaultTierId === null) {
                $v->errors()->add('units', 'Tier default belum ada — hubungi admin.');

                return;
            }
            $basePrices = collect($units[$baseIdx]['prices'] ?? []);
            $hasDefault = $basePrices->contains(
                fn ($p) => (int) ($p['price_tier_id'] ?? 0) === $defaultTierId
                    && ($p['price'] ?? null) !== null
            );
            if (! $hasDefault) {
                $v->errors()->add(
                    "units.{$baseIdx}.prices",
                    'Harga di tier default (Eceran) wajib diisi untuk satuan base.'
                );
            }
        });
    }
}
