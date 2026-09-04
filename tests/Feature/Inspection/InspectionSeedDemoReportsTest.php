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

        $this->artisan('inspection:seed-demo-reports', ['--live' => true, '--force' => true])
            ->expectsOutputToContain('id this command does not know')
            ->assertExitCode(1);

        $this->assertSame(0, $this->reportCount($impostor), 'nothing may be written on an id mismatch');
    }

    /**
     * It files on the demo tenant, and A1-Group is untouched beside it.
     *
     * Both organizations are built the same way and both are equally
     * submittable — same published definition, same jobs with customers — so
     * the only thing separating them is the name the command is pinned to. A
     * fixture where A1-Group had no jobs would pass against a command that
     * targeted it.
     */
    public function test_it_files_on_the_demo_tenant_and_never_on_a1_group(): void
    {
        $demo = $this->seedableTenant('EzBizi Demo');
        $a1 = $this->seedableTenant('A1-Group');

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

    /** Running it twice adds nothing — it is safe to re-run against a live tenant. */
    public function test_a_second_run_adds_nothing(): void
    {
        $demo = $this->seedableTenant('EzBizi Demo');

        $this->artisan('inspection:seed-demo-reports', ['--force' => true])->assertExitCode(0);

        $this->artisan('inspection:seed-demo-reports', ['--force' => true])
            ->expectsOutputToContain('already has reports')
            ->assertExitCode(0);

        $this->assertSame(3, $this->reportCount($demo), 'the second run must not add a fourth');
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

    /** An organization the command could file against, if it were allowed to. */
    private function seedableTenant(string $name): Organization
    {
        $tenant = $this->provisionTenant($name);

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
