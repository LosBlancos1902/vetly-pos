<?php

namespace App\Support;

use App\Models\Tenant\Product;

/**
 * Single source of truth utk label & deskripsi "Jenis Produk" di backend
 * (Inertia props, Excel export header, dst). Mirror dari
 * resources/js/lib/productTypes.ts — perubahan label cukup edit di kedua
 * tempat (5 entry, low maintenance).
 *
 * VALUE (slug) MATCH Product::TYPE_* — JANGAN diubah, dipakai DB enum +
 * StockGuard + JournalEngine.
 */
class ProductTypes
{
    /**
     * @return array<string, string> value => label
     */
    public static function labels(): array
    {
        return [
            Product::TYPE_SALEABLE_RETAIL => 'Barang',
            Product::TYPE_COMPOUNDABLE_DRUG => 'Obat Racikan',
            Product::TYPE_RAW_MATERIAL => 'Bahan Baku',
            Product::TYPE_SERVICE => 'Jasa',
            Product::TYPE_SERVICE_WITH_CONSUMPTION => 'Jasa + Bahan',
        ];
    }

    /**
     * Label aman utk render — fallback ke value asli kalau slug tak dikenal.
     */
    public static function label(?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return self::labels()[$value] ?? $value;
    }

    /**
     * Daftar value valid (utk validation rule / dropdown options).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::labels());
    }
}
