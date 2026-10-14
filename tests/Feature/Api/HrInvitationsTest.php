<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\HrInvitationsController;
use App\Models\AuditEvent;
use App\Models\Assignment;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Invitation;
use App\Models\PlatformModuleFlag;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationLink;
use App\Services\Hr\Invitations;
use App\Support\Automations\AutomationEngine;
use App\Support\Settings\SettingsResolver;
use App\Support\Modules\ModuleAvailability;
use App\Support\Access\Refusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Inviting somebody, and them accepting (EZBIZI-HR-PLAN.md, §4).
 *
 * Everything here is being tested for the first time, because until this module
 * there was no code path that could reach any of it: nothing wrote
 * `users.role_id`, so an organization was permanently its founding
 * Administrator and every rule about giving somebody a role was unreachable.
 */
class HrInvitationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── the round trip ───────────────────────────────────────────

    public function test_an_invitation_can_be_sent_and_accepted_into_a_working_session(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $sent = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => 'newstarter@example.test',
            'first_name' => 'Marta',
            'last_name'  => 'Ruiz',
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ])->assertCreated();

        $sent->assertJsonPath('data.status', 'sent');
        $sent->assertJsonPath('data.email', 'newstarter@example.test');

        $token = $this->tokenFrom($sent->json('data.accept_url'));

        // The public side: no session, no tenant, and it still knows enough to
        // draw the screen. Signed out first, and it matters — Sanctum::actingAs
        // holds the inviter on the guard for the rest of the test, so without
        // this the "unauthenticated" calls below would be made as the
        // administrator and the session assertion at the end would be checking
        // their token rather than the new one.
        $this->signedOut()->postJson('/api/v1/invitations/lookup', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.organization.name', 'Alpha')
            ->assertJsonPath('data.role.name', 'Dispatcher')
            ->assertJsonPath('data.invited_by.name', 'Alpha Admin')
            ->assertJsonPath('data.first_name', 'Marta');

        $accepted = $this->signedOut()->postJson('/api/v1/invitations/accept', ['token' => $token] + [
            'password' => 'a-real-password',
            'phone'    => '+34 600 000 000',
        ])->assertCreated();

        // The same body login returns, because the client treats acceptance as
        // a sign-in.
        $accepted->assertJsonPath('status', 'authenticated');
        $accepted->assertJsonStructure(['token', 'user' => ['id', 'name', 'email'], 'organization']);

        // And the token it hands back is a real session.
        $this->signedOut()->withToken($accepted->json('token'))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role.name', 'Dispatcher')
            ->assertJsonPath('data.organization.name', 'Alpha');
    }

    public function test_the_response_says_whether_the_invitation_email_actually_went(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $body = [
            'first_name' => 'Marta',
            'last_name'  => 'Ruiz',
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ];

        // THE ORDER OF THESE THREE IS LOAD-BEARING and cost me a red build.
        // The delivering case needs Notification::fake, and a fake cannot be
        // lifted — with it in place the broken-transport case below never
        // throws, so it returned "it went" and the assertion that catches a
        // real misconfiguration could not fail. The two cases that must reach
        // a real transport run FIRST; the faked one runs last, where it can
        // leak into nothing.

        // A transport that throws nothing and delivers nothing.
        //
        // phpunit runs on `array`, and this assertion used to read `true` here
        // with the comment "so this one genuinely sends". That was simply
        // wrong: `array` collects messages and delivers none. The assertion
        // was green and the sentence it protects — "the invitation has been
        // emailed to them" — was false on every box configured that way, which
        // is every demo and staging box somebody tests invitations on. Found
        // by UI/UX (2026-09-14) as the fourth state their email vocabulary was
        // missing. Nothing goes wrong here, so there is no exception to catch;
        // the inviter has to hand the link over exactly as if there had been.
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', ['email' => 'nowhere@example.test'] + $body)
            ->assertCreated()
            ->assertJsonPath('data.emailed', false)
            ->assertJsonPath('data.status', 'sent');

        // A transport that cannot be constructed at all, which is the cheapest
        // honest failure: no network, no sleeping retry, and it exercises the
        // same catch a 554 from a sandboxed SES arrives through.
        config(['mail.default' => 'a-mailer-that-does-not-exist']);

        // Still created. A mail failure must not roll back a perfectly good
        // invitation whose link is about to be handed back.
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', ['email' => 'second@example.test'] + $body)
            ->assertCreated()
            ->assertJsonPath('data.emailed', false)
            ->assertJsonPath('data.status', 'sent');

        // And last, the only case that may claim it went: a transport that
        // DELIVERS, with every clause of MailStatus::delivers satisfied —
        // it asks three questions and a box failing any one of them sends
        // nothing. Faked so nothing is actually dialled, which is why it is
        // last. Without this the file would only prove the flag can be false,
        // and a deliver() hardwired to false would pass everything above.
        config([
            'mail.default'               => 'smtp',
            'mail.mailers.smtp.username' => 'ezbizi',
            'mail.from.address'          => 'noreply@ezbizi.test',
        ]);
        Notification::fake();

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', ['email' => 'first@example.test'] + $body)
            ->assertCreated()
            ->assertJsonPath('data.emailed', true);

        Notification::assertSentOnDemand(InvitationLink::class);
    }

    /**
     * The wire carries a STATE, not a yes/no, and `emailed` stays beside it.
     *
     * The screen draws a strip whose sentence and action differ per state
     * (Jose picked desktop 2 / mobile 5 on 2026-09-14), so a bool cannot say
     * enough: "we did not even try, this box sends nothing" and "we tried and
     * were refused" are two different sentences with two different remedies.
     * `emailed` is kept rather than replaced because an installed client older
     * than this release still reads it, and a field going absent is the one
     * response change that breaks somebody who asked for none of this.
     */
    public function test_the_send_state_is_on_the_wire_and_the_old_flag_still_agrees_with_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $body = [
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ];

        // Same ordering rule as the test above: the faked delivering case last,
        // because Notification::fake cannot be lifted and would stop the
        // refused case from ever throwing.
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', ['email' => 'nowhere@example.test'] + $body)
            ->assertCreated()
            ->assertJsonPath('data.sent_state', HrInvitationsController::NOT_SENT_NO_TRANSPORT)
            ->assertJsonPath('data.emailed', false);

        config(['mail.default' => 'a-mailer-that-does-not-exist']);

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', ['email' => 'refused@example.test'] + $body)
            ->assertCreated()
            ->assertJsonPath('data.sent_state', HrInvitationsController::NOT_SENT_REFUSED)
            ->assertJsonPath('data.emailed', false);

        config([
            'mail.default'               => 'smtp',
            'mail.mailers.smtp.username' => 'ezbizi',
            'mail.from.address'          => 'noreply@ezbizi.test',
        ]);
        Notification::fake();

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', ['email' => 'went@example.test'] + $body)
            ->assertCreated()
            ->assertJsonPath('data.sent_state', HrInvitationsController::SENT)
            ->assertJsonPath('data.emailed', true);
    }

    /**
     * Correcting a mistyped address is a REISSUE, and the old link dies with it.
     *
     * The whole reason it is not a PATCH. The row and the credential are
     * different things: repointing the row changes where the next message goes
     * and cannot recall the one already sent, so if the typo'd address belongs
     * to a real person, that person keeps a live invitation into somebody
     * else's organization while the screen says it is fixed.
     *
     * Asserted on the TOKEN rather than on the response wording, because the
     * wording is what would be written to match a broken implementation.
     */
    public function test_re_inviting_at_a_different_address_moves_it_and_kills_the_old_link(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $created = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', [
                'email'      => 'bea@exampel.test',
                'role_id'    => $role->id,
                'scope_type' => 'franchise',
                'scope_id'   => $alpha['franchise']->id,
            ])->assertCreated();

        $id       = $created->json('data.id');
        $deadLink = $created->json('data.accept_url');

        $again = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/invitations/{$id}/resend", ['email' => 'bea@example.test'])
            ->assertOk()
            ->assertJsonPath('data.email', 'bea@example.test');

        $this->assertNotSame($deadLink, $again->json('data.accept_url'));

        // The stranger's link is dead, and this is the assertion the whole
        // design rests on: the token, not the sentence about the token.
        $this->inTenant($alpha['org'], function () use ($deadLink, $id) {
            $this->assertNull(
                app(Invitations::class)->open($this->tokenFrom($deadLink)),
                'The link sent to the mistyped address still works.',
            );

            $this->assertSame('bea@example.test', Invitation::findOrFail($id)->email);
        });

        // One row, moved — not a second invitation left behind for the typo.
        $this->inTenant($alpha['org'], fn () => $this->assertSame(
            1,
            Invitation::query()->count(),
            'Re-inviting created a second invitation instead of moving the one.',
        ));

        $this->inTenant($alpha['org'], fn () => $this->assertDatabaseHas('audit_events', [
            'action' => 'invitation.readdressed',
        ]));
    }

    /** Resending to the SAME address is not a re-address, and says so. */
    public function test_resending_unchanged_is_still_a_resend(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $id = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', [
                'email'      => 'same@example.test',
                'role_id'    => $role->id,
                'scope_type' => 'franchise',
                'scope_id'   => $alpha['franchise']->id,
            ])->assertCreated()->json('data.id');

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/invitations/{$id}/resend", ['email' => 'same@example.test'])
            ->assertOk();

        $this->inTenant($alpha['org'], fn () => $this->assertDatabaseMissing('audit_events', [
            'action' => 'invitation.readdressed',
        ]));
    }

    /** And it refuses the same address a fresh invitation would refuse. */
    public function test_re_inviting_at_an_address_that_already_has_one_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $body = [
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ];

        $first = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', ['email' => 'taken@example.test'] + $body)
            ->assertCreated()->json('data.id');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', ['email' => 'other@example.test'] + $body)
            ->assertCreated();

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/invitations/{$first}/resend", ['email' => 'other@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_lookup_carries_the_organization_date_format(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $this->inTenant($alpha['org'], function () use ($alpha) {
            app(SettingsResolver::class)->set('date_format', $alpha['org'], 'D/M/YYYY');
        });

        $sent = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => 'newstarter@example.test',
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ])->assertCreated();

        $this->signedOut()->postJson('/api/v1/invitations/lookup', [
            'token' => $this->tokenFrom($sent->json('data.accept_url')),
        ])
            ->assertOk()
            ->assertJsonPath('data.display.date_format', 'D/M/YYYY')
            ->assertJsonPath('data.display.time_format', '12h');
    }

    public function test_accepting_grants_exactly_the_role_and_the_scope_on_the_invitation(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $role   = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);
        $second = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Alpha F2',
        ]));

        $this->accept($this->invite($alpha, [
            'role_id' => $role->id, 'scope_type' => 'franchise', 'scope_id' => $second->id,
        ]));

        $this->inTenant($alpha['org'], function () use ($role, $second) {
            $user = User::where('email', 'newstarter@example.test')->firstOrFail();

            $this->assertSame($role->id, $user->role_id);
            $this->assertTrue($user->is_active);

            $assignments = Assignment::where('user_id', $user->id)->get();

            // Exactly one, at exactly the level the invitation named — §4's XOR
            // carried across rather than reinterpreted.
            $this->assertCount(1, $assignments);
            $this->assertSame(Assignment::SCOPE_FRANCHISE, $assignments[0]->scopeType());
            $this->assertSame($second->id, $assignments[0]->scopeId());

            // Reach follows from it, and only it.
            $this->assertTrue($user->fresh()->canActOnFranchise($second));
        });
    }

    public function test_the_invitation_is_completed_and_cannot_be_used_twice(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $token = $this->invite($alpha);

        $this->accept($token);

        $this->inTenant($alpha['org'], function () {
            $invitation = Invitation::firstOrFail();

            $this->assertSame(Invitation::COMPLETED, $invitation->status());
            $this->assertNotNull($invitation->accepted_user_id);
        });

        // Reused: refused, and refused identically to a token that never existed.
        $this->acceptWith($token, ['password' => 'a-real-password'])
            ->assertNotFound()
            ->assertJsonPath('code', 'invalid_invitation');
    }

    // ── the token is a credential ────────────────────────────────

    public function test_the_token_is_never_stored_in_the_clear(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $token = $this->invite($alpha);

        $this->inTenant($alpha['org'], function () use ($token) {
            $row = Invitation::firstOrFail();

            $this->assertNotSame($token, $row->token_hash);
            $this->assertSame(hash('sha256', $token), $row->token_hash);

            // Nowhere else on the row either — a "copy of the link for
            // convenience" column would undo the whole point.
            $this->assertStringNotContainsString($token, json_encode($row->getAttributes()));
        });

        // Nor in the audit trail, which is read by more people than a
        // credential should be.
        $this->assertStringNotContainsString(
            $token,
            (string) json_encode(AuditEvent::withoutGlobalScopes()->get()->toArray()),
        );
    }

    /** The list's search box (Jose, 2026-10-14): email, either name, or both. */
    public function test_the_list_searches_by_email_and_name(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->invite($alpha, ['email' => 'marta@example.test', 'first_name' => 'Marta', 'last_name' => 'Ruiz']);
        $this->invite($alpha, ['email' => 'leo@example.test', 'first_name' => 'Leo', 'last_name' => 'Brandt']);

        $found = fn (string $term) => collect($this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/hr/invitations?search='.urlencode($term))->assertOk()->json('data'))
            ->pluck('email')->all();

        $this->assertSame(['marta@example.test'], $found('Marta'));
        $this->assertSame(['marta@example.test'], $found('marta ruiz'));
        $this->assertSame(['leo@example.test'], $found('leo@'));
        $this->assertSame([], $found('Okafor'));
        $this->assertSame(1, $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/hr/invitations?search=Brandt')->json('meta.tiles.sent'));

        // Narrows what the reader may reach, never widens it (HR, 2026-10-14).
        $second = $this->inTenant($alpha['org'], fn () => \App\Models\Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Alpha F2',
        ]));
        $elsewhere = $this->userWithRole($alpha, 'Branch Manager', ['hr.view', 'hr.invitations.view'], onlyAt: $second);
        $this->assertSame([], collect($this->signedInAs($elsewhere)
            ->getJson('/api/v1/hr/invitations?search=Marta')->assertOk()->json('data'))->all());
    }

    public function test_the_list_never_carries_a_token_or_a_link(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $token = $this->invite($alpha);

        $body = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/hr/invitations')->assertOk()->json();

        $encoded = (string) json_encode($body);

        $this->assertStringNotContainsString($token, $encoded);
        $this->assertStringNotContainsString('accept_url', $encoded);
        $this->assertStringNotContainsString('token_hash', $encoded);
    }

    // ── every dead token answers the same ────────────────────────

    /**
     * The four ways an invitation can be dead, and a fifth that never existed.
     *
     * Asserted as one test rather than four, because the property being tested
     * is that the five answers are INDISTINGUISHABLE — which is a statement
     * about the set, not about any member of it.
     */
    public function test_wrong_expired_revoked_and_used_tokens_are_refused_identically(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $expired = $this->invite($alpha, ['email' => 'expired@example.test']);
        $this->inTenant($alpha['org'], fn () => Invitation::where('email', 'expired@example.test')
            ->firstOrFail()->forceFill(['expires_at' => now()->subDay()])->save());

        $revoked = $this->invite($alpha, ['email' => 'revoked@example.test']);
        $this->inTenant($alpha['org'], fn () => Invitation::where('email', 'revoked@example.test')
            ->firstOrFail()->forceFill(['revoked_at' => now()])->save());

        $used = $this->invite($alpha, ['email' => 'used@example.test']);
        $this->accept($used, 'used@example.test');

        $answers = [];

        foreach (['nonexistent-token-that-is-not-real', $expired, $revoked, $used] as $token) {
            foreach (['getJson', 'postJson'] as $verb) {
                $response = $verb === 'getJson'
                    ? $this->lookup($token)
                    : $this->acceptWith($token, ['password' => 'a-real-password']);

                $answers[] = [$response->status(), $response->json()];
            }
        }

        $this->assertCount(8, $answers);
        $this->assertSame([404, ['message' => $answers[0][1]['message'], 'code' => 'invalid_invitation']],
            $answers[0]);

        // One distinct answer across all eight. If any branch ever starts saying
        // "already used", this is the line that fails.
        $this->assertCount(1, array_unique(array_map('serialize', $answers)));
    }

    public function test_the_public_endpoints_are_throttled(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $token = $this->invite($alpha);

        // The named limiter counts every request that reaches the route, so a
        // wrong token spends budget too — which is the point of it.
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/invitations/lookup', ['token' => 'whatever-'.$i]);
        }

        $this->lookup($token)
            ->assertStatus(429)
            ->assertJsonPath('code', 'throttled')
            ->assertJsonPath('message', fn ($m) => is_string($m) && $m !== '');
    }

    // ── reach (§4) ───────────────────────────────────────────────

    public function test_inviting_outside_your_reach_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        // A second franchise under a company this manager has nothing to do with.
        $elsewhere = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id'      => $company->id,
                'name'            => 'Other F1',
            ]);
        });

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.invitations.view', 'hr.create', 'customers.view'],
            onlyAt: $alpha['franchise'],
        );

        $this->signedInAs($manager)->postJson('/api/v1/hr/invitations', [
            'email'      => 'someone@example.test',
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $elsewhere->id,
        ])->assertStatus(422)->assertJsonValidationErrors('scope_id');

        // Their own franchise is fine, so the refusal above was about reach and
        // not about the permission.
        $this->signedInAs($manager)->postJson('/api/v1/hr/invitations', [
            'email'      => 'someone@example.test',
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ])->assertCreated();
    }

    public function test_a_franchise_manager_cannot_assign_at_organization_level(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.invitations.view', 'hr.create', 'customers.view'],
            onlyAt: $alpha['franchise'],
        );

        // "Same level or downwards": the organization is above them, so nobody
        // beneath it may hand out reach over the whole of it.
        $this->signedInAs($manager)->postJson('/api/v1/hr/invitations', [
            'email'      => 'someone@example.test',
            'role_id'    => $role->id,
            'scope_type' => 'organization',
            'scope_id'   => $alpha['org']->id,
        ])->assertStatus(422)->assertJsonValidationErrors('scope_id');
    }

    public function test_you_cannot_invite_somebody_into_a_role_that_reaches_further_than_yours(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $manager = $this->userWithRole($alpha, 'Branch Manager', ['hr.view', 'hr.invitations.view', 'hr.create']);
        $adminRole = $this->inTenant($alpha['org'], fn () => $alpha['admin']->role);

        // Handing out Administrator grants everything without naming anything —
        // the escalation the roles editor already refuses, through another door.
        $this->signedInAs($manager)->postJson('/api/v1/hr/invitations', [
            'email'      => 'someone@example.test',
            'role_id'    => $adminRole->id,
            'scope_type' => 'organization',
            'scope_id'   => $alpha['org']->id,
        ])->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_the_scope_options_offer_only_what_the_caller_may_assign(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.invitations.view', 'hr.create', 'customers.view'],
            onlyAt: $alpha['franchise'],
        );

        $body = $this->signedInAs($manager)
            ->getJson('/api/v1/hr/people/scope-options')->assertOk()->json('data');

        // Nothing above them.
        $this->assertNull($body['organization']);
        $this->assertSame([], $body['companies']);
        $this->assertSame([$alpha['franchise']->id], array_column($body['franchises'], 'id'));

        $roles = collect($body['roles'])->keyBy('name');

        // Every role listed, with the ones they cannot hand out marked rather
        // than hidden — the same call grantableTreeFor makes about actions.
        $this->assertFalse($roles['Administrator']['assignable']);
        $this->assertTrue($roles['Dispatcher']['assignable']);
        // Their own role IS assignable, and should be: the bound is "no more
        // than you hold", and hiring somebody to do your own job is the ordinary
        // case rather than an escalation.
        $this->assertTrue($roles['Branch Manager']['assignable']);
    }

    public function test_the_invitation_list_is_filtered_to_the_callers_reach(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $elsewhere = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id'      => $company->id,
                'name'            => 'Other F1',
            ]);
        });

        $this->invite($alpha, ['email' => 'mine@example.test',
            'role_id' => $role->id, 'scope_id' => $alpha['franchise']->id]);
        $this->invite($alpha, ['email' => 'theirs@example.test',
            'role_id' => $role->id, 'scope_id' => $elsewhere->id]);

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.invitations.view', 'customers.view'], onlyAt: $alpha['franchise'],
        );

        $emails = $this->signedInAs($manager)->getJson('/api/v1/hr/invitations')
            ->assertOk()->json('data.*.email');

        $this->assertSame(['mine@example.test'], $emails);
    }

    // ── an invitation belongs to the ORGANIZATION (decided 2026-08-11) ──

    /**
     * The three tests in this section asserted the opposite rule until today.
     *
     * They were right about the code and wrong about the product: acceptance
     * re-checked the INVITER's role-granting ability and reach, so a manager
     * being demoted, switched off, or moved to a smaller patch killed every link
     * they had outstanding. A new starter was turned away because of a change on
     * the other side of a business they had not joined yet.
     *
     * They are kept, inverted, rather than deleted. Each one still exercises the
     * exact path that used to refuse — which is what makes them a guard against
     * the old rule coming back rather than three tests that simply stopped
     * existing.
     */
    public function test_an_invitation_from_somebody_since_demoted_is_still_honoured(): void
    {
        $alpha   = $this->provisionTenant('Alpha');
        $role    = $this->roleIn($alpha, 'Dispatcher', ['customers.view', 'customers.edit']);
        $manager = $this->userWithRole(
            $alpha, 'Branch Manager',
            ['hr.view', 'hr.invitations.view', 'hr.create', 'customers.view', 'customers.edit'],
        );

        $token = $this->inviteAs($manager, $alpha, [
            'role_id' => $role->id, 'scope_type' => 'organization', 'scope_id' => $alpha['org']->id,
        ]);

        // A week passes and the manager loses the permission the invited role
        // carries — so they could not send this invitation today. The
        // organization can still offer it, which is the question that counts.
        $this->inTenant($alpha['org'], fn () => $manager->role->syncPermissionKeys(
            ['hr.view', 'hr.invitations.view', 'hr.create', 'customers.view'],
        ));

        $this->acceptWith($token, ['password' => 'a-real-password'])->assertCreated();

        // And with exactly the role the invitation named. The demotion does not
        // quietly trim what the new person gets either — that would be the same
        // bug wearing a friendlier face.
        $this->inTenant($alpha['org'], function () use ($role) {
            $user = User::where('email', 'newstarter@example.test')->firstOrFail();

            $this->assertSame($role->id, $user->role_id);
        });
    }

    public function test_an_invitation_from_somebody_since_deactivated_is_still_honoured(): void
    {
        $alpha   = $this->provisionTenant('Alpha');
        $role    = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);
        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.invitations.view', 'hr.create', 'customers.view'],
        );

        $token = $this->inviteAs($manager, $alpha, [
            'role_id' => $role->id, 'scope_type' => 'organization', 'scope_id' => $alpha['org']->id,
        ]);

        // The person who invited them has left. This is the case the old rule
        // got most visibly wrong: the manager's last act before leaving was
        // hiring somebody, and it stopped working the moment they were switched
        // off.
        $this->inTenant($alpha['org'], fn () => $manager->forceFill(['is_active' => false])->save());

        $this->acceptWith($token, ['password' => 'a-real-password'])->assertCreated();
    }

    /**
     * And the inviter gone from the row entirely.
     *
     * `invited_by_user_id` is ON DELETE SET NULL, and the old rule read "no
     * inviter left" as "nobody stands behind this". The organization does.
     */
    public function test_an_invitation_whose_inviter_is_gone_from_the_row_is_still_honoured(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $token = $this->invite($alpha);

        $this->inTenant($alpha['org'], fn () => Invitation::firstOrFail()
            ->forceFill(['invited_by_user_id' => null])->save());

        $this->acceptWith($token, ['password' => 'a-real-password'])->assertCreated();
    }

    // ── what the ORGANIZATION must still be able to offer ────────

    public function test_an_invitation_into_a_franchise_since_switched_off_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $token = $this->invite($alpha, [
            'role_id' => $role->id, 'scope_type' => 'franchise',
            'scope_id' => $alpha['franchise']->id,
        ]);

        $this->inTenant($alpha['org'], fn () => $alpha['franchise']
            ->forceFill(['is_active' => false])->save());

        $this->acceptWith($token, ['password' => 'a-real-password'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invitation_superseded')
            // The sentence has to name the thing that went wrong, or the person
            // holding the link has no idea what to ask about.
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'franchise'));

        $this->inTenant($alpha['org'], fn () => $this->assertNull(
            User::where('email', 'newstarter@example.test')->first(),
        ));
    }

    /**
     * And one that has been removed rather than switched off.
     *
     * Franchises are soft-deleted, so the foreign key's ON DELETE CASCADE never
     * fires: the invitation row survives, pointing at a `deleted_at`. Without
     * the existence half of the check this would create an assignment to a
     * franchise that is not in any list.
     */
    public function test_an_invitation_into_a_franchise_since_removed_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $token = $this->invite($alpha, [
            'role_id' => $role->id, 'scope_type' => 'franchise',
            'scope_id' => $alpha['franchise']->id,
        ]);

        $this->inTenant($alpha['org'], fn () => $alpha['franchise']->delete());

        $this->acceptWith($token, ['password' => 'a-real-password'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invitation_superseded');
    }

    /**
     * §13, at the only line where it should refuse: the platform has switched
     * off every module behind the invited role, so the account would sign in and
     * reach nothing.
     */
    public function test_an_invitation_is_refused_when_the_platform_has_emptied_its_role(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $token = $this->invite($alpha, ['role_id' => $role->id]);

        app(ModuleAvailability::class)->setState(
            'customers', PlatformModuleFlag::DISABLED, reason: 'Incident 41.',
        );

        $this->acceptWith($token, ['password' => 'a-real-password'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invitation_superseded');
    }

    /**
     * And the line NOT drawn one step earlier, which is the part worth pinning.
     *
     * A role that touches a switched-off module alongside a live one is still
     * worth having: the same thing happens to every employee already here, whose
     * permissions are filtered by availability without their account being taken
     * away. `beta` is the ordinary rollout state, so the stricter rule would
     * kill every outstanding invitation for a switch the organization cannot see.
     */
    public function test_a_role_that_keeps_one_available_permission_is_still_honoured(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view', 'jobs.view']);

        $token = $this->invite($alpha, ['role_id' => $role->id]);

        app(ModuleAvailability::class)->setState(
            'jobs', PlatformModuleFlag::DISABLED, reason: 'Incident 41.',
        );

        $this->acceptWith($token, ['password' => 'a-real-password'])->assertCreated();
    }

    /**
     * A role with nothing on it is the organization's own choice, unchanged.
     *
     * It grants nothing before and after, so "the platform has withdrawn
     * everything" must not be true of it — otherwise an invitation that was
     * perfectly valid when it was sent is refused for a condition nobody
     * changed.
     */
    public function test_an_invitation_into_a_role_holding_no_permissions_is_honoured(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Nothing Yet', []);

        $this->acceptWith(
            $this->invite($alpha, ['role_id' => $role->id]),
            ['password' => 'a-real-password'],
        )->assertCreated();
    }

    // ── §4: email is unique PER organization ─────────────────────

    public function test_the_same_address_may_be_invited_into_two_organizations(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->accept($this->invite($alpha, ['email' => 'shared@example.test']), 'shared@example.test');
        $this->accept($this->invite($beta, ['email' => 'shared@example.test']), 'shared@example.test');

        // Two accounts, one address, two organizations — §4's whole reason for
        // the per-organization unique index.
        $this->assertSame(2, User::withoutGlobalScopes()->where('email', 'shared@example.test')->count());
    }

    public function test_accepting_into_an_organization_that_already_has_the_address_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $token = $this->invite($alpha, ['email' => 'twice@example.test']);

        // Somebody creates the account another way while the link is in flight.
        $this->inTenant($alpha['org'], fn () => User::create([
            'organization_id' => $alpha['org']->id,
            'role_id'         => $alpha['admin']->role_id,
            'name'            => 'Already Here',
            'email'           => 'twice@example.test',
            'password'        => 'a-real-password',
        ]));

        $this->acceptWith($token, ['password' => 'a-real-password'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'already_a_member');
    }

    public function test_inviting_an_address_that_is_already_here_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => $alpha['admin']->email,
            'role_id'    => $role->id,
            'scope_type' => 'organization',
            'scope_id'   => $alpha['org']->id,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_second_open_invitation_for_one_address_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $payload = [
            'email'      => 'twice@example.test',
            'role_id'    => $role->id,
            'scope_type' => 'organization',
            'scope_id'   => $alpha['org']->id,
        ];

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', $payload)
            ->assertCreated();

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // ── status, resend, revoke ───────────────────────────────────

    public function test_opening_the_link_marks_it_received(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $token = $this->invite($alpha);

        $this->assertSame('sent', $this->statusOf($alpha));

        $this->lookup($token)->assertOk();

        $this->assertSame('received', $this->statusOf($alpha));
    }

    public function test_an_expired_invitation_reads_as_abandoned_without_a_sweep(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->invite($alpha);

        // Nothing runs. The status is derived from the clock, which is the whole
        // reason there is no scheduled job for this.
        $this->travel(Invitations::LIFETIME_DAYS + 1)->days();

        $this->assertSame('abandoned', $this->statusOf($alpha));

        $this->travelBack();
    }

    /**
     * The two dead ends do not converge, and the order of the match is why.
     *
     * A withdrawn invitation whose week then runs out is still `revoked`: the
     * organization made a decision and the clock did not un-make it. Under the
     * old collapse both said `abandoned`, so this could not have been asked —
     * which is exactly the reporting question the owner named, arriving one week
     * later on every row.
     */
    public function test_a_withdrawn_invitation_stays_revoked_after_its_week_runs_out(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->invite($alpha);

        $id = $this->inTenant($alpha['org'], fn () => Invitation::firstOrFail()->id);

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/hr/invitations/{$id}")->assertOk();

        $this->assertSame('revoked', $this->statusOf($alpha));

        $this->travel(Invitations::LIFETIME_DAYS + 1)->days();

        $this->assertSame('revoked', $this->statusOf($alpha));

        $this->travelBack();
    }

    public function test_resending_mints_a_new_token_and_kills_the_old_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $first = $this->invite($alpha);

        $id = $this->inTenant($alpha['org'], fn () => Invitation::firstOrFail()->id);

        $resent = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/invitations/{$id}/resend")->assertOk();

        $second = $this->tokenFrom($resent->json('data.accept_url'));

        $this->assertNotSame($first, $second);

        // Single use means one live link, not one live link per send.
        $this->postJson('/api/v1/invitations/lookup', ['token' => $first])->assertNotFound();
        $this->postJson('/api/v1/invitations/lookup', ['token' => $second])->assertOk();

        // And the clock restarted, so an invitation that had lapsed is live again.
        $this->assertSame('received', $this->statusOf($alpha));
    }

    public function test_revoking_kills_the_link_and_keeps_the_record(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $token = $this->invite($alpha);

        $id = $this->inTenant($alpha['org'], fn () => Invitation::firstOrFail()->id);

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/hr/invitations/{$id}")
            ->assertOk()
            // Its own word since 2026-08-11, not `abandoned`. The timestamp is
            // carried beside it because the status says what and this says when.
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.revoked_at', fn ($v) => $v !== null);

        $this->lookup($token)->assertNotFound();

        // The row survives — deleting it would erase the fact that anyone was
        // ever invited.
        $this->inTenant($alpha['org'], fn () => $this->assertNotNull(Invitation::find($id)));
    }

    public function test_a_revoked_invitation_cannot_be_resent(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->invite($alpha);

        $id = $this->inTenant($alpha['org'], fn () => Invitation::firstOrFail()->id);

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/hr/invitations/{$id}")->assertOk();

        $this->signedInAs($alpha['admin'])->postJson("/api/v1/hr/invitations/{$id}/resend")
            ->assertStatus(422);
    }

    /**
     * A role somebody is about to be given cannot be deleted out from under them.
     *
     * `invitations.role_id` is ON DELETE SET NULL, so without the guard in
     * RolesController the delete would succeed quietly and the failure would
     * surface a week later, to a stranger, at acceptance.
     */
    public function test_a_role_with_an_outstanding_invitation_cannot_be_deleted(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $this->invite($alpha, ['role_id' => $role->id]);

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        // Withdraw it and the role is deletable again — the block is about the
        // outstanding ask, not about the role having a history.
        $id = $this->inTenant($alpha['org'], fn () => Invitation::firstOrFail()->id);

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/hr/invitations/{$id}")->assertOk();

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/roles/{$role->id}")->assertOk();

        // And the withdrawn invitation is still there, with its role now blank
        // rather than the row gone.
        $this->inTenant($alpha['org'], function () use ($id) {
            $this->assertNotNull(Invitation::find($id));
            $this->assertNull(Invitation::find($id)->role_id);
        });
    }

    // ── the module gate (§4) ─────────────────────────────────────

    public function test_hr_is_gated_by_its_own_permissions(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $reader = $this->userWithRole($alpha, 'Reader', ['hr.view', 'hr.invitations.view']);

        $reader->refresh();

        $this->signedInAs($reader)->getJson('/api/v1/hr/invitations')->assertOk();

        // View is the tab; it is not permission to act. PAST the tab gate and
        // refused by the policy, which is what makes this the assertion that
        // still tells the two layers apart.
        $this->signedInAs($reader)->postJson('/api/v1/hr/invitations', [
            'email'      => 'someone@example.test',
            'role_id'    => $role->id,
            'scope_type' => 'organization',
            'scope_id'   => $alpha['org']->id,
        ])->assertForbidden()->assertJsonMissingPath('code');

        $outsider = $this->userWithRole($alpha, 'Outsider', ['customers.view']);

        // And this one is the TAB GATE answering, not the policy — since
        // per-tab permissions it is in front, and `withImpliedView` means
        // everybody who passes it also satisfies `hr.view`. Named rather than
        // left as a bare 403, which would keep passing if the policy behind it
        // were deleted. See the longer note in HrPeopleTest.
        $this->signedInAs($outsider)->getJson('/api/v1/hr/invitations')
            ->assertForbidden()
            ->assertJsonPath('code', 'permission_denied')
            ->assertJsonPath('message', Refusal::permission('open this tab')->message());
    }

    public function test_an_invitation_belongs_to_its_own_organization_only(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->invite($alpha);

        $id = $this->inTenant($alpha['org'], fn () => Invitation::firstOrFail()->id);

        // Beta's administrator naming Alpha's invitation gets a 404 from the
        // tenant scope, not a 403 — the row does not exist as far as they are
        // concerned, which is the answer that leaks nothing.
        $this->signedInAs($beta['admin'])->deleteJson("/api/v1/hr/invitations/{$id}")
            ->assertNotFound();

        $this->signedInAs($beta['admin'])->getJson('/api/v1/hr/invitations')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ── the triggers (§5.5) ──────────────────────────────────────

    public function test_inviting_and_accepting_raise_their_declared_triggers(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $token = $this->invite($alpha);

        $invited = AutomationEvent::withoutGlobalScopes()->where('event_key', 'user.invited')->first();

        $this->assertNotNull($invited, 'user.invited was declared as raised and was not raised.');
        $this->assertSame('newstarter@example.test', $invited->payload['after']['email']);
        // No subject, and there cannot be one: the person does not exist yet.
        $this->assertNull($invited->subject_type);

        $this->accept($token);

        $activated = AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'user.activated')->first();

        $this->assertNotNull($activated, 'user.activated was declared as raised and was not raised.');
        $this->assertSame(User::class, $activated->subject_type);
    }

    /**
     * The subjectless event has to survive the sweep, not just the emit.
     *
     * `user.invited` is the only event in the system that carries no record, and
     * the engine reads the subject to work out which franchise an event belongs
     * to. An event that threw in `deliver()` would not fail this module — it
     * would jam the outbox for the whole organization, every minute, silently.
     */
    public function test_a_rule_can_be_built_on_being_invited_and_the_sweep_runs_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $automation = Automation::create([
                'organization_id' => $alpha['org']->id,
                'name'            => 'When somebody is invited',
                'trigger_key'     => 'user.invited',
                'is_active'       => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id'       => $alpha['org']->id,
                'automation_id'         => $automation->id,
                'version'               => 1,
                'conditions'            => null,
                'actions'               => [['type' => 'notify', 'message' => 'Somebody was invited']],
                'created_at'            => now(),
            ]);

            $automation->forceFill(['active_version_id' => $version->id])->save();
        });

        $this->invite($alpha);

        $this->inTenant($alpha['org'], function () {
            $event = AutomationEvent::where('event_key', 'user.invited')->firstOrFail();

            app(AutomationEngine::class)->deliver($event);

            $this->assertNotNull($event->fresh()->delivered_at, 'The outbox row was left undelivered.');
            $this->assertSame(AutomationRun::SUCCEEDED, AutomationRun::firstOrFail()->status);
        });
    }

    // ── the email seam ───────────────────────────────────────────

    public function test_the_invitation_email_is_a_real_mailable_and_carries_the_link(): void
    {
        Notification::fake();

        $alpha = $this->provisionTenant('Alpha');
        $this->invite($alpha);

        Notification::assertSentOnDemand(
            InvitationLink::class,
            function (InvitationLink $notification, array $channels, object $notifiable) {
                $mail = $notification->toMail($notifiable);

                // The link is the whole point: an email without it is a
                // notification that somebody was invited, sent to them.
                $this->assertStringContainsString('/invite?token=', $mail->actionUrl);

                return $notifiable->routes['mail'] === 'newstarter@example.test';
            },
        );
    }

    // ── helpers ──────────────────────────────────────────────────

    /** A role in this tenant holding exactly these keys. */
    private function roleIn(array $tenant, string $name, array $keys): Role
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name, $keys) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id, 'name' => $name, 'is_system' => false,
            ]);

            $role->syncPermissionKeys($keys);

            return $role;
        });
    }

    /**
     * The list's tiles count every page, and a tile's filter matches what
     * each row's own status says (HR lists, layout A, Mia 2026-10-02).
     */
    public function test_the_list_counts_and_filters_by_status_as_each_row_reports_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->invite($alpha, ['email' => 'sent@example.test']);
        $this->invite($alpha, ['email' => 'opened@example.test']);
        $this->invite($alpha, ['email' => 'expired@example.test']);
        $this->invite($alpha, ['email' => 'revoked@example.test']);
        $this->accept($this->invite($alpha, ['email' => 'done@example.test']), 'done@example.test');

        $this->inTenant($alpha['org'], function () {
            Invitation::where('email', 'opened@example.test')->firstOrFail()->forceFill(['opened_at' => now()])->save();
            Invitation::where('email', 'expired@example.test')->firstOrFail()->forceFill(['expires_at' => now()->subDay()])->save();
            Invitation::where('email', 'revoked@example.test')->firstOrFail()->forceFill(['revoked_at' => now()])->save();
        });

        $this->signedInAs($alpha['admin']);

        $this->getJson('/api/v1/hr/invitations')->assertOk()->assertJsonPath('meta.tiles', [
            'sent' => 1, 'opened' => 1, 'completed' => 1, 'abandoned' => 1, 'revoked' => 1,
        ]);

        foreach (['sent' => 'sent', 'opened' => 'received', 'completed' => 'completed', 'abandoned' => 'abandoned', 'revoked' => 'revoked'] as $filter => $status) {
            $rows = $this->getJson('/api/v1/hr/invitations?status='.$filter)->assertOk()->json('data');
            $this->assertCount(1, $rows, $filter);
            $this->assertSame($status, $rows[0]['status'], $filter);
        }
    }

    /** Invite as the tenant's administrator; returns the raw token. */
    private function invite(array $tenant, array $overrides = []): string
    {
        return $this->inviteAs($tenant['admin'], $tenant, $overrides);
    }

    private function inviteAs(User $actor, array $tenant, array $overrides = []): string
    {
        $payload = [
            'email'      => 'newstarter@example.test',
            'role_id'    => $overrides['role_id'] ?? $tenant['admin']->role_id,
            'scope_type' => 'franchise',
            'scope_id'   => $tenant['franchise']->id,
            ...$overrides,
        ];

        $response = $this->signedInAs($actor)
            ->postJson('/api/v1/hr/invitations', $payload)
            ->assertCreated();

        return $this->tokenFrom($response->json('data.accept_url'));
    }

    private function accept(string $token, string $expectEmail = 'newstarter@example.test'): void
    {
        $this->signedOut()->postJson('/api/v1/invitations/accept', ['token' => $token] + ['password' => 'a-real-password'])
            ->assertCreated()
            ->assertJsonPath('user.email', $expectEmail);
    }

    /**
     * Drop whoever BuildsTenants signed in.
     *
     * `Sanctum::actingAs` sets the user on the guard for the remainder of the
     * test, so a request to a public endpoint made after one is not actually
     * unauthenticated — which is exactly the wrong thing to be wrong about in a
     * file whose subject is an unauthenticated surface.
     */
    private function signedOut(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function tokenFrom(?string $url): string
    {
        $this->assertIsString($url, 'The invitation response carried no accept_url.');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('token', $query);

        return $query['token'];
    }

    private function statusOf(array $tenant): string
    {
        return $this->inTenant($tenant['org'], fn () => Invitation::firstOrFail()->status());
    }

    /**
     * Accepting, with the token in the BODY.
     *
     * And with the scoped container forgotten first, which is not tidiness.
     * Laravel does NOT reset scoped instances between requests made inside one
     * test, and several of the classes acceptance consults memoise: ScopeReach
     * caches reach per actor, ModuleAvailability caches the platform flags for
     * its lifetime. So a change made between the invite and the accept — which
     * is what every test in this file is about — was invisible to the second
     * request, and a test of the rule passed whether or not the rule still
     * existed. Deleting the reach clause from the controller left the suite
     * green.
     *
     * The classes have changed since — reach is no longer asked at acceptance,
     * and §13 availability is — but the trap has not, so neither has this line.
     *
     * A real request gets a fresh container. This makes the test agree with it.
     */
    private function acceptWith(string $token, array $body = []): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->postJson('/api/v1/invitations/accept', ['token' => $token] + $body);
    }

    private function lookup(string $token): \Illuminate\Testing\TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->postJson('/api/v1/invitations/lookup', ['token' => $token]);
    }

    /**
     * The reach half of the old rule, inverted.
     *
     * It was written because the two shipped tests both moved the inviter's
     * PERMISSIONS, which tripped mayAssignRole and never reached the reach
     * clause beside it — so that clause could be deleted with the suite still
     * green. It narrows the inviter's ASSIGNMENT instead, leaving their
     * permissions untouched, which makes it the one test that exercised reach
     * at acceptance and nothing else.
     *
     * That makes it the right test to keep now that reach is deliberately NOT
     * asked here: it is the only one that would notice the clause coming back.
     */
    public function test_an_invitation_is_honoured_though_the_inviters_reach_has_narrowed(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleIn($alpha, 'Dispatcher', ['customers.view']);

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.invitations.view', 'hr.create', 'customers.view'],
        );

        // Invited at organization level, which the manager can reach today.
        $token = $this->inviteAs($manager, $alpha, [
            'role_id' => $role->id, 'scope_type' => 'organization', 'scope_id' => $alpha['org']->id,
        ]);

        // A week passes. Their permissions are untouched — only where they may
        // act has narrowed, to the one franchise. The place the invitation names
        // is still open, and the organization still stands behind it.
        $this->inTenant($alpha['org'], function () use ($manager, $alpha) {
            $manager->assignments()->delete();
            $manager->assignments()->create([
                'organization_id'      => $alpha['org']->id,
                // The XOR columns are named assigned_*, and Assignment refuses a
                // row that sets none of them — which is what `franchise_id`
                // silently did.
                'assigned_franchise_id' => $alpha['franchise']->id,
            ]);
        });

        $this->acceptWith($token, ['password' => 'a-real-password'])->assertCreated();

        // Organization-wide, exactly as the link said — not trimmed to whatever
        // the inviter can reach today.
        $this->inTenant($alpha['org'], function () use ($alpha) {
            $user = User::where('email', 'newstarter@example.test')->firstOrFail();

            $assignments = Assignment::where('user_id', $user->id)->get();

            $this->assertCount(1, $assignments);
            $this->assertSame(Assignment::SCOPE_ORGANIZATION, $assignments[0]->scopeType());
            $this->assertSame($alpha['org']->id, $assignments[0]->scopeId());
        });
    }

}
