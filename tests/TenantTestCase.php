<?php

namespace Tests;

use App\Models\Tenant as TenantModel;

/**
 * Base test case that initializes tenancy against the existing `demo` tenant.
 *
 * We intentionally do NOT wrap each test in a DB transaction here — the
 * concurrency tests rely on real committed state across connections, and a
 * surrounding RefreshDatabase / DatabaseTransactions trait would defeat that.
 * Tests are responsible for resetting whatever data they touch.
 */
abstract class TenantTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = TenantModel::find('demo');
        if (! $tenant) {
            $this->markTestSkipped('Demo tenant not found — run `php artisan tenant:create demo` and seeders first.');
        }

        tenancy()->initialize($tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }
}
