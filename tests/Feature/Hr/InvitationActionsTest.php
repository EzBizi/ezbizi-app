<?php

namespace Tests\Feature\Hr;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Invitation;
use App\Models\Onboarding;
use App\Services\Hr\Invitations;
use App\Support\Hr\CannotActOnInvitation;
use App\Support\Hr\InvitationActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The three acts Automations causes from a rule, called the way a rule calls
 * them: with no actor at all.
 *
 * Every other test of these goes through a controller, which means through a
 * policy, a role check and a reach check — so none of them can tell whether
 * the act works when nobody is performing it. That is the only way a rule ever
 * calls it, and it is the case a rule would discover in production.
 *
 * @see InvitationActions for why the actor rules are NOT here.
 */
class InvitationActionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function actions(): InvitationActions
    {
        return app(InvitationActions::class);
    }

    private function invitation(array $tenant, array $overrides = []): Invitation
    {
        $row = new Invitation;
        $row->forceFill([
            'organization_id' => $tenant['org']->id,
            'email' => 'starter@alpha.test',
            'role_id' => $tenant['admin']->role_id,
            'invited_by_user_id' => $tenant['admin']->id,
            'assigned_franchise_id' => $tenant['franchise']->id,
            'sent_at' => now(),
            'expires_at' => now()->addWeek(),
            'token_hash' => hash('sha256', 'seed-'.uniqid()),
            ...$overrides,
        ]);
        $row->save();

        return $row;
    }

    public function test_a_rule_can_resend_with_no_actor_and_the_old_link_dies(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $invitation = $this->invitation($t);
            $first = $this->actions()->resend($invitation);

            // The returned token opens it, which is what makes the next
            // assertion mean something rather than being true of any string.
            $this->assertNotNull(app(Invitations::class)->open($first));

            $second = $this->actions()->resend($invitation->fresh());

            $this->assertNotSame($first, $second);
            $this->assertNull(
                app(Invitations::class)->open($first),
                'The link from the previous send still works, so reissuing did not kill it.',
            );
        });
    }

    /** Correcting the address is this act, and the wrong address loses its link. */
    public function test_a_resend_can_move_the_address(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $invitation = $this->invitation($t, ['email' => 'bea@exampel.test']);
            $dead = $this->actions()->resend($invitation);

            $this->actions()->resend($invitation->fresh(), 'bea@example.test');

            $this->assertSame('bea@example.test', $invitation->fresh()->email);
            $this->assertNull(app(Invitations::class)->open($dead));
        });
    }

    public function test_resending_an_accepted_invitation_refuses_with_a_readable_sentence(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $invitation = $this->invitation($t, ['accepted_at' => now()]);

            try {
                $this->actions()->resend($invitation);
                $this->fail('An accepted invitation was reissued.');
            } catch (CannotActOnInvitation $e) {
                // The sentence, not the class: Automations renders this verbatim
                // into a run log, so it has to read as an explanation rather
                // than as an exception name.
                $this->assertStringContainsString('already been accepted', $e->getMessage());
                $this->assertSame(422, $e->status());
                $this->assertNull($e->field);
            }
        });
    }

    /**
     * Withdrawing twice is not an error, and that is for the rule's sake.
     *
     * "Withdraw anything unaccepted after thirty days" runs every day. If the
     * second run refused, the run log would fill with failures describing a
     * success, and a real failure would be invisible among them.
     */
    public function test_revoking_is_idempotent(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $invitation = $this->invitation($t);

            $this->actions()->revoke($invitation);
            $first = $invitation->fresh()->revoked_at;

            $this->assertNotNull($first);

            $this->actions()->revoke($invitation->fresh());

            $this->assertEquals(
                $first,
                $invitation->fresh()->revoked_at,
                'The second withdrawal moved the timestamp, so it was not a no-op.',
            );
        });
    }

    public function test_revoking_an_accepted_invitation_refuses(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $invitation = $this->invitation($t, ['accepted_at' => now()]);

            $this->expectException(CannotActOnInvitation::class);
            $this->actions()->revoke($invitation);
        });
    }

    /**
     * A rule-started onboarding records no starter, and that is the honest row.
     *
     * `started_by_user_id` is nullable for this case. Borrowing whoever
     * happened to trigger the rule would attribute an act to somebody who did
     * not perform it, which is worse than recording that it was automatic.
     */
    public function test_a_rule_starts_an_onboarding_with_no_starter(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->artisan('hr:publish-onboarding-catalog', ['organization' => 'Alpha'])
            ->assertSuccessful();

        $this->inTenant($t['org'], function () use ($t) {
            $contract = Contract::query()->firstOrFail();
            $invitation = $this->invitation($t);

            $onboarding = $this->actions()->startOnboarding($invitation, $contract);

            $this->assertSame(Onboarding::IN_PROGRESS, $onboarding->status);
            $this->assertNull(
                $onboarding->started_by_user_id,
                'A rule has no actor, so nobody may be recorded as having started it.',
            );
            $this->assertSame($invitation->getKey(), $onboarding->invitation_id);
        });
    }

    /**
     * The picker offers exactly what the act accepts, and nothing else.
     *
     * Automations serves this list to a rule author. If it could offer a
     * template `startOnboarding` refuses, the author would be told days later,
     * in a run log, that the choice the builder gave them was never valid —
     * so both ask one predicate and this proves they agree on a real case.
     */
    public function test_the_contract_picker_omits_what_starting_would_refuse(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->artisan('hr:publish-onboarding-catalog', ['organization' => 'Alpha'])
            ->assertSuccessful();

        $this->inTenant($t['org'], function () use ($t) {
            // A template nobody could ever sign: published, and with no
            // signature field in it.
            $unsignable = Contract::create([
                'organization_id' => $t['org']->id,
                'name' => 'Unsignable',
                'created_by' => $t['admin']->id,
            ]);
            $version = ContractVersion::create([
                'organization_id' => $t['org']->id,
                'contract_id' => $unsignable->id,
                'version' => 1,
                'body' => '',
                'created_by' => $t['admin']->id,
                'fields' => [[
                    'key' => 'note', 'label' => 'Note', 'type' => 'text',
                    'options' => [], 'filled_by' => 'either', 'required' => false,
                    'sensitive' => false, 'retired' => false,
                ]],
            ]);
            $unsignable->forceFill(['current_version_id' => $version->id])->save();

            // And one that has never been published at all.
            Contract::create([
                'organization_id' => $t['org']->id,
                'name' => 'Never published',
                'created_by' => $t['admin']->id,
            ]);

            $labels = array_column($this->actions()->offerableContracts(), 'label');

            $this->assertNotContains('Unsignable', $labels);
            $this->assertNotContains('Never published', $labels);

            // The control: a real one IS offered, or the two assertions above
            // would pass against a picker that returns nothing at all.
            $this->assertNotEmpty($labels);

            // And the omissions are not an accident of the list being short —
            // starting against the unsignable one genuinely refuses.
            $this->expectException(CannotActOnInvitation::class);
            $this->actions()->startOnboarding($this->invitation($t), $unsignable);
        });
    }

    /** And the same invitation cannot carry two. */
    public function test_a_second_onboarding_on_one_invitation_refuses(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->artisan('hr:publish-onboarding-catalog', ['organization' => 'Alpha'])
            ->assertSuccessful();

        $this->inTenant($t['org'], function () use ($t) {
            $contract = Contract::query()->firstOrFail();
            $invitation = $this->invitation($t);

            $this->actions()->startOnboarding($invitation, $contract);

            try {
                $this->actions()->startOnboarding($invitation->fresh(), $contract);
                $this->fail('One invitation carried two onboardings.');
            } catch (CannotActOnInvitation $e) {
                $this->assertStringContainsString('already has an onboarding', $e->getMessage());
            }
        });
    }
}
