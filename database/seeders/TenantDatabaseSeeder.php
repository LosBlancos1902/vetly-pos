<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Baseline data seeded into EVERY tenant database
 * (units, chart of accounts, roles & permissions).
 *
 * Wired as the tenancy seeder via config/tenancy.php -> seeder_parameters.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UnitSeeder::class,
            CoaSeeder::class,
            DefaultRolesSeeder::class,
        ]);
    }
}
