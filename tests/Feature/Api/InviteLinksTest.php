<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\InviteLink;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Shareable invite links (Jose, 2026-11-23). */
class InviteLinksTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private ?Role $technician = null;

    private function technicianRole(array $t): Role
    {
        if ($this->technician !== null) {
            return $this->technician;
        }
        $tech = $this->userWithRole($t, 'Technician', ['customers.view'], 'seed-tech@alpha.test');

        return $this->technician = $this->inTenant($t['org'], fn () => $tech->role()->firstOrFail());
    }

    private function makeLink(array $t, array $extra = []): array
    {
        $role = $this->technicianRole($t);
        $data = $this->signedInAs($t['admin'])->postJson('/api/v1/hr/invite-links', [
            'role_id' => $role->id, 'scope_type' => 'franchise', 'scope_id' => $t['franchise']->id,
        ] + $extra)->assertCreated()->json('data');
        parse_str((string) parse_url($data['url'], PHP_URL_QUERY), $q);

        return [$data, $q['link'], $role];
    }

    private function join(string $link, string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/invite-links/join', [
            'link' => $link, 'first_name' => 'Nia', 'last_name' => 'Long', 'email' => $email,
            'phone' => '+15551234567', 'password' => 'a-long-enough-passphrase-9',
        ]);
    }

    public function test_a_person_joins_through_the_link_in_its_role_and_place(): void
    {
        Notification::fake();
        $t = $this->provisionTenant('Alpha');
        [$data, $link, $role] = $this->makeLink($t);
        $this->assertSame(14, (int) round(now()->diffInDays($data['expires_at'])));

        $this->postJson('/api/v1/invite-links/lookup', ['link' => $link])->assertOk()
            ->assertJsonPath('data.role', 'Technician')->assertJsonPath('data.open', true);

        $this->join($link, 'nia@example.test')->assertCreated();

        $user = $this->inTenant($t['org'], fn () => User::where('email', 'nia@example.test')->firstOrFail());
        $this->assertSame($role->id, $user->role_id);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email_verified_at, 'An address nobody proved stays unverified.');
        $this->assertSame([$t['franchise']->id], $this->inTenant($t['org'],
            fn () => Assignment::where('user_id', $user->id)->pluck('assigned_franchise_id')->all()));

        // HR sees the use and who joined.
        $row = $this->signedInAs($t['admin'])->getJson('/api/v1/hr/invite-links')->assertOk()->json('data.0');
        $this->assertSame(1, $row['uses']);
        $this->assertSame($user->id, $row['joined'][0]['user_id']);

        // The same address twice: sign in instead.
        $this->join($link, 'nia@example.test')->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_expired_full_revoked_and_admin_links_are_refused(): void
    {
        $t = $this->provisionTenant('Alpha');

        // Administrator can never be handed out by a link.
        $this->signedInAs($t['admin'])->postJson('/api/v1/hr/invite-links', [
            'role_id' => $t['admin']->role_id, 'scope_type' => 'franchise', 'scope_id' => $t['franchise']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');

        // Max uses.
        [, $one] = $this->makeLink($t, ['max_uses' => 1]);
        $this->join($one, 'a@example.test')->assertCreated();
        $this->join($one, 'b@example.test')->assertStatus(410);

        // Expired.
        [$expired, $old] = $this->makeLink($t, ['expires_in_days' => 1]);
        DB::table('invite_links')->where('id', $expired['id'])->update(['expires_at' => now()->subMinute()]);
        $this->join($old, 'c@example.test')->assertStatus(410);

        // Revoked, then extend refused; a revoked link stays refused.
        [$revoked, $off] = $this->makeLink($t);
        $this->signedInAs($t['admin'])->deleteJson("/api/v1/hr/invite-links/{$revoked['id']}")->assertOk()
            ->assertJsonPath('data.open', false);
        $this->join($off, 'd@example.test')->assertStatus(410);

        // A made-up token.
        $this->join('not-a-real-link', 'e@example.test')->assertStatus(410);

        // Nobody joined through the closed ones.
        $this->assertFalse($this->inTenant($t['org'], fn () => User::whereIn('email',
            ['b@example.test', 'c@example.test', 'd@example.test', 'e@example.test'])->exists()));
        $this->assertSame(1, $this->inTenant($t['org'], fn () => InviteLink::whereKey(
            InviteLink::query()->where('max_uses', 1)->value('id'))->value('uses')));
    }

    /** The authorization sweep's case b: same permissions, another place (desk, 2026-11-23). */
    public function test_a_link_outside_your_reach_is_not_found(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$data] = $this->makeLink($t);
        $elsewhere = $this->userWithRole($t, 'Branch HR', ['hr.view', 'hr.create', 'hr.edit', 'hr.invitations.view'],
            'branch-hr@alpha.test', onlyAt: $this->extraFranchise($t, 'Elsewhere'));

        $this->signedInAs($elsewhere)->patchJson("/api/v1/hr/invite-links/{$data['id']}", ['max_uses' => 5])->assertNotFound();
        $this->signedInAs($elsewhere)->deleteJson("/api/v1/hr/invite-links/{$data['id']}")->assertNotFound();
        $this->assertSame([], $this->signedInAs($elsewhere)->getJson('/api/v1/hr/invite-links')->assertOk()->json('data'));
        $this->assertNull($this->inTenant($t['org'], fn () => InviteLink::find($data['id'])->revoked_at));
    }

    public function test_only_people_who_may_invite_can_make_links(): void
    {
        $t = $this->provisionTenant('Alpha');
        $role = $this->technicianRole($t);
        $this->signedInAs($this->userWithRole($t, 'Reader', ['hr.view']))->postJson('/api/v1/hr/invite-links', [
            'role_id' => $role->id, 'scope_type' => 'franchise', 'scope_id' => $t['franchise']->id,
        ])->assertForbidden();
    }
}
