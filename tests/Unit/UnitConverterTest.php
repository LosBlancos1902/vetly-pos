<?php

use App\Exceptions\UnitNotConvertibleException;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductUnit;
use App\Services\UnitConverter;

/**
 * Beauty Premium (referensi Accurate Kamo):
 *   level 1  1/2KG   conversion_to_base = 1      (base)
 *   level 2  KG      conversion_to_base = 2      (1 KG = 2 × 1/2KG)
 *   level 3  SAK     conversion_to_base = 40     (1 SAK = 40 × 1/2KG)
 */
function beautyPremium(): Product
{
    $product = new Product;
    $product->id = 1;
    $product->setRelation('units', collect([
        new ProductUnit(['product_id' => 1, 'unit_id' => 10, 'level' => 1, 'conversion_to_base' => 1]),
        new ProductUnit(['product_id' => 1, 'unit_id' => 11, 'level' => 2, 'conversion_to_base' => 2]),
        new ProductUnit(['product_id' => 1, 'unit_id' => 12, 'level' => 3, 'conversion_to_base' => 40]),
    ]));

    return $product;
}

it('toBase: 1 SAK = 40 base units (1/2KG)', function () {
    expect((new UnitConverter)->toBase(beautyPremium(), 1, 12))->toBe('40.0000');
});

it('toBase: 3 KG = 6 base units', function () {
    expect((new UnitConverter)->toBase(beautyPremium(), 3, 11))->toBe('6.0000');
});

it('toBase: base unit qty passes through', function () {
    expect((new UnitConverter)->toBase(beautyPremium(), 5, 10))->toBe('5.0000');
});

it('fromBase: 80 base → 2 SAK', function () {
    expect((new UnitConverter)->fromBase(beautyPremium(), 80, 12))->toBe('2.0000');
});

it('fromBase: 7 base → 3.5 KG', function () {
    expect((new UnitConverter)->fromBase(beautyPremium(), 7, 11))->toBe('3.5000');
});

it('toBase: handles fractional input without floating point drift', function () {
    // 0.1 + 0.2 != 0.3 in float; bcmath keeps it exact at scale 4.
    expect((new UnitConverter)->toBase(beautyPremium(), '0.1', 11))->toBe('0.2000');
});

it('throws when unit is not configured for product', function () {
    (new UnitConverter)->toBase(beautyPremium(), 1, 999);
})->throws(UnitNotConvertibleException::class);

it('round-trip toBase + fromBase preserves quantity', function () {
    $c = new UnitConverter;
    $base = $c->toBase(beautyPremium(), 2.5, 12);  // 2.5 SAK = 100 base
    expect($base)->toBe('100.0000')
        ->and($c->fromBase(beautyPremium(), $base, 12))->toBe('2.5000');
});
