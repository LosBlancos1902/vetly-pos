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
    use \Spatie\Activitylog\Traits\LogsActivity;
    use \App\Models\Tenant\Concerns\LogsTenantActivity;

    public const ACTIVITY_LOG_NAME = 'users';
    public const ACTIVITY_EXCEPT = ['last_login_at', 'email_verified_at'];

    protected $table = 'users';

    /**
     * Force the polymorphic morph type to the BASE class so spatie/permission's
     * model_has_roles + model_has_permissions resolve uniformly across:
     *   - seeders / tinker (which instantiate Tenant\User directly)
     *   - request-time auth (config/auth.php points at App\Models\User)
     *
     * Without this, seeders persist model_type='App\Models\Tenant\User' but
     * auth lookups query model_type='App\Models\User' → no roles → 403.
     */
    public function getMorphClass(): string
    {
        return BaseUser::class;
    }

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
