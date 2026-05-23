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
        'purchasing.supplier_manage',
        'purchasing.pr_create',
        'purchasing.pr_approve',
        // PO + Receiving + AP permissions: owner-only by default. Owner
        // assigns to other roles at runtime via /settings/roles.
        'purchasing.po_create',
        'purchasing.po_approve',
        'purchasing.receive',
        'purchasing.ap_view',
        'purchasing.ap_pay',
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
        // Clinic / apoteker
        'master.compounds',       // CRUD compound recipes
        'master.services',        // CRUD service bundles
        'pharmacy.compound',      // execute racikan
    ];

    /**
     * role => list of permissions ('*' = all).
     */
    private array $roleMap = [
        'owner' => ['*'],
        'manager' => [
            'pos.access', 'pos.sell', 'pos.sale.void', 'pos.discount.manual', 'pos.shift.manage',
            // NOTE: inventory.opname dipindahkan ke owner-only (financial action,
            // post jurnal HPP). Owner assign manual via /settings/roles.
            'inventory.view', 'inventory.adjustment', 'inventory.transfer',
            'purchasing.manage', 'purchasing.supplier_manage',
            'purchasing.pr_create', 'purchasing.pr_approve',
            'accounting.view', 'accounting.journal.post',
            'master.manage', 'master.compounds', 'master.services',
            'pharmacy.compound',
            'reports.view', 'settings.users', 'settings.roles',
            'warehouse.view_all',
            // NOTE: no 'settings.tenant'
        ],
        'apoteker' => [
            // Klinik staff — racik & jual obat racikan + tindakan.
            // Permission yang dipakai sehari-hari oleh seorang apoteker:
            'pos.access', 'pos.sell', 'pos.shift.manage',
            'master.compounds', 'master.services',
            'pharmacy.compound',
            'inventory.view',
            'purchasing.pr_create',
        ],
        'supervisor' => [
            'pos.access', 'pos.sell', 'pos.sale.void', 'pos.discount.manual', 'pos.shift.manage',
            'inventory.view', 'inventory.adjustment', 'reports.view',
            'purchasing.pr_create',
        ],
        'cashier' => [
            'pos.access', 'pos.sell', 'pos.shift.manage',
            'purchasing.pr_create',
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
