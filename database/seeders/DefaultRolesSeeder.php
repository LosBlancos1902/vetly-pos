<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DefaultRolesSeeder extends Seeder
{
    /**
     * Critical / granular permissions used across the POS.
     */
    private array $permissions = [
        // POS
        'pos.access',
        'pos.sell',
        'pos.sell.stock_minus',   // override stock 0/minus — super_user only
        'pos.sale.void',
        'pos.discount.manual',
        'pos.shift.manage',
        // Inventory
        'inventory.view',
        'inventory.adjustment',
        'inventory.transfer',
        'inventory.opname',
        // Purchasing
        'purchasing.manage',
        // Accounting
        'accounting.view',
        'accounting.journal.post',
        // Master data
        'master.manage',
        // Reports
        'reports.view',
        // Tenant settings
        'settings.tenant',
        'settings.users',
        'settings.roles',
        // Warehouse scope
        'warehouse.view_all',     // see/switch across warehouses (owner/manager)
    ];

    /**
     * role => list of permissions ('*' = all).
     */
    private array $roleMap = [
        'owner' => ['*'],
        'manager' => [
            'pos.access', 'pos.sell', 'pos.sale.void', 'pos.discount.manual', 'pos.shift.manage',
            'inventory.view', 'inventory.adjustment', 'inventory.transfer', 'inventory.opname',
            'purchasing.manage', 'accounting.view', 'accounting.journal.post',
            'master.manage', 'reports.view', 'settings.users', 'settings.roles',
            'warehouse.view_all',
            // NOTE: no 'settings.tenant'
        ],
        'supervisor' => [
            'pos.access', 'pos.sell', 'pos.sale.void', 'pos.discount.manual', 'pos.shift.manage',
            'inventory.view', 'inventory.adjustment', 'reports.view',
        ],
        'cashier' => [
            'pos.access', 'pos.sell', 'pos.shift.manage',
        ],
        'super_user' => [
            'pos.access', 'pos.sell', 'pos.sell.stock_minus', 'pos.sale.void',
            'pos.discount.manual', 'inventory.adjustment',
        ],
    ];

    public function run(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        foreach ($this->permissions as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        foreach ($this->roleMap as $roleName => $perms) {
            $role = Role::findOrCreate($roleName, 'web');
            if ($perms === ['*']) {
                $role->syncPermissions(Permission::all());
            } else {
                $role->syncPermissions($perms);
            }
        }

        Artisan::call('permission:cache-reset');
    }
}
