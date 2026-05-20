<?php

namespace App\Models\Tenant\Concerns;

use App\Models\Tenant\Scopes\WarehouseScope;
use Illuminate\Database\Eloquent\Builder;

trait ScopedToWarehouse
{
    public static function bootScopedToWarehouse(): void
    {
        static::addGlobalScope(new WarehouseScope);
    }

    /**
     * Owner/manager helper: restrict a query to a specific warehouse explicitly.
     */
    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where($this->getTable().'.warehouse_id', $warehouseId);
    }
}
