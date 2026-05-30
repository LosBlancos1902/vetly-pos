<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seed permission COA + Finance dan role `finance` ke TENANT DB aktif.
 * Idempotent + non-destruktif (findOrCreate + givePermissionTo, tak pernah mencabut).
 *
 * Untuk SEMUA tenant existing:  php artisan tenants:run finance:grant
 *
 * Sengaja TIDAK lewat DefaultRolesSeeder (syncPermissions bisa clobber
 * kustomisasi role runtime via /settings/roles).
 */
class FinanceGrant extends Command
{
    protected $signature = 'finance:grant';

    protected $description = 'Grant permission COA/Finance + role finance (idempotent) di tenant aktif';

    public function handle(): int
    {
        app()['cache']->forget('spatie.permission.cache');

        $newPerms = [
            'coa.view', 'coa.manage',
            'finance.view', 'finance.cash_bank.post', 'finance.cash_bank.approve',
        ];
        foreach ($newPerms as $p) {
            Permission::findOrCreate($p, 'web');
        }

        // Role finance: permission finance + akses akuntansi yang relevan.
        $financePerms = array_merge($newPerms, [
            'accounting.view', 'accounting.journal.post',
            'reports.financial.view', 'audit.view',
        ]);
        $finance = Role::findOrCreate('finance', 'web');
        foreach ($financePerms as $p) {
            Permission::findOrCreate($p, 'web');
            if (! $finance->hasPermissionTo($p)) {
                $finance->givePermissionTo($p);
            }
        }
        $this->info('role finance siap');

        // owner & manager dapat permission COA/Finance baru.
        foreach (['owner', 'manager'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                continue;
            }
            foreach ($newPerms as $p) {
                if (! $role->hasPermissionTo($p)) {
                    $role->givePermissionTo($p);
                }
            }
            $this->info("{$roleName} granted COA/Finance perms");
        }

        $this->call('permission:cache-reset');

        return self::SUCCESS;
    }
}
