<?php

namespace App\Models\Central;

use App\Models\User as BaseUser;

/**
 * Central SaaS super-admin user (central DB `users` table).
 */
class User extends BaseUser
{
    protected $table = 'users';
}
