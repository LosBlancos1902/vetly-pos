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
        'customer.manage',  // CRUD customer + quick-create dari POS
                            // (cashier dapet by default, owner override)
        'promo.manage',     // CRUD promo (owner/manager only by default)
        // Reports — split per kategori (Batch A laporan)
        'reports.financial.view',   // P&L, Neraca, Buku Besar, TB, Jurnal, Kas/Bank — owner/manager
        'reports.sales.view',       // Penjualan multi-dim, margin — manager+supervisor
        'reports.purchasing.view',  // Pembelian per supplier/produk, AP aging — manager+supervisor
        'reports.inventory.view',   // Nilai stok, min stok, mutasi, shift kasir — manager+supervisor
        // Tenant settings
        'settings.tenant',
        'settings.users',
        'settings.roles',
        // Audit / Riwayat Aktivitas (lihat log perubahan master & settings)
        'audit.view',
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
            'master.manage', 'master.compounds', 'master.services', 'customer.manage',
            'promo.manage',
            'pharmacy.compound',
            'reports.financial.view', 'reports.sales.view',
            'reports.purchasing.view', 'reports.inventory.view',
            'settings.users', 'settings.roles',
            'audit.view',
            'warehouse.view_all',
            // NOTE: no 'settings.tenant'
        ],
        'apoteker' => [
            // Klinik staff — racik & jual obat racikan + tindakan.
            // Permission yang dipakai sehari-hari oleh seorang apoteker:
            'pos.access', 'pos.sell', 'pos.shift.manage',
            'master.compounds', 'master.services', 'customer.manage',
            'pharmacy.compound',
            'inventory.view',
            'purchasing.pr_create',
        ],
        'supervisor' => [
            'pos.access', 'pos.sell', 'pos.sale.void', 'pos.discount.manual', 'pos.shift.manage',
            'inventory.view', 'inventory.adjustment',
            'reports.sales.view', 'reports.purchasing.view', 'reports.inventory.view',
            'purchasing.pr_create', 'customer.manage',
        ],
        'cashier' => [
            'pos.access', 'pos.sell', 'pos.shift.manage',
            'purchasing.pr_create',
            // Quick-create customer dari POS saat transaksi — common workflow.
            'customer.manage',
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
