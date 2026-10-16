<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\Invitation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\GlanceRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Human Resources dashboard chip (spec §12).
 *
 * One glance — the same HrGlance as GET /dashboard — not five panels. The
 * failures that still matter are the ones that look like success:
 *
 *   - counting people by franchise, which silently drops the founding
 *     administrator (organization-level assignment)
 *   - answering wider than the caller's reach
 *   - counting withdrawn invitations as stalled
 */
class HrDashboardTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table populated by the module registry, so
        // a role built below gets an empty set without this and every glance
        // looks correctly hidden.
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

    // ── the case the owner will actually see ─────────────────────

    /**
     * One person, nothing else. The founding administrator is assigned at
     * ORGANIZATION level by ProvisionOrganization, so a glance that filtered
     * people by franchise id would report nobody and look right doing it.
     */
    public function test_a_one_person_organization_counts_that_person(): void
    {
        $glance = $this->glance($this->alpha['admin']);

        $this->assertSame('hr', $glance['key']);
        $this->assertFalse($glance['data']['empty']);
        $this->assertContains(
            '1 person on the books.',
            $glance['data']['sentences'],
            'The founder is assigned at organization level; counting by franchise_id would say nobody.',
        );

        $slices = collect($glance['data']['slices']);
        $this->assertSame(1, $slices->firstWhere('label', 'Active')['total'] ?? null);
        $this->assertSame(0, $slices->firstWhere('label', 'Inactive')['total'] ?? null);
        $this->assertContains('0 onboardings in flight.', $glance['data']['sentences']);
        $this->assertContains('0 invitations have stalled.', $glance['data']['sentences']);
    }

    // ── reach ────────────────────────────────────────────────────

    public function test_a_franchise_manager_does_not_see_the_whole_organizations_headcount(): void
    {
        [, $branchUser] = $this->secondBranch();

        $glance = $this->glance($branchUser);

        // Their own franchise, and only it. The administrator sitting at
        // organization level is above them and is not theirs to count.
        $this->assertContains('1 person on the books.', $glance['data']['sentences']);
    }

    public function test_somebody_assigned_nowhere_is_answered_with_empty_not_everything(): void
    {
        $stranger = $this->person('Stranger', 'stranger@alpha.test');

        $glance = $this->glance($stranger);

        // An empty place list that dropped its clause would invert into
        // "assigned nowhere means see everybody".
        $this->assertTrue($glance['data']['empty']);
        // Scoped, not "yet": this stranger reaches nobody, and the
        // organization they cannot see is not empty. See HrGlance's note.
        $this->assertSame('Nobody in this view.', $glance['data']['sentence']);
    }

    // ── invitations ──────────────────────────────────────────────

    public function test_a_withdrawn_invitation_is_not_a_stalled_one(): void
    {
        $this->invite('revoked@alpha.test');

        $this->inTenant(fn () => Invitation::where('email', 'revoked@alpha.test')
            ->firstOrFail()->forceFill(['revoked_at' => now()])->save());

        $glance = $this->glance($this->alpha['admin']);

        // "We changed our mind" and "they never came" are different facts.
        $this->assertContains('0 invitations have stalled.', $glance['data']['sentences']);
    }

    public function test_active_vs_inactive_slices_ignore_the_period(): void
    {
        $this->setPeriod($this->alpha['admin'], 'week');
        $week = $this->glance($this->alpha['admin'])['data']['slices'];

        $this->setPeriod($this->alpha['admin'], 'year');
        $year = $this->glance($this->alpha['admin'])['data']['slices'];

        $this->assertSame($week, $year);
    }

    // ── same glance, two surfaces ────────────────────────────────

    public function test_the_hr_chip_matches_the_main_dashboard_hr_glance(): void
    {
        $fromChip = $this->glance($this->alpha['admin']);

        $fromMain = collect($this->as($this->alpha['admin'])
            ->getJson('/api/v1/dashboard')->assertOk()->json('glances'))
            ->firstWhere('key', 'hr');

        $this->assertNotNull($fromMain);
        $this->assertSame($fromMain['data'], $fromChip['data']);
        $this->assertSame($fromMain['key'], $fromChip['key']);
    }

    public function test_period_envelope_is_echoed(): void
    {
        $body = $this->as($this->alpha['admin'])
            ->getJson('/api/v1/hr/dashboard')->assertOk()->json();

        $this->assertArrayHasKey('period', $body);
        $this->assertArrayHasKey('period_starts_on', $body);
        $this->assertArrayHasKey('period_ends_on', $body);
        $this->assertArrayHasKey('glance', $body);
        $this->assertIsArray($body['glance']);
    }

    /**
     * find('hr') null must not 500. A real ModuleAvailability kill switch also
     * makes hasPermission('hr.view') false, so authorize 403s first; this stubs
     * the registry so the controller's null path is what is under test.
     */
    public function test_missing_hr_glance_returns_null_rather_than_500(): void
    {
        $this->mock(GlanceRegistry::class, function ($mock) {
            $mock->shouldReceive('find')->once()->andReturn(null);
        });

        $body = $this->as($this->alpha['admin'])
            ->getJson('/api/v1/hr/dashboard')->assertOk()->json();

        $this->assertNull($body['glance']);
        $this->assertArrayHasKey('period', $body);
        $this->assertArrayHasKey('period_starts_on', $body);
        $this->assertArrayHasKey('period_ends_on', $body);
    }

    // ── the gates ────────────────────────────────────────────────

    public function test_somebody_without_the_tab_permission_is_refused(): void
    {
        $limited = $this->inTenant(function () {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Jobs only', 'is_system' => false,
            ]);

            $role->permissions()->sync(Permission::whereIn('key', ['jobs.view'])->pluck('id'));

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Limited', 'email' => 'limited@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });

        $this->as($limited)->getJson('/api/v1/hr/dashboard')->assertForbidden();
    }

    /**
     * One computation, two surfaces — the point of declaring these in the
     * shared registry rather than hand-building a second HR box.
     */
    public function test_the_hr_widgets_are_also_offered_on_the_main_dashboard(): void
    {
        $keys = collect($this->as($this->alpha['admin'])
            ->getJson('/api/v1/dashboard/catalogue')->assertOk()->json('glances'))
            ->pluck('key')->all();

        $this->assertContains('hr', $keys);
    }

    public function test_the_registry_withholds_hr_widgets_from_somebody_without_hr_view(): void
    {
        $jobsOnly = $this->inTenant(function () {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Jobs only', 'is_system' => false,
            ]);

            $role->permissions()->sync(Permission::whereIn('key', ['jobs.view'])->pluck('id'));

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Jobs', 'email' => 'jobs@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });

        $keys = collect($this->as($jobsOnly)
            ->getJson('/api/v1/dashboard/catalogue')->assertOk()->json('glances'))
            ->pluck('key')->all();

        $this->assertContains('jobs', $keys);
        $this->assertNotContains('hr', $keys);
    }

    // ── helpers ──────────────────────────────────────────────────

    /** @return array<string, mixed> the singular glance payload */
    private function glance(User $actor): array
    {
        $glance = $this->as($actor)->getJson('/api/v1/hr/dashboard')->assertOk()->json('glance');

        $this->assertIsArray($glance);

        return $glance;
    }

    private function setPeriod(User $user, string $period): void
    {
        $this->inTenant(function () use ($user, $period) {
            $preferences = $user->preferences ?? [];
            $preferences['dashboard.period'] = $period;
            $user->forceFill(['preferences' => $preferences])->save();
        });
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function as(User $user): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** A person, optionally placed. Unplaced when no franchise is given. */
    private function person(string $name, string $email, ?string $franchiseId = null): User
    {
        return $this->inTenant(function () use ($name, $email, $franchiseId) {
            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id' => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
                'name' => $name, 'email' => $email,
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            if ($franchiseId !== null) {
                Assignment::create([
                    'organization_id' => $this->alpha['org']->id,
                    'user_id' => $user->id, 'assigned_franchise_id' => $franchiseId,
                ]);
            }

            return $user;
        });
    }

    /** A second franchise with its own user, reaching only that franchise. */
    private function secondBranch(): array
    {
        $franchise = $this->inTenant(fn () => Franchise::create([
            'organization_id' => $this->alpha['org']->id,
            'company_id' => $this->alpha['company']->id,
            'name' => 'Second', 'is_active' => true,
        ]));

        return [$franchise, $this->person('Branch', 'branch@alpha.test', $franchise->id)];
    }

    /** Sent through the endpoint, so it carries whatever a real one carries. */
    private function invite(string $email): void
    {
        $this->as($this->alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email' => $email,
            'role_id' => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
            'scope_type' => 'franchise',
            'scope_id' => $this->alpha['franchise']->id,
        ])->assertCreated();
    }
}
