<?php

namespace Tests\Feature\Api;

use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A tab a role may not open does not answer either (Jose, 2026-09-17).
 *
 * His example, in his words: *"i wanted to give dispatchers access to the work
 * hours tab inside human resources ... i want them to see their work hours, but
 * not the onboarding information"*.
 *
 * ── What this suite exists to catch ──────────────────────────────
 *
 * Not whether the tab is drawn. A hidden tab whose endpoints still answer is
 * not a permission, it is a decoration — the URL is one paste away from anybody
 * who has ever seen the pane, and the screen looks correct from both sides
 * while it happens. So every test here is about the SERVER, and the client
 * filtering is tested separately in hr_screen_test.dart.
 *
 * The dispatcher below holds `hr.hours.view` and nothing else in HR. That is
 * the grant that did not exist yesterday: the smallest thing anybody could give
 * them was `hr.view`, which is every contract and every onboarding form in the
 * business.
 */
class HrTabPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Work Hours needs Clock in switched on as well as granted.
     *
     * TWO GATES, and the probe that found this is worth recording: a role
     * holding `hr.hours.view` was refused with "Clock in is switched off for
     * this organization", which is §2.1's platform layer answering before any
     * permission is consulted. A test that had asserted 403 without a positive
     * control would have read that as the tab permission working perfectly.
     */
    private function withClockIn(array $tenant): array
    {
        app(TenantContext::class)->run(
            $tenant['org'],
            fn () => app(SettingsResolver::class)->set('time_clock.enabled', $tenant['org'], true),
        );

        return $tenant;
    }

    public function test_a_dispatcher_granted_only_work_hours_can_read_it(): void
    {
        $tenant = $this->withClockIn($this->provisionTenant('Alpha'));

        $dispatcher = $this->userWithRole($tenant, 'Dispatcher', ['hr.hours.view']);

        // The POSITIVE control, and the whole suite is worthless without it.
        // Every other assertion here is a 403, and a 403 is also what a broken
        // route, a missing permission row or a fixture with no reach would
        // produce. This says the endpoint answers this person at all.
        $this->signedInAs($dispatcher)
            ->getJson('/api/v1/hr/time-clock/week')
            ->assertOk();
    }

    public function test_the_same_dispatcher_is_refused_every_other_tab(): void
    {
        $tenant = $this->provisionTenant('Bravo');

        $dispatcher = $this->userWithRole($tenant, 'Dispatcher', ['hr.hours.view']);

        /*
         * The point of the whole change, stated as the four things they must
         * NOT reach. `hr.hours.view` implies `hr.view` — §4, and
         * PermissionCatalogue::withImpliedView does it on save — so this person
         * genuinely holds the module. Yesterday that was the end of the
         * question and all four of these answered 200.
         */
        foreach ([
            '/api/v1/hr/dashboard',
            '/api/v1/hr/people',
            '/api/v1/hr/invitations',
        ] as $path) {
            $this->signedInAs($dispatcher)
                ->getJson($path)
                ->assertForbidden();
        }

        /*
         * ONBOARDING AND PAID TIME OFF ARE NOT IN THAT LIST, and their absence
         * is the finding rather than an omission.
         *
         * Both serve two audiences through one URL. An employee with no HR
         * permission must reach their OWN onboarding — they are the person
         * filling it in, and HrOnboardingTest pins an empty list rather than a
         * refusal for somebody with none. `hr/pto` says the same in its own
         * route comment: everybody reads their own leave, `hr.view` adds
         * everybody else's.
         *
         * So a route middleware cannot gate those two: it would take a person's
         * own paperwork away from them. Their tab grant belongs beside the split
         * that already exists inside HrOnboardingsController and
         * HrPeopleController::pto, which is HR's code and HR's to place. Until
         * they do, this dispatcher sees an empty Onboarding list rather than a
         * refusal — measured, not assumed.
         */
        $this->signedInAs($dispatcher)->getJson('/api/v1/hr/onboardings')->assertOk();
    }

    public function test_the_refusal_says_what_to_do_about_it(): void
    {
        $tenant = $this->provisionTenant('Charlie');

        $dispatcher = $this->userWithRole($tenant, 'Dispatcher', ['hr.hours.view']);

        // R8. A person refused a pane reads the same shape of sentence they read
        // everywhere else, and the permission KEY tells them nothing — the
        // remedy is somebody else's, and it is a role rather than an account.
        $this->signedInAs($dispatcher)
            ->getJson('/api/v1/hr/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'permission_denied')
            ->assertJsonFragment(['message' => 'You do not have permission to open this tab. '
                .'That is part of a role rather than of an account, so somebody who can edit '
                .'roles can add it to yours.']);
    }

    public function test_a_role_holding_the_whole_module_still_reaches_everything(): void
    {
        $tenant = $this->withClockIn($this->provisionTenant('Delta'));

        /*
         * The direction that would be invisible if it broke.
         *
         * Splitting a permission can only fail two ways, and they are not
         * symmetrical: granting too much gets reported by whoever sees a pane
         * they should not, while NARROWING is silent — somebody opens HR and
         * the tab they used yesterday is simply absent, with nothing logged and
         * no error to quote. The accompanying migration grants every existing
         * role all seven tabs for that reason, and this pins the shape it
         * produces.
         */
        $manager = $this->userWithRole($tenant, 'Manager', [
            'hr.view',
            'hr.dashboard.view',
            'hr.hours.view',
            'hr.onboarding.view',
            'hr.records.view',
            'hr.invitations.view',
            'hr.contracts.view',
        ]);

        foreach ([
            '/api/v1/hr/dashboard',
            '/api/v1/hr/time-clock/week',
            '/api/v1/hr/onboardings',
            '/api/v1/hr/people',
            '/api/v1/hr/invitations',
        ] as $path) {
            $this->signedInAs($manager)->getJson($path)->assertOk();
        }
    }

    public function test_the_punch_clock_is_not_behind_the_hr_tab(): void
    {
        $tenant = $this->provisionTenant('Echo');

        /*
         * `time-clock/me` is the top bar, not the pane.
         *
         * Everybody with an account clocks themselves in and out, so gating the
         * four `time-clock/*` routes behind an HR tab would stop a technician
         * starting their day. They sit deliberately outside the group — this is
         * the test that says so, because the next person tidying those routes
         * will see five similar lines and want to wrap them all.
         */
        $nobody = $this->userWithRole($tenant, 'Technician', ['customers.view']);

        $this->signedInAs($nobody)->getJson('/api/v1/time-clock/me')->assertOk();
    }
}
