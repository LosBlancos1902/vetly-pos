<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Grant permission `audit.view` ke role owner & manager pada TENANT DB aktif.
 * Idempotent + non-destruktif (cuma menambah, tidak pernah mencabut).
 *
 * Jalankan untuk SEMUA tenant existing:
 *   php artisan tenants:run audit:grant
 *
 * Sengaja TIDAK lewat DefaultRolesSeeder: seeder memakai syncPermissions yang
 * akan meng-CLOBBER kustomisasi role runtime (via /settings/roles). Command ini
 * hanya menambahkan satu permission baru, aman dijalankan kapan saja.
 */
class AuditGrant extends Command
{
    protected $signature = 'audit:grant';

    protected $description = 'Grant audit.view ke role owner & manager (idempotent) pada tenant aktif';

    public function handle(): int
    {
        app()['cache']->forget('spatie.permission.cache');

        $perm = Permission::findOrCreate('audit.view', 'web');

        foreach (['owner', 'manager'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($perm)) {
                $role->givePermissionTo($perm);
                $this->info("audit.view → {$roleName}");
            }
        }

        $this->call('permission:cache-reset');

        return self::SUCCESS;
    }
}
