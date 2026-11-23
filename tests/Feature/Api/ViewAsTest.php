<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Mailbox;
use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use App\Models\Role;
use App\Models\User;
use App\Models\ViewAsSession;
use App\Support\Modules\PermissionCatalogue;
use App\Support\ViewAs\ViewAsUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * View as (Jose, 2026-11-23): a developer Administrator sees and uses the app
 * as a role at a place, or as a person, never wider than themselves.
 */
class ViewAsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $this->t = $this->provisionTenant('Alpha');
        $this->admin = $this->t['admin'];
        $this->makeDeveloper($this->admin);
        $this->token = $this->admin->createToken('test')->plainTextToken;
    }

    private function makeDeveloper(User $user): void
    {
        $staff = PlatformStaff::create([
            'name' => $user->name, 'email' => 'staff-'.$user->email,
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);
        $staff->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_confirmed_at' => now()])->save();
        PlatformStaffLink::create(['staff_id' => $staff->getKey(), 'user_id' => $user->getKey()]);
    }

    /** A real bearer token each time: View as is tied to the token. */
    private function as(?string $token = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token);
    }

    private function sibling(): array
    {
        $sibling = $this->extraFranchise($this->t, 'Alpha F2');
        $mailbox = fn ($scopeId, $address) => $this->inTenant($this->t['org'], fn () => Mailbox::create([
            'organization_id' => $this->t['org']->id,
            'scope_type' => Mailbox::SCOPE_FRANCHISE,
            'scope_id' => $scopeId,
            'address' => $address,
            'display_name' => 'Box',
            'provider' => 'imap',
        ]));

        return [$sibling, $mailbox($this->t['franchise']->id, 'own@alpha.test'), $mailbox($sibling->id, 'other@alpha.test')];
    }

    private function localManager(): User
    {
        return $this->userWithRole($this->t, 'Local Manager',
            ['email.view', 'email.edit', 'email.settings.view', 'email.inbox.view'],
            'nick@alpha.test', onlyAt: $this->t['franchise']);
    }

    // 1 ── the distinguishing case ─────────────────────────────────

    public function test_viewing_as_a_role_at_a_place_answers_as_that_role_there(): void
    {
        [, , $other] = $this->sibling();
        $nick = $this->localManager();
        $role = $this->inTenant($this->t['org'], fn () => $nick->fresh()->role);

        // Without View as, the Administrator reaches all three.
        $this->as()->getJson('/api/v1/customers')->assertOk();
        $this->as()->getJson('/api/v1/email/mailboxes')->assertOk();
        $this->as()->getJson("/api/v1/email/mailboxes/{$other->id}/people")->assertOk();

        $me = $this->as()->postJson('/api/v1/view-as', [
            'role_id' => $role->id, 'scope_type' => 'franchise', 'scope_id' => $this->t['franchise']->id,
        ])->assertCreated();
        $this->assertSame('role', $me->json('data.viewing_as.kind'));
        $this->assertSame($this->admin->id, $me->json('data.viewing_as.real.id'));

        $this->as()->getJson('/api/v1/customers')->assertForbidden();
        $this->as()->getJson('/api/v1/email/mailboxes')->assertOk();
        $this->as()->getJson("/api/v1/email/mailboxes/{$other->id}/people")->assertNotFound();

        $me = $this->as()->getJson('/api/v1/me')->assertOk();
        $expected = $this->inTenant($this->t['org'], fn () => app(PermissionCatalogue::class)->heldBy($nick->fresh()));
        $this->assertEqualsCanonicalizing($expected, $me->json('data.permissions'));
        $this->assertSame([$this->t['franchise']->id], array_column($me->json('data.franchises'), 'id'));
        $this->assertSame('franchise', $me->json('data.scope.type'));
        $this->assertTrue($me->json('data.view_as_available'));
    }

    // 2 ── a person ────────────────────────────────────────────────

    public function test_viewing_as_a_person_matches_what_they_get_themselves(): void
    {
        $this->sibling();
        $nick = $this->localManager();

        $nickToken = $nick->createToken('own')->plainTextToken;
        $own = $this->as($nickToken)->getJson('/api/v1/me')->assertOk()->json('data');

        $this->as()->postJson('/api/v1/view-as', ['user_id' => $nick->id])->assertCreated();
        $seen = $this->as()->getJson('/api/v1/me')->assertOk()->json('data');

        foreach (['id', 'permissions', 'modules', 'companies', 'franchises', 'scope', 'role'] as $key) {
            $this->assertEquals($own[$key], $seen[$key], $key);
        }
        $this->assertSame('person', $seen['viewing_as']['kind']);
        $this->assertSame('Local Manager', $seen['viewing_as']['label']);
        $this->assertNull($own['viewing_as']);
        $this->assertFalse($own['view_as_available']);
        // From the REAL actor while active, though the target's own is false.
        $this->assertTrue($seen['view_as_available']);
        $this->assertSame($this->admin->id, $seen['viewing_as']['real']['id']);
    }

    public function test_refused_targets_get_a_sentence(): void
    {
        $other = $this->provisionTenant('Beta');
        $inactive = $this->userWithRole($this->t, 'Gone', ['jobs.view'], 'gone@alpha.test');
        $this->inTenant($this->t['org'], fn () => $inactive->forceFill(['is_active' => false])->save());
        $adminRole = $this->inTenant($this->t['org'], fn () => $this->admin->fresh()->role);

        foreach ([
            ['user_id' => $this->admin->id],
            ['user_id' => $inactive->id],
            ['user_id' => $other['admin']->id],
            ['role_id' => $adminRole->id, 'scope_type' => 'organization'],
            ['role_id' => $inactive->role_id, 'scope_type' => 'franchise', 'scope_id' => $other['franchise']->id],
        ] as $body) {
            $r = $this->as()->postJson('/api/v1/view-as', $body)->assertStatus(422);
            $this->assertNotEmpty($r->json('message'), json_encode($body));
        }
        $this->assertSame(0, ViewAsSession::withoutGlobalScopes()->count());
    }

    // 3 ── who may ─────────────────────────────────────────────────

    public function test_only_a_developer_administrator_may_start(): void
    {
        $nick = $this->localManager();

        // An Administrator with no staff link.
        PlatformStaffLink::query()->delete();
        $this->as()->postJson('/api/v1/view-as', ['user_id' => $nick->id])->assertForbidden();
        $this->as()->getJson('/api/v1/view-as/options')->assertForbidden();
        $this->assertFalse($this->as()->getJson('/api/v1/me')->json('data.view_as_available'));

        // A developer who is not an Administrator.
        $dev = $this->userWithRole($this->t, 'Dispatcher', ['jobs.view'], 'dev@alpha.test');
        $this->makeDeveloper($dev);
        $devToken = $dev->createToken('t')->plainTextToken;
        $this->as($devToken)->postJson('/api/v1/view-as', ['user_id' => $nick->id])->assertForbidden();

        $this->assertSame(0, ViewAsSession::withoutGlobalScopes()->count());
    }

    public function test_options_list_the_organizations_roles_people_and_places(): void
    {
        $nick = $this->localManager();
        $this->provisionTenant('Beta');

        $data = $this->as()->getJson('/api/v1/view-as/options?q=nick')->assertOk()->json('data');
        $this->assertContains($nick->role_id, array_column($data['roles'], 'id'));
        $this->assertNotContains($this->admin->role_id, array_column($data['roles'], 'id'));
        $this->assertSame([$nick->id], array_column($data['people'], 'id'));
        $this->assertSame('Local Manager', $data['people'][0]['role_name']);
        $this->assertEqualsCanonicalizing(['organization', 'company', 'franchise'], array_unique(array_column($data['places'], 'type')));
        $this->assertNotContains('Beta F1', array_column($data['places'], 'label'));
    }

    // 4 ── audit ───────────────────────────────────────────────────

    public function test_start_writes_and_stop_are_audited_against_the_real_actor(): void
    {
        $nick = $this->localManager();

        $this->as()->postJson('/api/v1/view-as', ['user_id' => $nick->id])->assertCreated();
        $this->as()->patchJson('/api/v1/me/preferences', ['preferences' => ['theme' => 'dark']]);
        $this->as()->deleteJson('/api/v1/view-as')->assertOk()->assertJsonPath('data.viewing_as', null)
            ->assertJsonPath('data.id', $this->admin->id);

        $rows = AuditEvent::withoutGlobalScopes()->where('action', 'like', 'view_as.%')->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertEqualsCanonicalizing(['view_as.started', 'view_as.request', 'view_as.ended'], $rows->pluck('action')->unique()->values()->all());
        foreach ($rows as $row) {
            $this->assertSame($this->admin->id, $row->actor_id, $row->action);
        }
        $write = $rows->firstWhere('action', 'view_as.request');
        $this->assertSame('person', $write->metadata['viewing_as']['kind']);
        $this->assertSame($nick->id, $write->metadata['viewing_as']['id']);
        $this->assertSame('PATCH', $write->metadata['method']);

        // Nothing written during it names Nick as the actor.
        $this->assertSame(0, AuditEvent::withoutGlobalScopes()->where('actor_id', $nick->id)->count());
    }

    public function test_an_existing_audit_call_records_the_real_actor_and_who_was_viewed(): void
    {
        $nick = $this->localManager();
        $this->as()->postJson('/api/v1/view-as', ['user_id' => $nick->id])->assertCreated();

        $this->as()->postJson('/api/v1/auth/logout')->assertOk();

        $row = AuditEvent::withoutGlobalScopes()->where('action', 'auth.signed_out')->sole();
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertSame($nick->id, $row->metadata['viewing_as']['id']);
    }

    // 5 ── blocked routes ──────────────────────────────────────────

    public function test_security_and_developer_routes_are_refused_while_viewing(): void
    {
        $nick = $this->localManager();
        $this->as()->postJson('/api/v1/view-as', ['user_id' => $nick->id])->assertCreated();

        foreach ([
            ['POST', '/api/v1/me/password'],
            ['GET', '/api/v1/me/totp'],
            ['POST', '/api/v1/me/totp'],
            ['GET', '/api/v1/me/sms-codes'],
            ['GET', '/api/v1/me/api-keys'],
            ['POST', '/api/v1/me/api-keys'],
            ['GET', '/api/v1/me/sessions'],
            ['POST', '/api/v1/view-as'],
            ['GET', '/api/v1/view-as/options'],
            ['POST', '/api/v1/me/development/unlock'],
        ] as [$method, $uri]) {
            $this->as()->json($method, $uri, [])->assertForbidden();
        }
        $this->as()->deleteJson("/api/v1/hr/people/{$nick->id}")->assertNotFound();

        // Stopped, the same routes answer as usual again.
        $this->as()->deleteJson('/api/v1/view-as')->assertOk();
        $this->as()->getJson('/api/v1/me/api-keys')->assertOk();
    }

    // 6 ── expiry and sign-out ─────────────────────────────────────

    public function test_it_expires_and_ends_on_sign_out(): void
    {
        $nick = $this->localManager();
        $this->as()->postJson('/api/v1/view-as', ['user_id' => $nick->id])->assertCreated();
        $this->assertSame($nick->id, $this->as()->getJson('/api/v1/me')->json('data.id'));

        $this->travel(61)->minutes();
        $me = $this->as()->getJson('/api/v1/me')->assertOk();
        $this->assertSame($this->admin->id, $me->json('data.id'));
        $this->assertNull($me->json('data.viewing_as'));
        $this->assertNotNull(ViewAsSession::withoutGlobalScopes()->sole()->ended_at);
        $this->travelBack();

        $this->as()->postJson('/api/v1/view-as', ['user_id' => $nick->id])->assertCreated();
        $this->as()->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, ViewAsSession::withoutGlobalScopes()->whereNull('ended_at')->count());

        $fresh = $this->admin->createToken('again')->plainTextToken;
        $this->assertSame($this->admin->id, $this->as($fresh)->getJson('/api/v1/me')->json('data.id'));
    }

    public function test_it_ends_when_the_actor_loses_the_staff_link(): void
    {
        $nick = $this->localManager();
        $this->as()->postJson('/api/v1/view-as', ['user_id' => $nick->id])->assertCreated();

        PlatformStaffLink::query()->delete();

        $this->assertSame($this->admin->id, $this->as()->getJson('/api/v1/me')->json('data.id'));
        $this->assertNotNull(ViewAsSession::withoutGlobalScopes()->sole()->ended_at);
    }

    // 7 ── never wider than the actor ──────────────────────────────

    public function test_a_role_never_grants_what_the_actor_lacks(): void
    {
        $actor = $this->userWithRole($this->t, 'Narrow', ['jobs.view'], 'narrow@alpha.test');
        $wide = $this->inTenant($this->t['org'], function () {
            $role = Role::create(['organization_id' => $this->t['org']->id, 'name' => 'Wide', 'is_system' => false]);
            $role->syncPermissionKeys(['jobs.view', 'customers.view']);

            return $role;
        });

        $this->inTenant($this->t['org'], function () use ($actor, $wide) {
            $actor = $actor->fresh();
            $session = new ViewAsSession(['scope_type' => 'organization', 'expires_at' => now()->addHour()]);
            $clone = ViewAsUser::asRole($actor, $wide, 'organization', $this->t['org']->id, $session);

            $this->assertTrue($wide->hasPermission('customers.view'));
            $this->assertFalse($clone->hasPermission('customers.view'));
            $this->assertTrue($clone->hasPermission('jobs.view'));
            $held = app(PermissionCatalogue::class)->heldBy($clone);
            $this->assertNotContains('customers.view', $held);
            $this->assertContains('jobs.view', $held);
        });
    }
}
