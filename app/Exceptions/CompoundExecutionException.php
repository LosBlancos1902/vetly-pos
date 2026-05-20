<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a compound recipe cannot be executed because one or more
 * components are insufficient. The transaction is rolled back before this
 * is thrown so no movement persists.
 *
 * `$shortages` is keyed by component product id, with each entry shaped:
 *   [
 *     'product_id'      => int,
 *     'available'       => string (base qty),
 *     'required_base'   => string,
 *   ]
 */
class CompoundExecutionException extends RuntimeException
{
    public function __construct(public readonly array $shortages, ?string $message = null)
    {
        parent::__construct($message ?? 'Compound execution aborted: insufficient components.');
    }
}
