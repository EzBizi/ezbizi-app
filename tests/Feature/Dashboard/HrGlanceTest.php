<?php

namespace Tests\Feature\Dashboard;

use App\Models\Invitation;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\HrGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Human Resources dashboard glance (spec § HR — bar).
 *
 * Snapshot bar of onboardings in flight vs invitations stalled. Headcount and
 * the other HR figures stay in sentences. Organization-level assignments must
 * count — the founder is not on a franchise.
 */
class HrGlanceTest extends TestCase
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

    private function as(\App\Models\User $user): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Sent through the endpoint, so it carries whatever a real one carries. */
    private function invite(string $email): void
    {
        $this->as($this->alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => $email,
            'role_id'    => $this->alpha['admin']->role_id,
            'scope_type' => 'franchise',
            'scope_id'   => $this->alpha['franchise']->id,
        ])->assertCreated();
    }

    public function test_a_one_person_organization_counts_the_founder(): void
    {
        $payload = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
        $this->assertContains(
            '1 person on the books.',
            $payload['data']['sentences'],
            'The founder is assigned at organization level; counting by franchise_id would say nobody.',
        );
    }

    public function test_revoked_invitations_are_not_stalled(): void
    {
        $this->invite('revoked@alpha.test');

        $this->inTenant(fn () => Invitation::where('email', 'revoked@alpha.test')
            ->firstOrFail()->forceFill(['revoked_at' => now()])->save());

        $payload = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
        $stalled = collect($payload['data']['slices'])->firstWhere('label', 'Invitations stalled');

        $this->assertSame(0, $stalled['total'] ?? null);
    }

    public function test_in_flight_vs_stalled_slices_ignore_the_period(): void
    {
        $week = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), [])->toArray()['data']['slices'];
        $year = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), [])->toArray()['data']['slices'];
        $this->assertSame($week, $year);
    }
}
