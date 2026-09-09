<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Invitation;
use App\Models\User;
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
 * Snapshot bar of active vs inactive people in reach. Onboardings, stalled
 * invitations, joiners, leavers, and certifications stay sentences.
 * Organization-level assignments must count — the founder is not on a franchise.
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

        $slices = collect($payload['data']['slices']);
        $this->assertSame(1, $slices->firstWhere('label', 'Active')['total'] ?? null);
        $this->assertSame(0, $slices->firstWhere('label', 'Inactive')['total'] ?? null);
    }

    /**
     * The one line that used to vanish when the news was good.
     *
     * Five of the six sentences render their zero; certifications was written
     * `elseif (total > 0)`, so a box that had LOOKED and found nothing due was
     * indistinguishable from one where nobody is watching. The note is the
     * other branch — nothing marked as an expiry at all — and this case is the
     * opposite: a question IS marked, and nothing is coming due.
     */
    public function test_a_configured_expiry_with_nothing_due_says_zero_rather_than_going_quiet(): void
    {
        $this->inTenant(function () {
            $contract = \App\Models\Contract::create([
                'organization_id' => $this->alpha['org']->id,
                'name'            => 'Technician',
                'created_by'      => $this->alpha['admin']->id,
            ]);

            $version = \App\Models\ContractVersion::create([
                'organization_id' => $this->alpha['org']->id,
                'contract_id'     => $contract->id,
                'version'         => 1,
                'body'            => '',
                'created_by'      => $this->alpha['admin']->id,
                'fields'          => [[
                    'key' => 'ticket', 'label' => 'Gas ticket', 'type' => 'date',
                    'options' => [], 'filled_by' => 'either', 'required' => false,
                    'sensitive' => false, 'retired' => false, 'is_expiry' => true,
                ]],
            ]);

            // No invitation, so it is placed by the reader's own assignment and
            // an organization-level administrator can see it. No answer either:
            // the question exists and nobody has dated it, which is exactly
            // "marked, and nothing coming due".
            \App\Models\Onboarding::create([
                'organization_id'     => $this->alpha['org']->id,
                'user_id'             => $this->alpha['admin']->id,
                'contract_version_id' => $version->id,
                'status'              => 'in_progress',
                'started_at'          => now(),
                'started_by_user_id'  => $this->alpha['admin']->id,
            ]);
        });

        $sentences = app(HrGlance::class)
            ->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])
            ->toArray()['data']['sentences'];

        $this->assertContains('0 certifications are coming due.', $sentences);

        // And it is not the other branch wearing a different hat: the note
        // fires only when nothing is marked at all, and something is.
        $this->assertNotContains(
            'No contract question in this view is marked as an expiry, so there is nothing to track. A date field on a contract template can be marked as one.',
            $sentences,
        );
    }

    public function test_revoked_invitations_are_not_stalled(): void
    {
        $this->invite('revoked@alpha.test');

        $this->inTenant(fn () => Invitation::where('email', 'revoked@alpha.test')
            ->firstOrFail()->forceFill(['revoked_at' => now()])->save());

        $payload = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();

        $this->assertContains('0 invitations have stalled.', $payload['data']['sentences']);
    }

    public function test_active_vs_inactive_slices_ignore_the_period(): void
    {
        $week = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), [])->toArray()['data']['slices'];
        $year = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), [])->toArray()['data']['slices'];
        $this->assertSame($week, $year);
    }

    public function test_deactivated_people_are_the_inactive_slice(): void
    {
        $this->inTenant(function () {
            $person = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $this->alpha['admin']->role_id,
                'name' => 'Gone', 'email' => 'gone@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $person->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            $person->forceFill(['is_active' => false])->save();
        });

        $payload = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
        $slices = collect($payload['data']['slices']);

        $this->assertSame(1, $slices->firstWhere('label', 'Active')['total'] ?? null);
        $this->assertSame(1, $slices->firstWhere('label', 'Inactive')['total'] ?? null);
        $this->assertContains('2 people on the books.', $payload['data']['sentences']);
    }

    /**
     * A view-timezone wall clock must be converted before it meets a UTC column.
     *
     * `DashboardPeriod::startsOn` is local midnight in the ORGANIZATION timezone;
     * `users.created_at` is UTC. A Carbon binds as its own wall-clock string with
     * no conversion, so an unconverted bound sits EARLY by the offset and drags in
     * people who joined the evening before the period began.
     *
     * The clock is frozen in the EVENING deliberately. Freeze it at midday and the
     * gap this guards does not exist, and the test passes against the bug — which
     * is the only reason it is written this way.
     *
     * Direction matters too: this defect OVER-counts. CallReport had the same
     * class as a two-sided window that DROPPED evening rows. A test shaped for
     * one does not catch the other.
     */
    public function test_somebody_who_joined_before_the_period_began_is_not_counted_as_a_starter(): void
    {
        // Settings are tenant-scoped, so this write needs a tenant in context.
        $this->inTenant(fn () => app(\App\Support\Settings\SettingsResolver::class)
            ->set('timezone', $this->alpha['org'], 'Etc/GMT+5'));   // UTC-5; POSIX sign

        // Monday 2026-08-31, 21:00 in the view timezone — an evening inside the week.
        Carbon::setTestNow(Carbon::parse('2026-08-31 21:00:00', 'Etc/GMT+5'));

        $period = DashboardPeriod::for($this->alpha['admin'], 'week');

        // Two hours BEFORE the week began, in real time. In New York terms this is
        // the previous evening; as an unconverted wall-clock string it looks like
        // it is inside the window, which is the bug.
        $before = $period->startsOn->copy()->utc()->subHours(2);

        $this->inTenant(function () use ($before) {
            $person = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $this->alpha['admin']->role_id,
                'name' => 'Early', 'email' => 'early@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $person->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            // created_at is not fillable, so set it explicitly or the row is
            // stamped now() and the test asserts nothing.
            $person->forceFill(['created_at' => $before])->save();
        });

        // Through the GLANCE, which is where the conversion lives. Calling the
        // widget directly with an already-converted bound would assert nothing
        // about HrGlance and would pass against the bug.
        $sentences = app(HrGlance::class)
            ->resolve($this->alpha['admin'], $period, [])
            ->toArray()['data']['sentences'];

        $this->assertContains(
            '1 person joined this week.',   // the founder, and NOT the early joiner
            $sentences,
            'somebody who joined before the period began was counted as a starter',
        );
    }
}
