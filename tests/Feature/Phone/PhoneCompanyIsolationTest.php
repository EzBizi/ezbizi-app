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
            $role->syncPermissionKeys(['phone.view', 'phone.edit']);

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
}
