<?php

namespace App\Support;

use App\Models\Tenant\User;
use App\Models\Tenant\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Resolve the warehouse the current request should operate against.
 *
 * Rules:
 *   - Staff/cashier (users.warehouse_id NOT NULL) → always their fixed warehouse;
 *     they cannot switch. Any session override is ignored.
 *   - Owner/manager (users.warehouse_id NULL) → reads session('current_warehouse_id'),
 *     falling back to the tenant's default warehouse.
 *
 * Returns NULL only when no user is authenticated, or when an owner has no
 * default warehouse seeded yet (caller must handle).
 */
class CurrentWarehouse
{
    public const SESSION_KEY = 'current_warehouse_id';

    public static function resolveId(?User $user = null): ?int
    {
        $user ??= Auth::user();
        if (! $user) {
            return null;
        }

        if ($user->warehouse_id !== null) {
            return (int) $user->warehouse_id;
        }

        $sessionId = Session::get(self::SESSION_KEY);
        if (is_int($sessionId) || (is_string($sessionId) && ctype_digit($sessionId))) {
            return (int) $sessionId;
        }

        return Warehouse::query()->default()->active()->value('id')
            ?? Warehouse::query()->active()->value('id');
    }

    public static function resolve(?User $user = null): ?Warehouse
    {
        $id = self::resolveId($user);

        return $id ? Warehouse::find($id) : null;
    }

    /**
     * Owners/managers can switch via UI. Staff cannot — attempts are silently ignored.
     */
    public static function setForOwner(int $warehouseId, ?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user || $user->warehouse_id !== null) {
            return false;
        }

        Session::put(self::SESSION_KEY, $warehouseId);

        return true;
    }
}
