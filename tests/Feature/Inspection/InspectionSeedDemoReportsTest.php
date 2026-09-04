<?php

namespace Tests\Feature\Inspection;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The demo-report seeder writes where it is allowed to and nowhere else.
 *
 * This command exists to write to the PRODUCTION database — that is the whole
 * point of it, since the demonstration tenant lives there — so the two things
 * worth pinning are the two that cannot be checked by reading the output: that
 * it cannot reach production unasked, and that the only tenant it can reach is
 * the demo one.
 *
 * The second is not a hypothetical. The authorisation was "the demo
 * organization, never A1-Group", and A1-Group's rows on that same database are
 * real customers with real addresses.
 */
class InspectionSeedDemoReportsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * The production guard answers before anything is read.
     *
     * Asserted with no demo organization in the database at all: if the guard
     * ever moved below the lookup, this would fail with "No organization
     * named…" instead, and the difference between those two failures is the
     * difference between a refusal and a near miss.
     */
    public function test_it_refuses_production_unless_asked(): void
    {
        config(['database.connections.'.config('database.default').'.database' => 'ezbizi']);

        $this->artisan('inspection:seed-demo-reports', ['--force' => true])
            ->expectsOutputToContain('Refusing to touch production without --live.')
            ->assertExitCode(1);
    }

    /**
     * On production, the right NAME is not enough — the id has to agree.
     *
     * The case this refuses is a second organization called "EzBizi Demo",
     * which is not exotic: the name is editable from Settings and is not
     * unique across the platform. Reached by faking the database name and
     * passing --live, so the run gets past the first guard and is stopped by
     * the second one specifically.
     *
     * The fixture tenant has its own id and therefore cannot match the
     * production constant, which is the whole point — the assertion is that
     * writing is refused, and the zero count is what proves it refused rather
     * than merely complained.
     */
    public function test_on_production_a_matching_name_with_the_wrong_id_is_refused(): void
    {
        $impostor = $this->seedableTenant('EzBizi Demo');

        config(['database.connections.'.config('database.default').'.database' => 'ezbizi']);

        // The exit code comes back from run() and is asserted AFTER the rows.
        // Asserting it inside the chain throws first, so a guard that refused
        // correctly and wrote anyway would look identical to one that worked —
        // the Apple session hit exactly that in their own refusal tests.
        $status = $this->artisan('inspection:seed-demo-reports', ['--live' => true, '--force' => true])->run();

        $this->assertSame(0, $this->reportCount($impostor), 'nothing may be written on an id mismatch');
        $this->assertSame(1, $status, 'an id mismatch must exit non-zero');
    }

    /**
     * The flag is the question that actually matters.
     *
     * `organizations.is_demo` is the first-class marker for "this tenant is a
     * demonstration"; the name and the id are stand-ins that both this command
     * and the Apple session's had invented before either of us noticed the
     * column. A tenant carrying the right name and the wrong flag is refused,
     * on every database rather than only on production.
     */
    public function test_a_tenant_that_says_it_is_not_a_demo_is_refused(): void
    {
        $notReallyDemo = $this->seedableTenant('EzBizi Demo', isDemo: false);

        $status = $this->artisan('inspection:seed-demo-reports', ['--force' => true])->run();

        $this->assertSame(0, $this->reportCount($notReallyDemo), 'nothing may be written to a non-demo tenant');
        $this->assertSame(1, $status, 'a non-demo tenant must exit non-zero');
    }

    /**
     * It files on the demo tenant, and A1-Group is untouched beside it.
     *
     * Both organizations are equally SUBMITTABLE — same published definition,
     * same jobs with customers — so a fixture where A1-Group simply had no
     * jobs would pass against a command that targeted it, and this one does
     * not.
     *
     * What separates them is the guard stack rather than any one gate: the
     * name it is pinned to, and `is_demo`, which A1-Group honestly does not
     * carry. Retargeting the name alone is caught by the flag, and that is the
     * defence working rather than the test being loose — the assertion below is
     * that A1-Group holds no rows, however the command was pointed at it.
     */
    public function test_it_files_on_the_demo_tenant_and_never_on_a1_group(): void
    {
        $demo = $this->seedableTenant('EzBizi Demo');
        $a1 = $this->seedableTenant('A1-Group', isDemo: false);

        // Deliberately NOT asserting the "Filed 3 reports on EzBizi Demo"
        // line. It is the first thing to change if the command is retargeted,
        // so a test that checks it fails on the message and stops — leaving
        // the row counts below, which are the actual property, unexercised.
        // Proven: pointing ORG_NAME at A1-Group must fail on the count.
        $this->artisan('inspection:seed-demo-reports', ['--force' => true])->assertExitCode(0);

        // A1-Group first, so a retargeted command reports itself as "A1-Group
        // must be untouched" rather than as an arithmetic mismatch.
        $this->assertSame(0, $this->reportCount($a1), 'A1-Group must be untouched');
        $this->assertSame(3, $this->reportCount($demo), 'the demo tenant should have three reports');
    }

    /**
     * Running it twice adds nothing, including on a later day.
     *
     * The clock moves between the runs, and that is not decoration. The Apple
     * session found that their seeder was idempotent only within a single
     * second: two of their rows had no natural key and one had been invented
     * out of `now()->subMonths(2)`, which forms a DIFFERENT key tomorrow, so a
     * second run on a later day appended a whole new set. Their test could not
     * see it because both runs happened in the same second and the dates
     * agreed — a test asserting a property it could not have observed the
     * absence of, which is the third instance of that shape found today.
     *
     * This command's early return asks `exists()` and has no date in it, so it
     * is time-independent by construction rather than by luck. The travel is
     * here so that stays true: `submitted_at` IS computed from now(), and the
     * day somebody turns that into a lookup key, this fails.
     */
    public function test_a_second_run_adds_nothing_even_on_a_later_day(): void
    {
        $demo = $this->seedableTenant('EzBizi Demo');

        $this->artisan('inspection:seed-demo-reports', ['--force' => true])->assertExitCode(0);

        $this->travel(8)->days();

        // Rows before status, and no output expectation at all — the third
        // time in this file that a chained assertion masked the real one. A
        // date-dependent early return makes the second run FILE AGAIN, and
        // "already has reports" is the first thing that stops being printed,
        // so a test checking the message fails on the message and never counts.
        $status = $this->artisan('inspection:seed-demo-reports', ['--force' => true])->run();

        $this->assertSame(3, $this->reportCount($demo), 'a run on a later day must still be a no-op');
        $this->assertSame(0, $status, 'a no-op re-run still succeeds');
    }

    /** Every answer the published version asks for is filled in. */
    public function test_the_reports_it_files_answer_every_question(): void
    {
        $demo = $this->seedableTenant('EzBizi Demo');

        $this->artisan('inspection:seed-demo-reports', ['--force' => true])->assertExitCode(0);

        $answers = $this->inTenant($demo, fn () => InspectionReport::query()->first()->answers);

        $this->assertSame(['crown', 'liner', 'notes'], array_keys($answers));

        foreach ($answers as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "{$key} was left blank");
        }
    }

    // ── fixtures ─────────────────────────────────────────────────

    /**
     * An organization the command could file against, if it were allowed to.
     *
     * `is_demo` is a real column set by `ProvisionOrganization`, and the
     * command now gates on it, so the fixture has to carry it honestly: the
     * demo tenant says it is one, A1-Group says it is not.
     */
    private function seedableTenant(string $name, bool $isDemo = true): Organization
    {
        $tenant = $this->provisionTenant($name);
        $tenant['org']->forceFill(['is_demo' => $isDemo])->save();

        $screens = [
            ['name' => 'Exterior', 'fields' => [['key' => 'crown', 'label' => 'Crown condition', 'type' => 'text']]],
            ['name' => 'Flue', 'fields' => [['key' => 'liner', 'label' => 'Liner condition', 'type' => 'text']]],
            ['name' => 'Findings', 'fields' => [['key' => 'notes', 'label' => 'Notes', 'type' => 'text']]],
        ];

        $this->inTenant($tenant['org'], function () use ($tenant, $screens) {
            $definition = InspectionDefinition::create([
                'name' => 'Level 1 Chimney Inspection',
                'layout_preset' => 'step_strip',
                'screens' => $screens,
                'created_by' => $tenant['admin']->id,
            ]);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version' => 1,
                'layout_preset' => 'step_strip',
                'screens' => $screens,
                'published_by' => $tenant['admin']->id,
            ]);

            $definition->forceFill([
                'current_version_id' => $version->id,
                'published_at' => now(),
            ])->save();

            // Four, so taking three is a real limit rather than everything
            // there happens to be.
            foreach (range(1, 4) as $n) {
                $customer = Customer::create([
                    'owning_franchise_id' => $tenant['franchise']->id,
                    'number' => 'C-'.bin2hex(random_bytes(3)),
                    'name' => $n.' Maple Street',
                ]);

                Job::create([
                    'franchise_id' => $tenant['franchise']->id,
                    'customer_id' => $customer->id,
                    'number' => 'J-'.bin2hex(random_bytes(3)),
                    'title' => 'Annual inspection',
                    'status' => 'scheduled',
                ]);
            }
        });

        return $tenant['org'];
    }

    private function reportCount(Organization $organization): int
    {
        return $this->inTenant($organization, fn () => InspectionReport::query()->count());
    }
}
