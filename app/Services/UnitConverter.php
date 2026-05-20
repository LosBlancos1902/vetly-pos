<?php

namespace App\Services;

use App\Exceptions\UnitNotConvertibleException;
use App\Models\Tenant\Product;

/**
 * Convert quantities between a product's units.
 *
 * Stock is ALWAYS stored in base unit (level 1, conversion_to_base = 1).
 * Conversions use bcmath at scale 4 to avoid floating-point drift across
 * multi-step conversions (e.g. 1/2KG → KG → SAK).
 *
 * Returned values are numeric strings so callers can decide where to cast
 * (DB binds DECIMAL columns from strings just fine).
 */
class UnitConverter
{
    public const SCALE = 4;

    /**
     * Convert `qty` of `unitId` into the product's base unit.
     *
     * Example: 1 SAK with conversion_to_base=40 → "40.0000" base units.
     */
    public function toBase(Product $product, string|float|int $qty, int $unitId): string
    {
        $factor = $this->factorFor($product, $unitId);

        return bcmul($this->normalize($qty), $factor, self::SCALE);
    }

    /**
     * Convert a base-unit qty into `targetUnitId`.
     *
     * Example: 40 base units with target SAK (factor 40) → "1.0000" SAK.
     */
    public function fromBase(Product $product, string|float|int $baseQty, int $targetUnitId): string
    {
        $factor = $this->factorFor($product, $targetUnitId);

        if (bccomp($factor, '0', self::SCALE) === 0) {
            throw new \DomainException("Conversion factor for unit #{$targetUnitId} is zero.");
        }

        return bcdiv($this->normalize($baseQty), $factor, self::SCALE);
    }

    /**
     * Lookup the conversion_to_base factor for (product, unit) as a string.
     *
     * Uses the `units` relation — eager-loaded if available, otherwise a single
     * query. Callers handling many lines in a hot loop should `->load('units')`
     * once on each product.
     */
    private function factorFor(Product $product, int $unitId): string
    {
        $row = $product->units->firstWhere('unit_id', $unitId);

        if (! $row) {
            throw UnitNotConvertibleException::for($product->id, $unitId);
        }

        return $this->normalize($row->conversion_to_base);
    }

    /**
     * Normalize to a bcmath-friendly numeric string (no scientific notation,
     * preserves precision from DECIMAL casts which already return strings).
     */
    private function normalize(string|float|int $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return number_format((float) $value, self::SCALE, '.', '');
    }
}
