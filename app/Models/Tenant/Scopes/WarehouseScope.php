<?php

namespace App\Models\Tenant\Scopes;

use App\Support\CurrentWarehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Filters tenant rows by warehouse for users fixed to one outlet (cashier/staff).
 *
 * Skips when:
 *   - no authenticated user (CLI, queue, seeders)
 *   - authenticated user has no fixed warehouse (owner/manager) — they may
 *     choose to filter explicitly via withCurrentWarehouse() or query as-is
 *   - explicit ::withoutGlobalScope(WarehouseScope::class)
 */
class WarehouseScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if (! $user || $user->warehouse_id === null) {
            return;
        }

        $builder->where($model->getTable().'.warehouse_id', $user->warehouse_id);
    }
}
