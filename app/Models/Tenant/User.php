<?php

namespace App\Models\Tenant;

use App\Models\User as BaseUser;

/**
 * Tenant-scoped user (POS operators). Resolved against the tenant DB
 * `users` table once tenancy is initialized.
 */
class User extends BaseUser
{
    protected $table = 'users';
}
