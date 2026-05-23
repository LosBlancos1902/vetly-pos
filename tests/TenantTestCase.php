<?php

namespace Tests;

use App\Models\Tenant as TenantModel;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base test case that initializes tenancy against a dedicated `test` tenant
 * (NOT `demo`). Tests freely delete stock_movements + reset inventory between
 * cases (see resetClinicState / resetServiceState), so they must NEVER run
 * against the developer-facing demo tenant. Use `demo` for manual UI testing
 * only; `test` is the disposable target for pest.
 *
 * Central DB at test time is `vetly_pos_central_testing` (overridden in
 * phpunit.xml) — completely separate from the local `vetly_pos_central`,
 * so Feature tests' RefreshDatabase cannot wipe the demo tenant mapping.
 * Tenant DBs (vetly_pos_tenant_*) survive across runs and are re-attached
 * to the freshly-migrated central testing DB on each suite invocation.
 *
 * We intentionally do NOT wrap each test in a DB transaction — the
 * concurrency tests rely on real committed state across connections, and a
 * surrounding RefreshDatabase / DatabaseTransactions trait would defeat that.
 * Tests are responsible for resetting whatever data they touch.
 */
abstract class TenantTestCase extends TestCase
{
    protected const TEST_TENANT_ID = 'test';

    protected function setUp(): void
    {
        parent::setUp();

        Auth::logout();

        // Central testing DB may be empty if Tenant tests run in isolation
        // (e.g. --testsuite=Tenant). Migrate on demand; idempotent.
        if (! Schema::hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        $tenant = TenantModel::find(self::TEST_TENANT_ID);
        if (! $tenant) {
            $tenant = $this->provisionTestTenant();
        }

        tenancy()->initialize($tenant);

        // Tenant DB persist across runs (lihat docblock di atas), jadi schema
        // bisa ketinggalan kalau ada migration tenant baru. Jalankan tenants:migrate
        // tiap setUp — idempotent, Laravel skip migration yang sudah jalan.
        Artisan::call('tenants:migrate', [
            '--tenants' => [self::TEST_TENANT_ID],
            '--force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    /**
     * Either fully create the test tenant (first run on this machine) or
     * re-attach an orphaned tenant DB to the central mapping (every subsequent
     * suite invocation, because Feature tests' RefreshDatabase wipes the
     * central testing DB but the tenant DB persists).
     *
     * The re-attach path bypasses Tenant::create() so we don't fire the
     * CreateDatabase listener that would throw TenantDatabaseAlreadyExistsException.
     */
    private function provisionTestTenant(): TenantModel
    {
        $tenantDbName = config('tenancy.database.prefix').self::TEST_TENANT_ID.config('tenancy.database.suffix');
        $domain = self::TEST_TENANT_ID.'.vetly-pos.test';

        // SHOW DATABASES LIKE doesn't accept bound parameters in MariaDB;
        // inline is safe because $tenantDbName is derived from a constant + config.
        $dbAlreadyExists = ! empty(DB::select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$tenantDbName]
        ));

        if ($dbAlreadyExists) {
            $now = now();

            DB::table('tenants')->insert([
                'id' => self::TEST_TENANT_ID,
                'data' => json_encode([
                    'name' => self::TEST_TENANT_ID,
                    'tenancy_db_name' => $tenantDbName,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('domains')->insert([
                'domain' => $domain,
                'tenant_id' => self::TEST_TENANT_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return TenantModel::find(self::TEST_TENANT_ID);
        }

        // Fresh path: CreateDatabase + MigrateDatabase + SeedDatabase fire via events.
        $tenant = TenantModel::create([
            'id' => self::TEST_TENANT_ID,
            'name' => self::TEST_TENANT_ID,
        ]);

        $tenant->domains()->create(['domain' => $domain]);

        $tenant->run(function () {
            (new DemoSeeder)->run();
        });

        return $tenant;
    }
}
