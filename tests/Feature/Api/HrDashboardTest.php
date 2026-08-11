<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Franchise;
use App\Models\Invitation;
use App\Models\Onboarding;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Human Resources dashboard (EZBIZI-HR-PLAN.md — Dashboard).
 *
 * ── What is actually worth testing here ──────────────────────────
 *
 * Not "does it return four panels". The failures that matter on a dashboard are
 * the ones that look like success:
 *
 *   - counting people by FRANCHISE, which silently drops everybody placed at
 *     company or organization level — including the founding administrator, who
 *     is the only person most organizations have. The panel would report zero
 *     for an organization with somebody in it.
 *   - answering wider than the caller's reach, which is a data leak nobody
 *     reports as a bug because a number that is too big looks like the app
 *     working.
 *   - adding rows up to make a total, which double-counts anybody working in
 *     two places.
 *   - counting withdrawn invitations as stalled, which makes the one number
 *     this panel exists for — are our invitations being ignored — go up every
 *     time somebody changes their mind.
 */
class HrDashboardTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table populated by the module registry, so
        // a role built below gets an empty set without this and every widget
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

    // ── the case the owner will actually see ─────────────────────

    /**
     * One person, nothing else. The empty state is the PRIMARY case here.
     *
     * And the one number that must not be zero is the headcount: the founding
     * administrator is assigned at ORGANIZATION level by ProvisionOrganization,
     * so a panel that filtered people by franchise id would report an
     * organization with a person in it as having nobody, and would look right
     * doing it.
     */
    public function test_a_one_person_organization_counts_that_person(): void
    {
        $panels = $this->panels($this->alpha['admin']);

        $this->assertSame(1, $panels['hr.headcount']['total']);

        // Placed under the organization's own name, not under a franchise they
        // were never assigned to.
        $this->assertContains(
            ['title' => 'Alpha', 'total' => 1],
            $panels['hr.headcount']['rows'],
        );

        // Everything else is genuinely empty, and says so with a zero rather
        // than by omitting the row.
        $this->assertSame(0, $panels['hr.onboardings_in_flight']['total']);
        $this->assertSame(0, $panels['hr.invitations_stalled']['total']);

        // Nothing has been sent, which the client needs in order to say "you
        // have not invited anybody yet" rather than "nothing has stalled".
        $this->assertSame(0, $panels['hr.invitations_stalled']['sent_total']);
    }

    /** The places with nobody in them are shown, because that is the useful row. */
    public function test_empty_places_are_listed_rather_than_hidden(): void
    {
        $rows = $this->panels($this->alpha['admin'])['hr.headcount']['rows'];

        $this->assertContains(['title' => 'Co', 'total' => 0], $rows);
        $this->assertContains(['title' => 'F1', 'total' => 0], $rows);
    }

    /**
     * The invariant the client's empty state is allowed to lean on.
     *
     * The headcount panel has two branches — "you have nowhere to count yet"
     * when `places` is 0, and the rows otherwise — and no third one for "places
     * that sent no rows", because THIS endpoint cannot produce that. It resolves
     * every widget with no options (HrDashboardController::index), so
     * `include_empty` is its `true` default and Headcount emits one row per
     * place; an empty row list therefore means no places, full stop.
     *
     * Pinned rather than assumed. The day this tab starts passing options is the
     * day that third branch has to come back, and this is what will say so.
     */
    public function test_a_place_always_arrives_as_a_row_so_empty_rows_mean_no_places(): void
    {
        $headcount = $this->panels($this->alpha['admin'])['hr.headcount'];

        // Three places on a freshly provisioned organization — Alpha, Co, F1 —
        // and nobody in two of them.
        $this->assertSame(3, $headcount['places']);
        $this->assertGreaterThanOrEqual($headcount['places'], count($headcount['rows']));
        $this->assertNotEmpty($headcount['rows']);
    }

    // ── reach ────────────────────────────────────────────────────

    public function test_a_franchise_manager_does_not_see_the_whole_organizations_headcount(): void
    {
        [$second, $branchUser] = $this->secondBranch();

        $panels = $this->panels($branchUser);

        // Their own franchise, and only it. The administrator sitting at
        // organization level is above them and is not theirs to count.
        $this->assertSame(1, $panels['hr.headcount']['total']);
        $this->assertContains(['title' => 'Second', 'total' => 1], $panels['hr.headcount']['rows']);

        foreach ($panels['hr.headcount']['rows'] as $row) {
            $this->assertNotSame('Alpha', $row['title']);
        }
    }

    public function test_somebody_assigned_nowhere_is_answered_with_zero_not_everything(): void
    {
        $stranger = $this->person('Stranger', 'stranger@alpha.test');

        $panels = $this->panels($stranger);

        // An empty place list compiles to `0 = 1`. Written as a condition that
        // dropped the clause when the list was empty, this would invert into
        // "assigned nowhere means see everybody".
        $this->assertSame(0, $panels['hr.headcount']['total']);
    }

    // ── counting people rather than rows ─────────────────────────

    public function test_somebody_in_two_places_appears_twice_but_counts_once(): void
    {
        $second = $this->inTenant(fn () => Franchise::create([
            'organization_id' => $this->alpha['org']->id,
            'company_id'      => $this->alpha['company']->id,
            'name' => 'Second', 'is_active' => true,
        ]));

        $shared = $this->person('Shared', 'shared@alpha.test');

        $this->inTenant(function () use ($shared, $second) {
            foreach ([$this->alpha['franchise']->id, $second->id] as $franchiseId) {
                Assignment::create([
                    'organization_id' => $this->alpha['org']->id,
                    'user_id' => $shared->id, 'assigned_franchise_id' => $franchiseId,
                ]);
            }
        });

        $headcount = $this->panels($this->alpha['admin'])['hr.headcount'];

        // In both rows: they genuinely are part of both franchises' headcount.
        $this->assertContains(['title' => 'F1', 'total' => 1], $headcount['rows']);
        $this->assertContains(['title' => 'Second', 'total' => 1], $headcount['rows']);

        // But two people, not three. Summing the rows would inflate every
        // organization that shares staff, quietly.
        $this->assertSame(2, $headcount['total']);
    }

    public function test_deactivated_people_are_not_in_the_headcount(): void
    {
        $leaver = $this->person('Leaver', 'leaver@alpha.test', $this->alpha['franchise']->id);

        $this->assertSame(2, $this->panels($this->alpha['admin'])['hr.headcount']['total']);

        $this->inTenant(fn () => $leaver->forceFill(['is_active' => false])->save());

        $panels = $this->panels($this->alpha['admin']);

        $this->assertSame(1, $panels['hr.headcount']['total']);

        // They have not vanished — deactivate is not delete, and the other panel
        // still reports them.
        $this->assertContains(
            ['title' => 'No longer active', 'total' => 1],
            $panels['hr.starters_and_leavers']['rows'],
        );
    }

    // ── invitations ──────────────────────────────────────────────

    public function test_a_withdrawn_invitation_is_not_a_stalled_one(): void
    {
        $this->invite('revoked@alpha.test');

        $this->inTenant(fn () => Invitation::where('email', 'revoked@alpha.test')
            ->firstOrFail()->forceFill(['revoked_at' => now()])->save());

        // "We changed our mind" and "they never came" are different facts about
        // different failures. Counting the first here would inflate the number
        // this panel exists to report every time somebody withdrew one.
        $this->assertSame(0, $this->panels($this->alpha['admin'])['hr.invitations_stalled']['total']);
    }

    public function test_an_expired_invitation_has_stalled(): void
    {
        $this->invite('expired@alpha.test');

        $this->inTenant(fn () => Invitation::where('email', 'expired@alpha.test')
            ->firstOrFail()->forceFill(['expires_at' => now()->subDay()])->save());

        $stalled = $this->panels($this->alpha['admin'])['hr.invitations_stalled'];

        $this->assertSame(1, $stalled['total']);
        $this->assertContains(
            ['title' => 'Expired before anyone accepted', 'total' => 1],
            $stalled['rows'],
        );
    }

    public function test_a_live_unopened_invitation_stalls_once_it_has_gone_quiet(): void
    {
        $this->invite('quiet@alpha.test');

        // Sent a week ago, still live, never opened — the wrong-address case.
        $this->inTenant(fn () => Invitation::where('email', 'quiet@alpha.test')
            ->firstOrFail()->forceFill([
                'sent_at'    => now()->subDays(7),
                'expires_at' => now()->addDays(7),
            ])->save());

        $stalled = $this->panels($this->alpha['admin'])['hr.invitations_stalled'];

        $this->assertContains(
            ['title' => 'Sent but never opened', 'total' => 1],
            $stalled['rows'],
        );
    }

    public function test_an_invitation_sent_today_has_not_stalled(): void
    {
        $this->invite('fresh@alpha.test');

        $this->assertSame(0, $this->panels($this->alpha['admin'])['hr.invitations_stalled']['total']);

        // But it HAS been sent, which is what lets the client tell "nothing has
        // stalled" apart from "nothing has been sent".
        $this->assertSame(1, $this->panels($this->alpha['admin'])['hr.invitations_stalled']['sent_total']);
    }

    // ── onboardings ──────────────────────────────────────────────

    public function test_the_two_unfinished_states_are_reported_separately(): void
    {
        $this->onboarding('one@alpha.test', Onboarding::IN_PROGRESS);
        $this->onboarding('two@alpha.test', Onboarding::SUBMITTED);
        $this->onboarding('three@alpha.test', Onboarding::COMPLETED);

        $inFlight = $this->panels($this->alpha['admin'])['hr.onboardings_in_flight'];

        // Finished work is not in flight.
        $this->assertSame(2, $inFlight['total']);

        // Split, because they need different people to do different things:
        // one is waiting on typing, the other on a signature.
        $this->assertContains(['title' => 'Being filled in', 'total' => 1], $inFlight['rows']);
        $this->assertContains(['title' => 'Waiting on a signature', 'total' => 1], $inFlight['rows']);

        $this->assertNotNull($inFlight['oldest_started_at']);
    }

    // ── starters, and the half that has no date ──────────────────

    public function test_starters_are_dated_and_leavers_are_not(): void
    {
        $this->person('New', 'new@alpha.test', $this->alpha['franchise']->id);

        $old = $this->person('Old', 'old@alpha.test', $this->alpha['franchise']->id);
        $this->inTenant(fn () => $old->forceFill(['created_at' => now()->subYear()])->save());

        $panel = $this->panels($this->alpha['admin'])['hr.starters_and_leavers'];

        // The administrator and the new starter joined inside the window; the
        // one backdated a year did not.
        $this->assertSame(2, $panel['total']);
        $this->assertContains('New', array_column($panel['people'], 'name'));
        $this->assertNotContains('Old', array_column($panel['people'], 'name'));

        /*
         * The period covers joining and nothing else.
         *
         * `users.is_active` is a boolean with no timestamp beside it, so there is
         * no honest answer to "who left this month" — only to "who is no longer
         * active". The client labels the two rows from this, so a panel that
         * claimed both were "in the last 30 days" would be asserting something
         * the data cannot support.
         */
        $this->assertSame(['Joined'], $panel['period_applies_to']);
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
     * shared registry rather than hand-building four cards.
     *
     * Offered there, not switched on there: the main dashboard's default layout
     * is a named subset that does not include them, which
     * DashboardTest::test_a_newly_registered_widget_is_offered_rather_than_switched_on
     * is the test for.
     */
    public function test_the_hr_widgets_are_also_offered_on_the_main_dashboard(): void
    {
        $keys = collect($this->as($this->alpha['admin'])
            ->getJson('/api/v1/dashboard/catalogue')->assertOk()->json('widgets'))
            ->pluck('key')->all();

        // Somebody who wants headcount beside their revenue gets the same
        // widget rather than a second one that counts slightly differently.
        $this->assertContains('hr.headcount', $keys);
        $this->assertContains('hr.starters_and_leavers', $keys);
    }

    /**
     * The registry gates each widget on its OWN permission, not on the route's.
     *
     * That is the second gate HrDashboardController's docblock names, and this
     * is how far it can honestly be demonstrated today: all four HR widgets
     * return `hr.view`, which is also what the HR route checks, so nothing in
     * PANELS can currently be withheld from a caller who got past the route.
     * The gate is real — it is what a widget added later with a narrower
     * permission would meet — but it is unexercised BY CONSTRUCTION on that
     * tab, and saying so is better than a test that looks like it proves it.
     *
     * What is exercisable is the filter itself, on the surface where the widget
     * permission and the route permission differ: the catalogue is open to
     * anybody signed in, so a role with no `hr.view` reaching it is the case
     * that would leak headcount if the registry trusted the route.
     */
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
            ->getJson('/api/v1/dashboard/catalogue')->assertOk()->json('widgets'))
            ->pluck('key')->all();

        // They can see a dashboard, and none of it is HR.
        $this->assertContains('jobs.by_status', $keys);

        foreach (['hr.headcount', 'hr.onboardings_in_flight',
            'hr.invitations_stalled', 'hr.starters_and_leavers'] as $hr) {
            $this->assertNotContains($hr, $keys);
        }
    }

    // ── helpers ──────────────────────────────────────────────────

    /** @return array<string, array> the panels, keyed by widget key */
    private function panels(User $actor): array
    {
        $panels = $this->as($actor)->getJson('/api/v1/hr/dashboard')->assertOk()->json('panels');

        return collect($panels)->keyBy('key')->map(fn (array $p) => $p['data'])->all();
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
                'role_id'         => $this->alpha['admin']->role_id,
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
            'company_id'      => $this->alpha['company']->id,
            'name' => 'Second', 'is_active' => true,
        ]));

        return [$franchise, $this->person('Branch', 'branch@alpha.test', $franchise->id)];
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

    /** An onboarding in a given state, against a real invitation and version. */
    private function onboarding(string $email, string $status): Onboarding
    {
        $this->invite($email);

        return $this->inTenant(function () use ($email, $status) {
            $contract = Contract::firstOrCreate(
                ['organization_id' => $this->alpha['org']->id, 'name' => 'Standard'],
                ['created_by' => $this->alpha['admin']->id],
            );

            $version = ContractVersion::create([
                'organization_id' => $this->alpha['org']->id,
                'contract_id'     => $contract->id,
                'version'         => ContractVersion::where('contract_id', $contract->id)->count() + 1,
                'fields'          => [],
                'created_by'      => $this->alpha['admin']->id,
            ]);

            return Onboarding::create([
                'organization_id'     => $this->alpha['org']->id,
                'invitation_id'       => Invitation::where('email', $email)->firstOrFail()->id,
                'contract_version_id' => $version->id,
                'status'              => $status,
                'started_at'          => now()->subDays(2),
                'started_by_user_id'  => $this->alpha['admin']->id,
            ]);
        });
    }
}
