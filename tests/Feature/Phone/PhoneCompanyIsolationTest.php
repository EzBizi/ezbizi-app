<?php

namespace Tests\Feature\Phone;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\PhoneConnection;
use App\Models\PhoneFlow;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneRingGroup;
use App\Models\PhoneVoicemailBox;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * One company must not see another company's phone settings.
 *
 * ── The defect this pins ────────────────────────────────────────
 *
 * Numbers carried scope_type/scope_id from the start. The things a number
 * POINTS AT — ring groups, opening hours, IVR menus, voicemail boxes — hung
 * off `connection_id` alone, and a connection is deliberately shared:
 * ConnectionResolver walks franchise → company → organization, so a business
 * running one Twilio account at organization level has EVERY company resolving
 * to the same connection.
 *
 * So a ring group made for A-1 Chimney appeared under a sibling company. The
 * missing thing was not a filter — it was the column.
 *
 * Two of the four listed by REACH rather than by the current view, which leaks
 * even when the scope is right; PhoneFlow's index had no filter whatsoever.
 *
 * ── Why a sibling, specifically ─────────────────────────────────
 *
 * The rules desk asks for a sibling company in the fixture, and this is why:
 * a test with one company passes against a module that shares everything,
 * because there is nobody to leak to.
 */
class PhoneCompanyIsolationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_company_does_not_see_a_siblings_phone_settings(): void
    {
        $t = $this->provisionTenant();

        [$sibling, $connection] = $this->inTenant($t['org'], function () use ($t) {
            $sibling = Company::query()->create([
                'organization_id' => $t['org']->id,
                'name' => 'Everdoors Co',
            ]);

            // ONE connection at organization level — the setup that makes the
            // defect possible, and the ordinary one for a small business.
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            return [$sibling, $connection];
        });

        // Everything below belongs to A-1 Chimney, the tenant's own company.
        $this->inTenant($t['org'], function () use ($t, $connection) {
            PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'name' => 'A-1 Chimney Ring Group',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            PhoneHoursSchedule::query()->create([
                'connection_id' => $connection->id,
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'name' => 'A-1 Hours',
                'timezone' => 'America/New_York',
                'weeks' => ['mon' => [['09:00', '17:00']]],
                'exceptions' => null,
            ]);

            PhoneFlow::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'name' => 'A-1 Menu',
                'nodes' => ['type' => 'menu', 'prompt' => 'Press one.', 'options' => []],
            ]);

            PhoneVoicemailBox::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'name' => 'A-1 Voicemail',
                'greeting_text' => 'You have reached A-1 Chimney.',
            ]);
        });

        // Assigned to the SIBLING company only — not the organization,
        // because an organization assignment legitimately sees everything
        // beneath it and would prove nothing here.
        $outsider = $this->inTenant($t['org'], function () use ($t, $sibling) {
            $role = Role::query()->create([
                'organization_id' => $t['org']->id,
                'name' => 'Everdoors phone',
                'is_system' => false,
            ]);
            $role->syncPermissionKeys(['phone.view', 'phone.settings.view', 'phone.edit']);

            $user = User::query()->create([
                'organization_id' => $t['org']->id,
                'role_id' => $role->id,
                'name' => 'Everdoors phone',
                'email' => 'everdoors@'.$t['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);

            Assignment::query()->create([
                'organization_id' => $t['org']->id,
                'user_id' => $user->id,
                'assigned_company_id' => $sibling->id,
            ]);

            return $user;
        });

        foreach ([
            '/api/v1/phone/ring-groups' => 'ring group',
            '/api/v1/phone/hours' => 'opening hours',
            '/api/v1/phone/flows' => 'IVR menu',
            '/api/v1/phone/voicemail-boxes' => 'voicemail box',
        ] as $endpoint => $what) {
            $data = $this->signedInAs($outsider)->getJson($endpoint)->assertOk()->json('data');

            $this->assertSame(
                [],
                $data,
                "Everdoors can see A-1 Chimney's {$what} on {$endpoint}",
            );
        }
    }

    /**
     * An organization VIEW is not organization-wide AUTHORITY.
     *
     * ── The case the sibling test cannot express ────────────────
     *
     * ViewScope::forUser falls back to an organization view whenever no
     * navigation preference is stored, which is the state every user is in
     * until they touch the switcher. So "the view says organization" tells you
     * nothing about what this person may actually reach.
     *
     * Somebody assigned to two companies out of three is exactly that: their
     * view resolves to the organization, and the third company is still none
     * of their business. Filtering on the view alone would hand it to them.
     *
     * PhoneScope::apply short-circuits ONLY when the view is organization AND
     * the user can act on the whole organization; otherwise it falls through
     * to the reachable ids. This test is what holds that second condition in
     * place — drop it and the third company's menu appears.
     */
    public function test_an_organization_view_still_stops_at_the_edge_of_reach(): void
    {
        $t = $this->provisionTenant();

        [$reachable, $outOfReach, $theirs, $notTheirs] = $this->inTenant($t['org'], function () use ($t) {
            $reachable = Company::query()->create([
                'organization_id' => $t['org']->id,
                'name' => 'Everdoors Co',
            ]);

            $outOfReach = Company::query()->create([
                'organization_id' => $t['org']->id,
                'name' => 'Third Co',
            ]);

            // Each company keeps its own phone account, so reach is the only
            // thing that can tell them apart.
            $theirs = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $reachable->id,
            ]);

            $notTheirs = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $outOfReach->id,
            ]);

            foreach ([[$theirs, $reachable, 'Everdoors Menu'], [$notTheirs, $outOfReach, 'Third Menu']] as [$c, $company, $name]) {
                PhoneFlow::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $c->id,
                    'scope_type' => 'company',
                    'scope_id' => $company->id,
                    'name' => $name,
                    'nodes' => ['type' => 'menu', 'prompt' => 'Press one.', 'options' => []],
                ]);
            }

            return [$reachable, $outOfReach, $theirs, $notTheirs];
        });

        // Assigned to A-1 Chimney AND Everdoors — two companies. Since
        // 2026-10-16 (access only downwards) that is not the organization
        // view: they stand in one of their companies at a time.
        $user = $this->inTenant($t['org'], function () use ($t, $reachable) {
            $role = Role::query()->create([
                'organization_id' => $t['org']->id,
                'name' => 'Two companies',
                'is_system' => false,
            ]);
            $role->syncPermissionKeys(['phone.view', 'phone.settings.view', 'phone.edit']);

            $user = User::query()->create([
                'organization_id' => $t['org']->id,
                'role_id' => $role->id,
                'name' => 'Two companies',
                'email' => 'two@'.$t['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);

            foreach ([$t['company']->id, $reachable->id] as $companyId) {
                Assignment::query()->create([
                    'organization_id' => $t['org']->id,
                    'user_id' => $user->id,
                    'assigned_company_id' => $companyId,
                ]);
            }

            return $user;
        });

        $names = collect(
            $this->signedInAs($user)
                ->withHeader('X-EzBizi-View', 'company:'.$reachable->id)
                ->getJson('/api/v1/phone/flows')->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertContains('Everdoors Menu', $names);
        $this->assertNotContains(
            'Third Menu',
            $names,
            'an organization view showed a company this person has no reach over',
        );
    }
}
