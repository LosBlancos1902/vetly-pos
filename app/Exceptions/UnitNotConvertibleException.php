<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a product cannot be converted between units because the
 * requested unit is not registered for that product in `product_units`.
 */
class UnitNotConvertibleException extends RuntimeException
{
    public static function for(int $productId, int $unitId): self
    {
        return new self("Unit #{$unitId} is not configured for product #{$productId}.");
    }
}
