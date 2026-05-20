<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create {name : Tenant slug, e.g. demo}
                            {--domain= : Full domain (default {name}.vetly-pos.test)}
                            {--demo : Also seed demo data (warehouse, products, owner user)}';

    protected $description = 'Create a tenant, its database (auto-migrated + baseline-seeded) and domain.';

    public function handle(): int
    {
        $name = Str::slug($this->argument('name'));
        $domain = $this->option('domain') ?: "{$name}.vetly-pos.test";

        if (Tenant::find($name)) {
            $this->error("Tenant '{$name}' already exists.");

            return self::FAILURE;
        }

        $this->info("Creating tenant '{$name}' ...");
        // TenantCreated => CreateDatabase + MigrateDatabase + SeedDatabase (baseline).
        $tenant = Tenant::create(['id' => $name, 'name' => $name]);
        $tenant->domains()->create(['domain' => $domain]);
        $this->info("✔ DB vetly_pos_tenant_{$name} created, migrated & baseline-seeded.");

        if ($this->option('demo')) {
            $this->info('Seeding demo data ...');
            $tenant->run(function () {
                (new DemoSeeder())->run();
            });
            $this->info('✔ Demo data seeded (Toko Demo, 5 produk, owner@vetly.id).');
        }

        $this->newLine();
        $this->line("  URL    : http://{$domain}");
        $this->line('  Login  : owner@vetly.id / demo123');

        return self::SUCCESS;
    }
}
