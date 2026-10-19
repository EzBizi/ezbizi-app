<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\InspectionRun;
use App\Models\Job;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\InspectionGlance;
use App\Support\Inspection\ScopeKey;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Inspection dashboard glance (named bars + submitted-this-period sentences).
 *
 * Snapshot bars are named inspections in reach (in-progress runs plus live
 * reports). Status counts and submitted-in-period ride as sentences.
 */
class InspectionGlanceTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table populated by the module registry, not
        // by provisioning an organization — so a test that builds a non-admin
        // role has to make sure the rows exist first.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    /** A second franchise with its own user, reaching only that franchise. */
    private function secondBranch(): array
    {
        return $this->inTenant(function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id'      => $this->alpha['company']->id,
                'name' => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
                'name' => 'Branch', 'email' => 'branch@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    /** @return array{definition: InspectionDefinition, version: InspectionDefinitionVersion} */
    private function publishedDefinition(): array
    {
        return $this->inTenant(function () {
            $existing = InspectionDefinition::query()->where('name', 'Level 1')->first();
            if ($existing !== null) {
                return [
                    'definition' => $existing,
                    'version' => InspectionDefinitionVersion::query()
                        ->where('inspection_definition_id', $existing->id)
                        ->firstOrFail(),
                ];
            }

            $definition = InspectionDefinition::create([
                'name' => 'Level 1',
                'created_by' => $this->alpha['admin']->id,
                'published_at' => now(),
            ]);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version' => 1,
                'layout_preset' => 'step_strip',
                'screens' => [],
                'published_by' => $this->alpha['admin']->id,
            ]);

            $definition->update(['current_version_id' => $version->id]);

            return ['definition' => $definition->fresh(), 'version' => $version];
        });
    }

    private function customer(Franchise $franchise): Customer
    {
        return $this->inTenant(fn () => Customer::create([
            'owning_franchise_id' => $franchise->id,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => '14 Maple',
        ]));
    }

    private function job(Franchise $franchise, Customer $customer): Job
    {
        return $this->inTenant(fn () => Job::create([
            'franchise_id' => $franchise->id,
            'customer_id' => $customer->id,
            'number' => 'J-'.bin2hex(random_bytes(3)),
            'title' => 'Work',
            'status' => 'scheduled',
            'currency' => 'USD',
        ]));
    }

    private function runOn(Franchise $franchise): InspectionRun
    {
        $seeded = $this->publishedDefinition();
        $customer = $this->customer($franchise);
        $job = $this->job($franchise, $customer);

        return $this->inTenant(fn () => InspectionRun::create([
            'user_id' => $this->alpha['admin']->id,
            'customer_id' => $customer->id,
            'job_id' => $job->id,
            'inspection_definition_id' => $seeded['definition']->id,
            'inspection_definition_version_id' => $seeded['version']->id,
            'scope_key' => ScopeKey::for($customer->id, $job->id),
            'answers' => [],
        ]));
    }

    /** Same reach as runOn; insert style copied from InspectionReportsTest. */
    private function reportOn(Franchise $franchise, ?Carbon $submittedAt = null): InspectionReport
    {
        $seeded = $this->publishedDefinition();
        $customer = $this->customer($franchise);
        $job = $this->job($franchise, $customer);

        return $this->inTenant(fn () => InspectionReport::create([
            'customer_id' => $customer->id,
            'job_id' => $job->id,
            'inspection_definition_id' => $seeded['definition']->id,
            'inspection_definition_version_id' => $seeded['version']->id,
            'submitted_by' => $this->alpha['admin']->id,
            'submitted_at' => $submittedAt ?? now(),
            'answers' => ['crown' => 'ok'],
            'layout_preset' => 'step_strip',
            'definition_name' => $seeded['definition']->name,
        ]));
    }

    public function test_a_branch_user_sees_only_runs_in_their_franchises(): void
    {
        [$second, $branch] = $this->secondBranch();
        $this->runOn($this->alpha['franchise']);
        $this->runOn($this->alpha['franchise']);
        $this->runOn($second);

        $payload = app(InspectionGlance::class)->resolve($branch, DashboardPeriod::for($branch), [])->toArray();
        $slices = collect($payload['data']['slices']);

        $this->assertNull($slices->firstWhere('label', 'In progress'));
        $this->assertSame(1, (int) $slices->sum('total'));
        $this->assertSame(1, $slices->firstWhere('label', 'Level 1')['total'] ?? null);
    }

    public function test_zero_inspections_is_a_sentence_not_slices(): void
    {
        $payload = app(InspectionGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray();

        $this->assertTrue($payload['data']['empty']);
        $this->assertSame('No inspections to show.', $payload['data']['sentence']);
    }

    public function test_the_named_bars_ignore_the_dashboard_period(): void
    {
        $this->runOn($this->alpha['franchise']);
        $this->reportOn($this->alpha['franchise']);

        $week = app(InspectionGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();
        $year = app(InspectionGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), []
        )->toArray();

        $this->assertSame($week['data']['slices'], $year['data']['slices']);
    }

    /**
     * The figures: in progress and archived are snapshots; submitted carries
     * the same span last month. The 20th of August is after last month's
     * "so far" ended, so it reaches neither submitted figure.
     */
    public function test_the_figures_compare_submitted_with_the_same_span_last_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));

        $this->runOn($this->alpha['franchise']);
        $this->reportOn($this->alpha['franchise'], Carbon::parse('2026-09-10 12:00:00', 'UTC'));
        $this->reportOn($this->alpha['franchise'], Carbon::parse('2026-08-05 12:00:00', 'UTC'));
        $this->reportOn($this->alpha['franchise'], Carbon::parse('2026-08-12 12:00:00', 'UTC'));
        $late = $this->reportOn($this->alpha['franchise'], Carbon::parse('2026-08-20 12:00:00', 'UTC'));
        $this->inTenant(fn () => $late->forceFill(['archived_at' => now()])->save());

        $figures = app(InspectionGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'month'), []
        )->toArray()['data']['figures'];

        $this->assertSame([
            ['key' => 'in_progress', 'label' => 'in progress', 'value' => 1, 'previous' => null],
            ['key' => 'submitted', 'label' => 'submitted this month', 'value' => 1, 'previous' => 2],
            ['key' => 'archived', 'label' => 'archived', 'value' => 1, 'previous' => null],
        ], $figures);
    }

    /**
     * Attention is untouched drafts: a week is bad, three days is caution, and
     * a run saved yesterday is neither. Each run is created at its own clock so
     * `updated_at` is the age being judged; the boundary days (exactly 3 and 7)
     * sit on the side the comparisons put them.
     */
    public function test_attention_names_drafts_nobody_came_back_to(): void
    {
        $now = Carbon::parse('2026-09-20 12:00:00', 'UTC');

        foreach (['2026-09-19', '2026-09-16', '2026-09-13 13:00:00', '2026-09-10', '2026-09-01'] as $at) {
            Carbon::setTestNow(Carbon::parse($at, 'UTC'));
            $this->runOn($this->alpha['franchise']);
        }

        Carbon::setTestNow($now);

        $data = app(InspectionGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray()['data'];

        $this->assertSame([
            ['tone' => 'bad', 'text' => '2 inspections started over a week ago and not submitted.'],
            ['tone' => 'caution', 'text' => '2 inspections not touched in 3 days.'],
        ], $data['attention'] ?? null);
    }

    /** Fresh drafts and filed reports need nobody: the key is absent, not empty. */
    public function test_nothing_stale_carries_no_attention(): void
    {
        $this->runOn($this->alpha['franchise']);
        $this->reportOn($this->alpha['franchise'], now()->subMonths(2));

        $data = app(InspectionGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), []
        )->toArray()['data'];

        $this->assertFalse($data['empty']);
        $this->assertArrayNotHasKey('attention', $data);
    }

    public function test_submitted_this_period_uses_the_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'UTC'));
        $this->reportOn($this->alpha['franchise']);

        $week = app(InspectionGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();
        $this->assertContains('1 submitted this week.', $week['data']['sentences'] ?? []);

        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
        $weekLater = app(InspectionGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
        )->toArray();
        $yearLater = app(InspectionGlance::class)->resolve(
            $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), []
        )->toArray();

        $this->assertContains('0 submitted this week.', $weekLater['data']['sentences'] ?? []);
        $this->assertContains('1 submitted this year.', $yearLater['data']['sentences'] ?? []);
    }
}
