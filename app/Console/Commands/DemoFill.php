<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleServiceVisit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fill the demo tenant so its screens have something on them.
 *
 * ── Why this is not another `*:seed-demo` ────────────────────────
 *
 * The four existing seeders all open with the same guard:
 *
 *     if ($database === 'ezbizi' || ! str_contains($database, 'testing')) { refuse }
 *
 * and they are right to. That line was added after `db:wipe --env=testing
 * --force` fell through to `.env` on 2026-08-06 and named the live schema.
 *
 * But the demo tenant is ON the production database and always will be, because
 * the screenshot run signs into https://app.ezbizi.com. So that guard can never
 * be satisfied here, and the wrong answer is to relax it — the guard protects
 * against a command that can address the WHOLE database, which is what those
 * seeders are.
 *
 * This is a different risk class and is built as one. It cannot address the
 * whole database: it refuses to run against any organization but the demo one,
 * matched on BOTH its id and its name, so a renamed organization or a reused id
 * stops it rather than a typo reaching a customer. It only ever inserts. There
 * is no truncate, no delete, no update of an existing row anywhere below, so the
 * worst a bug here can do is add rows to a tenant that exists to hold invented
 * ones. Running it twice is a no-op rather than doubles, on any day — where a
 * row has a real natural key (an area's name, a zip, a weekday slot, a
 * vehicle's one open assignment) that key does it; where it has none, the guard
 * is whether the parent already has demo history, because the alternative keys
 * are dates derived from now() and those stop matching tomorrow.
 *
 * ── What it deliberately does not fill ──────────────────────────
 *
 * Phone, Web and Email are absent on purpose. A PhoneNumber row is the app's
 * handle on a real Twilio number, and WebSite and Mailbox are live connections;
 * inventing those gives a demo that dials, syncs or bills against something
 * nobody owns. Jose's call on 2026-09-04 was to drop the Communication screen
 * rather than park a number here.
 *
 * Contracts, Onboarding and Training are absent for a duller reason:
 * `contract_versions.fields` and `training_guides.screens` are json columns
 * whose shape is decided by their modules, and inventing a schema I have not
 * read produces rows that render wrong rather than rows that are missing. A
 * missing section is honest; a malformed one is a bug report. Whoever owns HR
 * should add those.
 *
 * Inspection is absent because the Inspection session owns it (docs/SESSIONS.md)
 * and is doing its own slice.
 *
 * See docs/demo-tenant-gaps.md for the measured counts this works from.
 */
class DemoFill extends Command
{
    protected $signature = 'demo:fill {--dry-run : Say what would be written and write nothing}';

    protected $description = 'Give the demo tenant enough data that its screens are not empty';

    /**
     * Three conditions, all of which must hold. Being exact about what each one
     * buys, because a comment that flatters the guard is worse than none.
     *
     * The ID IS THE LOOKUP, not a check — handle() finds by it, so this command
     * addresses one row of one table and can address no other. That is the whole
     * safety property. It also means a SECOND demo tenant, however honestly it
     * set is_demo, is not served here at all; it would need this constant
     * changed. That is a limitation, not an oversight, and it is the right way
     * round for something that writes to the production database.
     *
     * `is_demo` and the name are refusals stacked on top, for the case where
     * that one id stops meaning what it means today — recycled onto something
     * real, or the demo organization renamed into a customer. is_demo is the
     * column the schema provides for this exact question
     * (create_foundation_tables, set by ProvisionOrganization) and production
     * has it true on EzBizi Demo and false on A1-Group; the name catches a flag
     * somebody set on a real tenant to try something out.
     *
     * Measured, not copied: both ids were read off production directly on
     * 2026-09-04. That matters here in a way it would not in a purely
     * fail-closed gate — this one PROCEEDS on a match, so a wrong constant
     * could have pointed a write at the wrong tenant rather than merely
     * refusing. Re-measure before trusting it, per docs/demo-tenant-gaps.md.
     */
    private const DEMO_ID = '01a03e4f-f9a1-7012-9515-96bc9260d2f8';

    private const DEMO_NAME = 'EzBizi Demo';

    public function handle(TenantContext $tenant): int
    {
        $organization = Organization::query()->find(self::DEMO_ID);

        if ($organization === null
            || ! $organization->is_demo
            || $organization->name !== self::DEMO_NAME) {
            $this->error(sprintf(
                'Refusing to run. Expected the organization %s, named "%s", with is_demo set; found %s.',
                self::DEMO_ID,
                self::DEMO_NAME,
                $organization === null
                    ? 'nothing'
                    : sprintf('"%s" with is_demo %s', $organization->name, $organization->is_demo ? 'set' : 'UNSET'),
            ));

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $this->line(sprintf('%s %s', $dry ? 'Would fill' : 'Filling', $organization->name));

        $tenant->run($organization, function () use ($dry) {
            $this->scheduling($dry);
            $this->fleet($dry);
            $this->timeClock($dry);
        });

        return self::SUCCESS;
    }

    /**
     * Scheduling shows "Search a zip code or an area name to see its week" and
     * nothing else until an area exists, because there is nothing to search.
     */
    private function scheduling(bool $dry): void
    {
        $franchise = Franchise::query()->orderBy('created_at')->first();

        if ($franchise === null) {
            $this->warn('  scheduling: no franchise to own an area, skipped');

            return;
        }

        // Zips are the ones already used by the demo customers' cities, so a
        // search for an area finds work that exists rather than an empty week.
        $areas = [
            'Orlando — north' => ['32801', '32803', '32804', '32806'],
            'Miami — coastal' => ['33101', '33125', '33127'],
            'Fort Lauderdale' => ['33301', '33304', '33308'],
        ];

        foreach ($areas as $name => $zips) {
            if ($dry) {
                $this->line(sprintf('  scheduling: would add "%s" (%d zips, Mon-Fri)', $name, count($zips)));

                continue;
            }

            $area = ServiceArea::query()->firstOrCreate(
                ['name' => $name],
                ['franchise_id' => $franchise->id],
            );

            foreach ($zips as $zip) {
                ServiceAreaZip::query()->firstOrCreate([
                    'service_area_id' => $area->id,
                    'zip' => $zip,
                ], [
                    'franchise_id' => $franchise->id,
                ]);
            }

            // Monday to Friday. weekday is 0-6 (SchedulingAreasController:166
            // validates between:0,6), so 1..5 is the working week.
            foreach (range(1, 5) as $weekday) {
                ServiceAreaSlot::query()->firstOrCreate([
                    'service_area_id' => $area->id,
                    'weekday' => $weekday,
                    'start_time' => '08:00:00',
                ], [
                    'end_time' => '17:00:00',
                    'max_jobs' => 6,
                ]);
            }

            $this->info(sprintf('  scheduling: "%s" — %d zips, Mon-Fri 08:00-17:00', $name, count($zips)));
        }
    }

    /**
     * Five real vehicles all captioned "Nobody has the keys", because there is
     * not one row in vehicle_assignments.
     */
    private function fleet(bool $dry): void
    {
        $vehicles = Vehicle::query()->orderBy('number')->get();
        $drivers = User::query()->where('is_active', true)->orderBy('created_at')->get();

        if ($vehicles->isEmpty() || $drivers->isEmpty()) {
            $this->warn('  fleet: no vehicles or no active users, skipped');

            return;
        }

        foreach ($vehicles as $i => $vehicle) {
            $driver = $drivers[$i % $drivers->count()];

            if ($dry) {
                $this->line(sprintf('  fleet: would give %s to %s', $vehicle->number, $driver->name));

                continue;
            }

            // `open` is what makes it the current holder rather than history, so
            // firstOrCreate keys on it: a second run finds the open row and adds
            // nothing.
            VehicleAssignment::query()->firstOrCreate([
                'vehicle_id' => $vehicle->id,
                'open' => 1,
            ], [
                'user_id' => $driver->id,
                'assigned_at' => Carbon::now()->subDays(30 + $i),
                'note' => 'Assigned for demonstration data.',
            ]);

            // NOT firstOrCreate on the date. `occurred_on` is derived from
            // now(), so the key it forms is different tomorrow: a second run on
            // a later day would match nothing and add a second visit, and a
            // third would add a third. The idempotency would have looked real
            // in any test that runs both passes in the same second, which is
            // every test that does not travel.
            //
            // There is no natural key here — a vehicle may legibly have two
            // services on one day — so the honest question is not "does THIS
            // visit exist" but "has this vehicle been given demo history yet".
            $hasHistory = VehicleServiceVisit::query()
                ->where('vehicle_id', $vehicle->id)
                ->exists();

            $hasHistory || VehicleServiceVisit::query()->create([
                'vehicle_id' => $vehicle->id,
                'occurred_on' => Carbon::now()->subMonths(2)->toDateString(),
                // VehicleServiceKind has no generic 'service' case — oil, tires,
                // brakes, repair, inspection, registration, other.
                'kind' => 'oil',
                'odometer_miles' => 42000 + ($i * 3100),
                'performed_by' => 'Ridgeline Fleet Services',
                'notes' => 'Oil and filters, with a brake check while it was up.',
                'next_service_on' => Carbon::now()->addMonths(4)->toDateString(),
            ]);

            $this->info(sprintf('  fleet: %s -> %s, one service visit', $vehicle->number, $driver->name));
        }
    }

    /**
     * The HR panel reads "0 people joined this month" and so on down the list.
     * Shifts are the one line here whose shape is plain columns rather than a
     * json schema owned by another module.
     */
    private function timeClock(bool $dry): void
    {
        $staff = User::query()->where('is_active', true)->orderBy('created_at')->take(4)->get();

        if ($staff->isEmpty()) {
            $this->warn('  time clock: no active users, skipped');

            return;
        }

        foreach ($staff as $i => $person) {
            // Same trap as the service visits, same answer: clocked_in_at is
            // derived from now(), so keying on it makes a run on a later day
            // add a second pair of shifts rather than find the first. A person
            // has no natural key for "the demo shift" — two shifts on one day
            // is ordinary — so the question is whether this person has any
            // history at all.
            $hasShifts = TimeClockShift::query()
                ->where('user_id', $person->id)
                ->exists();

            // Two closed shifts each over the last week, and the first two
            // people still on the clock today, so the screen shows both states.
            foreach ([3, 2] as $daysAgo) {
                $in = Carbon::now()->subDays($daysAgo)->setTime(8, 0);

                if ($dry) {
                    $this->line(sprintf('  time clock: would add %s on %s', $person->name, $in->toDateString()));

                    continue;
                }

                if ($hasShifts) {
                    continue;
                }

                TimeClockShift::query()->create([
                    'user_id' => $person->id,
                    'clocked_in_at' => $in,
                    'clocked_out_at' => $in->copy()->setTime(16, 30),
                ]);
            }

            if ($i < 2 && ! $dry) {
                // open_slot is what keeps "one open shift per person" unique, so
                // an open row is keyed on it rather than on a time.
                TimeClockShift::query()->firstOrCreate([
                    'user_id' => $person->id,
                    'open_slot' => 1,
                ], [
                    'clocked_in_at' => Carbon::now()->setTime(8, 0),
                    'clocked_out_at' => null,
                ]);
            }

            if (! $dry) {
                $this->info(sprintf('  time clock: %s — 2 closed%s', $person->name, $i < 2 ? ', 1 open' : ''));
            }
        }
    }
}
