<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Ten fake customers and ten jobs so Inspection has something to search.
 *
 * ── Why both a name and a testing database ───────────────────────
 *
 * A default organization is how this would hit production. `APP_ENV=testing` is
 * how it already did: on 2026-08-06 `php artisan db:wipe --env=testing --force`
 * fell through to `.env` because there was no `.env.testing`, and `.env` names
 * the live schema. The thing that actually matters is the connected database's
 * own name containing `testing` — the same marker `tests/TestCase.php` uses —
 * plus an organization you typed, so nothing can silently pick the live tenant.
 *
 * A database named `ezbizi` is refused even if someone later adds `testing` to
 * `APP_ENV`. TODO #9 (what a demo organization suppresses) is still open: fakes
 * in the live org would show up in dashboards and roll-ups.
 */
class InspectionSeedDemo extends Command
{
    protected $signature = 'inspection:seed-demo {organization : The organization name to seed}';

    protected $description = 'Plant 10 fake customers and 10 jobs for Inspection demos — never on production';

    public function handle(TenantContext $tenant): int
    {
        $database = $this->connectedDatabaseName();

        if ($database === 'ezbizi' || ! str_contains($database, 'testing')) {
            $this->error(sprintf(
                "Refusing to seed against '%s'. The database name must contain 'testing'.",
                $database,
            ));

            return self::FAILURE;
        }

        $name = (string) $this->argument('organization');
        $organization = Organization::query()->where('name', $name)->first();

        if ($organization === null) {
            $this->error("No organization named {$name}.");

            return self::FAILURE;
        }

        $seeded = $tenant->run($organization, function () {
            $franchise = Franchise::query()->first();

            if ($franchise === null) {
                $this->error('That organization has no franchise to own the records.');

                return false;
            }

            $customers = Customer::factory()->count(10)->create([
                'owning_franchise_id' => $franchise->id,
            ]);

            foreach ($customers as $customer) {
                Job::factory()->create([
                    'franchise_id' => $franchise->id,
                    'customer_id' => $customer->id,
                ]);
            }

            return true;
        });

        if ($seeded !== true) {
            return self::FAILURE;
        }

        $this->info('Seeded 10 customers and 10 jobs.');

        return self::SUCCESS;
    }

    private function connectedDatabaseName(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }
}
