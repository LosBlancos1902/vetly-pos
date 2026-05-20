<?php

namespace Tests;

use App\Models\Tenant as TenantModel;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Auth;

/**
 * Base test case that initializes tenancy against a dedicated `test` tenant
 * (NOT `demo`). Tests freely delete stock_movements + reset inventory between
 * cases (see resetClinicState / resetServiceState), so they must NEVER run
 * against the developer-facing demo tenant. Use `demo` for manual UI testing
 * only; `test` is the disposable target for pest.
 *
 * We intentionally do NOT wrap each test in a DB transaction — the
 * concurrency tests rely on real committed state across connections, and a
 * surrounding RefreshDatabase / DatabaseTransactions trait would defeat that.
 * Tests are responsible for resetting whatever data they touch.
 *
 * The `test` tenant is auto-provisioned on first invocation: TenantModel::create
 * fires CreateDatabase + MigrateDatabase + SeedDatabase (baseline = units, COA,
 * roles); DemoSeeder is then run inside the tenant context to lay down the
 * demo products / recipes / bundles the test helpers expect (RAW-AMOX,
 * CPD-AMOXSIR, "Vaksin Rabies" bundle, etc.).
 */
abstract class TenantTestCase extends TestCase
{
    protected const TEST_TENANT_ID = 'test';

    protected function setUp(): void
    {
        parent::setUp();

        Auth::logout();

        $tenant = TenantModel::find(self::TEST_TENANT_ID);
        if (! $tenant) {
            $tenant = $this->provisionTestTenant();
        }

        tenancy()->initialize($tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    /**
     * Create the test tenant with baseline + demo data. Runs once per machine
     * (kept in MariaDB until manually dropped).
     */
    private function provisionTestTenant(): TenantModel
    {
        $tenant = TenantModel::create([
            'id' => self::TEST_TENANT_ID,
            'name' => self::TEST_TENANT_ID,
        ]);

        // Domain isn't strictly needed for pest (we never hit it via HTTP),
        // but stancl/tenancy expects every tenant to have at least one for
        // Inertia route generation if any feature test ever exercises it.
        $tenant->domains()->create(['domain' => self::TEST_TENANT_ID.'.vetly-pos.test']);

        $tenant->run(function () {
            (new DemoSeeder)->run();
        });

        return $tenant;
    }
}
