<?php

namespace Tests\Feature\Inspection;

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `inspection:seed-demo` is the only thing allowed to plant fake inspection
 * clients, and it is not allowed to guess where.
 *
 * Tests elsewhere must not call this command (spec §13). This file is the
 * exception that proves the guards: a named organization, and a database whose
 * name contains `testing`. `APP_ENV=testing` is a line in `.env` and is not
 * enough — that is how the production schema was wiped on 2026-08-06.
 */
class InspectionSeedDemoTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_missing_organization_argument_fails(): void
    {
        $this->expectException(ConsoleRuntimeException::class);
        $this->expectExceptionMessage('organization');

        $this->artisan('inspection:seed-demo');
    }

    public function test_a_database_name_without_testing_is_refused_even_when_app_env_is_testing(): void
    {
        $this->assertSame('testing', $this->app->environment());

        $this->withConnectedDatabase('ezbizi_local', function () {
            $this->artisan('inspection:seed-demo', ['organization' => 'Alpha'])
                ->expectsOutputToContain('testing')
                ->assertFailed();
        });
    }

    public function test_a_database_named_ezbizi_is_refused(): void
    {
        $this->withConnectedDatabase('ezbizi', function () {
            $this->artisan('inspection:seed-demo', ['organization' => 'Alpha'])
                ->expectsOutputToContain('ezbizi')
                ->assertFailed();
        });
    }

    public function test_named_organization_plus_testing_database_seeds_ten_customers_and_ten_jobs(): void
    {
        $tenant = $this->provisionTenant('Inspection Demo');
        $other = $this->provisionTenant('Other');

        $this->artisan('inspection:seed-demo', ['organization' => 'Inspection Demo'])
            ->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(10, Customer::count());
            $this->assertSame(10, Job::count());

            foreach (Customer::all() as $customer) {
                $this->assertNotNull($customer->email);
                $this->assertStringEndsWith('@example.test', $customer->email);
            }
        });

        $this->inTenant($other['org'], function () {
            $this->assertSame(0, Customer::count());
            $this->assertSame(0, Job::count());
        });
    }

    public function test_an_unknown_organization_is_refused(): void
    {
        $this->artisan('inspection:seed-demo', ['organization' => 'Does Not Exist'])
            ->expectsOutputToContain('Does Not Exist')
            ->assertFailed();
    }

    /**
     * Point the command at a different schema name without moving RefreshDatabase.
     *
     * The 2026-08-06 wipe was `--env=testing` falling through to `.env`. These
     * tests have to lie about the connected name the same way, then put it back
     * before teardown tries to roll the transaction back against the lie.
     */
    private function withConnectedDatabase(string $name, callable $fn): void
    {
        $key = 'database.connections.'.config('database.default').'.database';
        $original = config($key);

        config([$key => $name]);

        try {
            $fn();
        } finally {
            config([$key => $original]);
        }
    }
}
