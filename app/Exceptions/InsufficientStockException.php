<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by StockMovement::record() inside the locked transaction when an
 * outbound movement would push base-unit stock below zero and the caller
 * does not have the override permission.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly string $availableBaseQty,
        public readonly string $requestedBaseQty,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? "Insufficient stock: available {$availableBaseQty}, requested {$requestedBaseQty} (base units) for product #{$productId} at warehouse #{$warehouseId}.",
        );
    }
}
