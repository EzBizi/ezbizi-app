<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Role;
use App\Models\SendingIdentity;
use App\Models\User;
use App\Support\Mail\MailTransport;
use App\Support\Mail\SendingState;
use App\Support\Privacy\SensitiveValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The sending-address endpoints (§6, §4).
 *
 * ── The four that are not about happy paths ──────────────────────
 *
 * test_a_franchise_user_cannot_change_the_companys_address, because that is the
 * owner's rule — "a franchise user should not be able to modify parent
 * preferences" — on a brand new write surface that could have missed it. It
 * comes with a control that the SAME user CAN set their own franchise's, so the
 * refusal is demonstrably about reach and not about the permission.
 *
 * test_the_api_cannot_declare_an_address_verified and its second-write
 * counterpart, because an identity that can be marked verified over HTTP is the
 * whole reason this is a table and not a settings-registry entry. The second of
 * the two exists because a mutation run proved the first was not enough.
 *
 * test_a_transport_credential_never_comes_back, because §9's rule is that the
 * only way a sensitive value leaves this application in the clear is through the
 * call that records that it did, and an endpoint serialising a model is the
 * classic way round it.
 *
 * ── And one that is about neither ────────────────────────────────
 *
 * test_the_response_carries_exactly_what_the_panel_reads. Two agents built the
 * two halves of this feature at the same time, and the client's own test file
 * pins its side of the contract with a fixture. This pins the other side
 * against the same key names, so a rename here fails here rather than in a
 * blank card nobody notices.
 */
class SendingIdentitiesApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/mail/sending-identity';

    private const CHECK = '/api/v1/mail/sending-identity/check';

    private function body(string $address = 'info@a-1chimney.com', string $name = 'A-1 Chimney'): array
    {
        return ['from_address' => $address, 'from_name' => $name];
    }

    private function at(string $type, string $id): string
    {
        return self::ENDPOINT."?scope_type={$type}&scope_id={$id}";
    }

    /** The check takes its scope in the body — ApiClient puts no query on POST. */
    private function checkFor(string $type, string $id): array
    {
        return ['scope_type' => $type, 'scope_id' => $id];
    }

    // ── the contract the client reads ────────────────────────────

    public function test_the_response_carries_exactly_what_the_panel_reads(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $data = $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk()
            ->json('data');

        // Every key client/lib/widgets/sending_identity_panel.dart reads off the
        // object, and nothing it does not. A key it wants and does not get is a
        // blank line on a panel; a key here that it never reads is dead weight
        // somebody will later assume is load bearing.
        $this->assertSame([
            'from_address', 'from_name', 'reply_to', 'source', 'verification',
            'transport', 'not_yet_in_effect', 'automation',
            'may_edit', 'may_edit_reason',
        ], array_keys($data));

        $this->assertSame(
            ['state', 'label', 'detail', 'next_step', 'records'],
            array_keys($data['verification']),
        );

        // No `found` on a record. The panel prints "Found"/"Not there yet" only
        // when the server says so, and this server cannot: a DnsVerifier
        // returns one verdict for the domain, not one per record, and none is
        // connected anyway. Sending `false` would accuse a tenant of a missing
        // record that nothing had looked for.
        $this->assertNotEmpty($data['verification']['records']);
        $this->assertSame(
            ['type', 'name', 'value', 'purpose'],
            array_keys($data['verification']['records'][0]),
        );
    }

    /**
     * Every feature wires into automations, including by saying it does not.
     *
     * The note is not hardcoded as permanent: `automation.emits` is probed
     * against the module registry, so the day somebody declares the triggers
     * this starts listing them and the apology stops. Asserting on the derived
     * shape rather than on the sentence is what lets that happen quietly.
     */
    public function test_the_automation_surface_is_declared_even_though_it_is_empty(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $automation = $this->signedInAs($t['admin'])
            ->getJson($this->at('company', $t['company']->id))
            ->assertOk()
            ->json('data.automation');

        $this->assertSame([], $automation['emits']);
        $this->assertNotNull($automation['note'], 'A module that emits nothing must say why.');
        $this->assertStringContainsString('verification', $automation['note']);
    }

    public function test_the_screen_is_told_that_nothing_sends_from_this_address_yet(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $sentence = $this->signedInAs($t['admin'])
            ->getJson($this->at('company', $t['company']->id))
            ->assertOk()
            ->json('data.not_yet_in_effect');

        // A control that saves, audits, inherits and changes nothing is worse
        // than a missing one, because the screen presents it as working. The
        // sentence has to be careful about WHICH mail, though — password resets
        // and invitations do send, from EzBizi, and always will.
        $this->assertNotNull($sentence);
        $this->assertStringContainsString('Newsletter', $sentence);
        $this->assertStringContainsString('password resets', $sentence);
    }

    // ── reading ──────────────────────────────────────────────────

    public function test_a_scope_with_nothing_set_reports_the_platform_address(): void
    {
        config(['mail.from.address' => 'noreply@ezbizi.test', 'mail.from.name' => 'EzBizi']);

        $t = $this->provisionTenant('A1-Group');

        $response = $this->signedInAs($t['admin'])
            ->getJson($this->at('franchise', $t['franchise']->id))
            ->assertOk();

        $response->assertJsonPath('data.from_address', 'noreply@ezbizi.test');

        // Null source and null verification are two different facts the panel
        // draws differently: nothing is set anywhere in this business, and
        // there is nothing for a tenant to prove about EzBizi's own domain.
        $this->assertNull($response->json('data.source'));
        $this->assertNull($response->json('data.verification'));
    }

    public function test_an_inherited_address_says_where_it_came_from(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk();

        $response = $this->getJson($this->at('franchise', $t['franchise']->id))->assertOk();

        $response->assertJsonPath('data.from_address', 'info@a-1chimney.com');
        $response->assertJsonPath('data.source.type', 'company');
        $response->assertJsonPath('data.source.name', $t['company']->name);
        $response->assertJsonPath('data.source.inherited', true);
    }

    /**
     * A franchise showing its company's address gets the state and not the records.
     *
     * The records belong to A-1 Chimney and are not Connecticut's to publish or
     * to check — and the panel keys its "Check now" button off a non-empty
     * list, so an empty one is also what stops that button appearing where the
     * check would 404.
     */
    public function test_an_inherited_address_carries_its_state_but_not_its_records(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk();

        $company = $this->getJson($this->at('company', $t['company']->id))->assertOk();
        $branch  = $this->getJson($this->at('franchise', $t['franchise']->id))->assertOk();

        // CONTROL: the company DOES have records, so "the franchise has none"
        // is not passing because nothing generates any.
        $this->assertNotEmpty($company->json('data.verification.records'));
        $this->assertSame([], $branch->json('data.verification.records'));

        // Same state though — it is what decides whether their mail goes out.
        $this->assertSame(
            $company->json('data.verification.state'),
            $branch->json('data.verification.state'),
        );

        // And the next step names whose address it is, rather than telling them
        // to publish records they cannot publish.
        $this->assertStringContainsString(
            $t['company']->name,
            $branch->json('data.verification.next_step'),
        );
    }

    // ── writing, and the reach rule ──────────────────────────────

    public function test_setting_an_address_at_a_franchise_leaves_the_company_alone(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk();

        $this->patchJson(
            $this->at('franchise', $t['franchise']->id),
            $this->body('info@ct.a-1chimney.com', 'A-1 Chimney Connecticut')
        )->assertOk()->assertJsonPath('data.from_address', 'info@ct.a-1chimney.com');

        $this->getJson($this->at('company', $t['company']->id))
            ->assertOk()
            ->assertJsonPath('data.from_address', 'info@a-1chimney.com');
    }

    /**
     * The owner's rule, on a brand new write surface.
     *
     * The user holds `organizations.edit` — the exact permission the endpoint
     * asks for — and is assigned to one franchise. Authority runs downwards
     * only (§4), so the company above them is not theirs to change.
     */
    public function test_a_franchise_user_cannot_change_the_companys_address(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $manager = $this->userWithRole(
            $t, 'Branch Manager', ['organizations.edit'], onlyAt: $t['franchise']
        );

        $message = $this->signedInAs($manager)
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertForbidden()
            ->json('message');

        // R8: a sentence with a remedy in it, and no permission key or class
        // name anywhere.
        //
        // A franchise-only assignment reaches no company at all, so the honest
        // remedy is an assignment rather than "ask somebody at A-1 Chimney" —
        // that sentence belongs to somebody assigned to a DIFFERENT company,
        // and the test below covers them.
        $this->assertStringContainsString('permission to change the sending address', $message);
        $this->assertStringContainsString('assign', $message);
        $this->assertStringNotContainsString('organizations.edit', $message);
        $this->assertStringNotContainsString('\\', $message);

        // Nothing was written on the way to being refused.
        $this->assertSame(0, SendingIdentity::withoutGlobalScopes()->count());

        /*
         * And it refuses in exactly the words the rest of the settings screen
         * uses, for the same act, by the same person, at the same scope.
         *
         * Two screens side by side with two accounts of one rule is how
         * somebody concludes that one of them is broken. Both sentences come
         * out of Refusal, and this is what keeps them there: a copy of §4
         * written into this controller would drift, and drift silently.
         */
        $settingsRefusal = $this->patchJson(
            "/api/v1/settings/money.tax_rate?scope_type=company&scope_id={$t['company']->id}",
            ['value' => 1.0],
        )->assertForbidden()->json('message');

        // The two differ only in the act they name, which is the part that is
        // supposed to differ. Everything after it is Refusal's, word for word.
        $this->assertSame(
            substr($settingsRefusal, strpos($settingsRefusal, ', but you are not assigned')),
            substr($message, strpos($message, ', but you are not assigned')),
        );
    }

    /**
     * The other reach failure, and the one that names the place.
     *
     * Somebody assigned AT a company does reach a company, just not this one,
     * and Refusal picks a different sentence for them. It has to: telling them
     * they are "not assigned to any company" would be false, and would send
     * them to an administrator for something they already have.
     */
    public function test_a_company_user_cannot_change_a_sibling_companys_address(): void
    {
        $t = $this->provisionTenant('A1-Group');

        [$everdoors, $manager] = $this->inTenant($t['org'], function () use ($t) {
            $everdoors = Company::create([
                'organization_id' => $t['org']->id,
                'name'            => 'Everdoors',
            ]);

            $role = Role::create([
                'organization_id' => $t['org']->id,
                'name'            => 'Everdoors Manager',
                'is_system'       => false,
            ]);

            $role->syncPermissionKeys(['organizations.edit']);

            $manager = User::create([
                'organization_id' => $t['org']->id,
                'role_id'         => $role->id,
                'name'            => 'Everdoors Manager',
                'email'           => 'everdoors@a1group.test',
                'password'        => 'a-real-password',
                'is_active'       => true,
            ]);

            Assignment::create([
                'organization_id'     => $t['org']->id,
                'user_id'             => $manager->id,
                'assigned_company_id' => $everdoors->id,
            ]);

            return [$everdoors, $manager];
        });

        // Their own: allowed. The control that makes the refusal below mean
        // something about reach rather than about the permission.
        $this->signedInAs($manager)
            ->patchJson($this->at('company', $everdoors->id), $this->body('info@everdoors.com', 'Everdoors'))
            ->assertOk();

        $message = $this->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString($t['company']->name, $message);
        $this->assertStringContainsString('outside where you are assigned', $message);
    }

    /** The control for the franchise test above. */
    public function test_the_same_franchise_user_may_set_their_own(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $manager = $this->userWithRole(
            $t, 'Branch Manager', ['organizations.edit'], onlyAt: $t['franchise']
        );

        $this->signedInAs($manager)
            ->patchJson($this->at('franchise', $t['franchise']->id), $this->body('info@ct.a-1chimney.com', 'CT'))
            ->assertOk()
            ->assertJsonPath('data.from_address', 'info@ct.a-1chimney.com')
            ->assertJsonPath('data.source.inherited', false);
    }

    public function test_a_franchise_user_cannot_change_the_organizations_address(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $manager = $this->userWithRole(
            $t, 'Branch Manager', ['organizations.edit'], onlyAt: $t['franchise']
        );

        // Refused by POSITION rather than by place: there is no other
        // organization they could have done it on instead.
        $message = $this->signedInAs($manager)
            ->patchJson($this->at('organization', $t['org']->id), $this->body())
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('whole organization', $message);
    }

    public function test_somebody_without_the_permission_is_refused_in_a_sentence(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $viewer = $this->userWithRole($t, 'Viewer', ['organizations.view']);

        $message = $this->signedInAs($viewer)
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('role', $message);
        $this->assertStringNotContainsString('organizations.edit', $message);
    }

    public function test_the_read_says_why_a_control_would_be_dead(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $viewer = $this->userWithRole($t, 'Viewer', ['organizations.view']);

        $response = $this->signedInAs($viewer)
            ->getJson($this->at('company', $t['company']->id))
            ->assertOk();

        // The disabled control and the 403 behind it are the same sentence,
        // computed once — the settings screen learned this the hard way, with a
        // switch that animated and then came back 403.
        $this->assertFalse($response->json('data.may_edit'));
        $this->assertNotNull($response->json('data.may_edit_reason'));
    }

    // ── the state is not the caller's to set ─────────────────────

    public function test_the_api_cannot_declare_an_address_verified(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])->patchJson($this->at('company', $t['company']->id), [
            ...$this->body(),

            // Everything a hopeful caller might try. None of it is validated
            // in, none of it is fillable, and update() puts the state columns
            // back as it found them regardless.
            'state'              => SendingState::Verified->value,
            'verified_at'        => now()->toIso8601String(),
            'state_reason'       => 'Trust me.',
            'verification_token' => 'chosen-by-the-caller',
        ])->assertOk()->assertJsonPath('data.verification.state', SendingState::Unverified->value);

        $stored = SendingIdentity::withoutGlobalScopes()->first();

        $this->assertNotNull($stored, 'Nothing was stored, so the assertions below prove nothing.');
        $this->assertSame(SendingState::Unverified, $stored->sendingState());
        $this->assertNull($stored->verified_at);
        $this->assertNotSame('chosen-by-the-caller', $stored->verification_token);
        $this->assertNotSame('Trust me.', $stored->state_reason);
    }

    /**
     * The same thing on the SECOND write, which is a different code path.
     *
     * Found by mutation rather than by reading. Deleting BOTH of the guards
     * that stop a request reaching the state columns — the validated allowlist
     * and `$fillable` — left the whole suite green, because a create is settled
     * again by DomainVerification::prepare a few lines later and the test above
     * only ever created. An UPDATE is not: reconsider() deliberately leaves a
     * still-valid proof alone when nothing relevant changed, so this is exactly
     * where a widened allowlist would have got through with nothing saying so.
     */
    public function test_a_second_write_cannot_declare_an_address_verified_either(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk();

        // Same address, same transport — so nothing about the domain changed
        // and the state machine has no reason to touch the row at all.
        $this->patchJson($this->at('company', $t['company']->id), [
            ...$this->body(),
            'state'              => SendingState::Verified->value,
            'verified_at'        => now()->toIso8601String(),
            'state_reason'       => 'Trust me.',
            'verification_token' => 'chosen-by-the-caller',
        ])->assertOk()->assertJsonPath('data.verification.state', SendingState::Unverified->value);

        $stored = SendingIdentity::withoutGlobalScopes()->first();

        $this->assertNotNull($stored, 'Nothing was stored, so the assertions below prove nothing.');
        $this->assertSame(SendingState::Unverified, $stored->sendingState());
        $this->assertNull($stored->verified_at);
        $this->assertNotSame('chosen-by-the-caller', $stored->verification_token);
        $this->assertNotSame('Trust me.', $stored->state_reason);

        // CONTROL: the write really did happen, so none of the above is passing
        // because the request was rejected on the way in.
        $this->assertSame('info@a-1chimney.com', $stored->from_address);
    }

    public function test_checking_records_what_came_back_and_does_not_verify(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk();

        $response = $this->postJson(self::CHECK, $this->checkFor('company', $t['company']->id))
            ->assertOk();

        // This installation has no DNS provider, so the honest answer is "we
        // could not look" — waiting, not verified, and not the tenant's fault.
        $response->assertJsonPath('data.verification.state', SendingState::Pending->value);

        $this->assertStringContainsString(
            'no domain-verification service',
            strtolower($response->json('data.verification.detail')),
        );

        // And the sentence beside it says what to do (R8).
        $this->assertStringContainsString(
            'info@a-1chimney.com',
            $response->json('data.verification.next_step'),
        );

        $stored = SendingIdentity::withoutGlobalScopes()->first();
        $this->assertNotNull($stored->last_checked_at, 'The attempt was not recorded.');
        $this->assertNull($stored->verified_at);
    }

    public function test_checking_a_level_that_holds_no_address_says_so(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->postJson(self::CHECK, $this->checkFor('franchise', $t['franchise']->id))
            ->assertNotFound()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'nothing to check'));
    }

    // ── §9: the credential never leaves ──────────────────────────

    public function test_a_transport_credential_never_comes_back(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $secret = 'smtp-password-never-echo-this';

        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk();

        // Written the only way it can be — no route sets it. This is the state
        // a bring-your-own-SMTP transport would leave rows in.
        $this->inTenant($t['org'], function () use ($secret) {
            $stored = SendingIdentity::first();
            $stored->forceFill(['credentials' => $secret])->save();

            // CONTROL: it really is stored, so "the response does not contain
            // it" is not passing because there was nothing to leak.
            $this->assertSame($secret, $stored->fresh()->credentials);
        });

        $this->getJson($this->at('company', $t['company']->id))
            ->assertOk()
            ->assertDontSee($secret);

        $this->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk()
            ->assertDontSee($secret);

        $this->postJson(self::CHECK, $this->checkFor('company', $t['company']->id))
            ->assertOk()
            ->assertDontSee($secret);
    }

    public function test_the_credential_is_encrypted_at_rest_and_masked_on_serialisation(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $secret = 'smtp-password-never-echo-this';

        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk();

        $this->inTenant($t['org'], function () use ($secret) {
            $stored = SendingIdentity::first();
            $stored->forceFill(['credentials' => $secret])->save();

            // Unreadable in a database dump.
            $raw = \Illuminate\Support\Facades\DB::table('sending_identities')
                ->where('id', $stored->getKey())->value('credentials');

            $this->assertNotSame($secret, $raw);
            $this->assertStringNotContainsString($secret, (string) $raw);

            // And masked through every ordinary serialisation path, which is
            // the half that a per-endpoint fix would have missed.
            $this->assertSame(SensitiveValues::MASK, $stored->fresh()->toArray()['credentials']);
        });
    }

    // ── clearing, and the tenant boundary ────────────────────────

    public function test_clearing_an_address_restores_inheritance(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk();

        $this->patchJson($this->at('franchise', $t['franchise']->id), $this->body('info@ct.a-1chimney.com', 'CT'))
            ->assertOk();

        $this->deleteJson($this->at('franchise', $t['franchise']->id))
            ->assertOk()
            // Back to inheriting, rather than freezing today's answer as a choice.
            ->assertJsonPath('data.from_address', 'info@a-1chimney.com')
            ->assertJsonPath('data.source.type', 'company')
            ->assertJsonPath('data.source.inherited', true);
    }

    public function test_another_organizations_scope_is_simply_not_found(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->signedInAs($alpha['admin'])
            ->patchJson($this->at('company', $beta['company']->id), $this->body())
            ->assertNotFound();

        $this->assertSame(0, SendingIdentity::withoutGlobalScopes()->count());
    }

    public function test_an_address_that_is_not_an_address_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), [
                'from_address' => 'not-an-address',
                'from_name'    => 'A-1 Chimney',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('from_address');
    }

    public function test_the_platforms_own_transport_is_not_a_tenant_choice(): void
    {
        $t = $this->provisionTenant('A1-Group');

        // Picking it would mean sending as EzBizi from a tenant's screen, which
        // is what the cascade already does when nothing is set — as a decision
        // somebody can see, rather than a stored value with no visible meaning.
        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), [
                ...$this->body(),
                'transport' => MailTransport::Platform->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('transport');
    }

    public function test_a_body_that_omits_the_transport_takes_the_only_one_on_offer(): void
    {
        $t = $this->provisionTenant('A1-Group');

        // The client sends an address and a name and nothing else, because
        // there is one transport and a picker of one is a question with no
        // second answer.
        $this->signedInAs($t['admin'])
            ->patchJson($this->at('company', $t['company']->id), $this->body())
            ->assertOk()
            ->assertJsonPath('data.transport.value', MailTransport::VerifiedDomain->value);

        $this->assertSame(
            MailTransport::VerifiedDomain->value,
            SendingIdentity::withoutGlobalScopes()->first()->transport,
        );
    }
}
