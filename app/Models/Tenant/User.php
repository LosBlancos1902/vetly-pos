<?php

namespace App\Models\Tenant;

use App\Models\User as BaseUser;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-scoped user (POS operators). Resolved against the tenant DB
 * `users` table once tenancy is initialized.
 *
 * warehouse_id semantics:
 *   - non-null → user fixed to one outlet (cashier/staff)
 *   - null     → cross-warehouse access (owner/manager), needs permission 'warehouse.view_all'
 */
class User extends BaseUser
{
    protected $table = 'users';

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Cashier/staff are fixed to a single warehouse.
     */
    public function isFixedToWarehouse(): bool
    {
        return $this->warehouse_id !== null;
    }

    public function canAccessAllWarehouses(): bool
    {
        return $this->warehouse_id === null && $this->can('warehouse.view_all');
    }
}
