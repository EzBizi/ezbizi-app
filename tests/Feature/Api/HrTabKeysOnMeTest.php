<?php

namespace Tests\Feature\Api;

use App\Support\Modules\ModuleAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What `/me` tells the CLIENT about HR's tabs, which is a different question
 * from what the routes refuse.
 *
 * HrTabPermissionsTest pins the server side — 403 for a tab you were not
 * given, 200 for one you were. Neither of those says anything about the list
 * the client reads to decide which chips to DRAW. `hr_screen.dart` filters on
 * `me.permissions.contains('hr.<tab>.view')`, so if `/me` stopped carrying
 * those keys every chip would disappear and every endpoint would keep
 * answering: a blank module over a working server, with nothing refused and
 * nothing logged.
 *
 * NO CLIENT TEST CAN ANSWER THIS. Every widget test states its own permission
 * list in a fixture, so it discovers what a fixture says and never what the
 * server derives. I found the 121-key administrator answer with a throwaway
 * probe on 2026-09-17 and then deleted it, which left the fact measured and
 * unguarded — this is that probe, kept.
 *
 * The two paths fail for DIFFERENT reasons, so they need separate assertions
 * (Email measured the same split in their module on the same day):
 *
 *   administrator   `heldBy()` hands them `keys()` whole, so the only question
 *                   is whether the tab is in the registry at all
 *   everybody else  the key is intersected with the role AND filtered by
 *                   `ModuleAvailability`, which answers about the organization
 *                   before any role is consulted
 *
 * A module that tests only the administrator has tested the registry and not
 * the gate.
 */
class HrTabKeysOnMeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** The seven HR declares. Written out rather than read from the registry:
     * a test that asks the registry what the registry says would pass on an
     * empty one. */
    private const TABS = [
        'hr.dashboard.view',
        'hr.hours.view',
        'hr.onboarding.view',
        'hr.records.view',
        'hr.invitations.view',
        'hr.pto.view',
        'hr.contracts.view',
    ];

    private function permissionsOnMe(\App\Models\User $user): array
    {
        $body = $this->signedInAs($user)->getJson('/api/v1/me')->assertOk()->json();

        return $body['data']['permissions'] ?? $body['permissions'] ?? [];
    }

    /**
     * The organization's own owner gets every tab, or the module is blank for
     * the one person who can fix nothing about it.
     *
     * This is the failure that would hit EVERY tenant at once rather than one
     * misconfigured role: an administrator holds no permission rows at all —
     * `ProvisionOrganization` attaches none, deliberately — so their keys are
     * derived rather than stored, and the derivation is what this pins.
     */
    public function test_an_administrator_is_given_every_hr_tab(): void
    {
        $t = $this->provisionTenant('Alpha');

        $permissions = $this->permissionsOnMe($t['admin']);

        foreach (self::TABS as $tab) {
            $this->assertContains($tab, $permissions,
                "An administrator was not given {$tab}, so that chip is missing for the owner.");
        }
    }

    /**
     * And somebody granted one tab is given ONE, not all seven.
     *
     * The control, and the half that cannot be replaced by the test above: an
     * availability layer that handed every declared key to everybody would
     * satisfy the administrator assertion completely while drawing tabs for
     * people who were never granted them.
     */
    public function test_a_role_granted_one_tab_is_given_only_that_tab(): void
    {
        $t = $this->provisionTenant('Alpha');

        $reader = $this->userWithRole($t, 'Records only', ['hr.records.view']);

        $permissions = $this->permissionsOnMe($reader);

        $this->assertContains('hr.records.view', $permissions);

        foreach (array_diff(self::TABS, ['hr.records.view']) as $other) {
            $this->assertNotContains($other, $permissions,
                "A role granted only Records was also given {$other}.");
        }

        // The module key rides along — `withImpliedView` adds it beside any
        // granted `hr.*` key — and that is what puts Human Resources in their
        // navigation at all. Pinned because it is the reason the module can be
        // reachable with a single tab, which the screen depends on.
        $this->assertContains('hr.view', $permissions);
    }

    /**
     * A tab the organization cannot use is not offered to anybody.
     *
     * `ModuleAvailability` answers about the ORGANIZATION and runs before any
     * role is consulted, so this is the path the administrator test above
     * cannot reach: their keys come from the registry whole, and switching the
     * module off at the platform takes the tab from them too.
     *
     * Both people are asserted, and the administrator is the one that matters:
     * a filter applied to ordinary roles and skipped for administrators would
     * pass every other test in this file.
     */
    public function test_switching_hr_off_takes_the_tabs_from_everybody(): void
    {
        $t = $this->provisionTenant('Alpha');

        $reader = $this->userWithRole($t, 'Records only', ['hr.records.view']);

        // The control: it is there before the switch, so its absence after is
        // the switch and not the fixture.
        $this->assertContains('hr.records.view', $this->permissionsOnMe($reader));
        $this->assertContains('hr.records.view', $this->permissionsOnMe($t['admin']));

        app(ModuleAvailability::class)->setState('hr', 'disabled', 'a test switched it off');

        $this->assertNotContains('hr.records.view', $this->permissionsOnMe($reader));
        $this->assertNotContains(
            'hr.records.view',
            $this->permissionsOnMe($t['admin']),
            'An administrator was offered a tab from a module the platform has switched off.'
        );
    }
}
