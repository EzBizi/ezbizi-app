<?php

namespace Tests\Feature\Api;

use App\Models\PlatformStaff;
use App\Services\Staff\StaffAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Managing staff accounts (plan §4.2).
 *
 * The properties worth defending here: only a Lead Developer can mint an account
 * that reaches every customer; nobody can lock the platform out of its own
 * control plane; and rotating your authenticator cannot strand you.
 */
class StaffAccountsApiTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(Google2FA::class);
    }

    /** @return array{0: PlatformStaff, 1: string} */
    private function staff(string $tier = PlatformStaff::TIER_LEAD, string $email = 'lead@ezbizi.test'): array
    {
        $staff = PlatformStaff::create([
            'name'      => 'Staffer',
            'email'     => $email,
            'password'  => 'a-sufficiently-long-password',
            'tier'      => $tier,
            'is_active' => true,
        ]);

        $secret = $this->totp->generateSecretKey(32);
        $staff->totp_secret = $secret;
        $staff->totp_confirmed_at = now();
        $staff->save();

        return [$staff, $secret];
    }

    private function tokenFor(PlatformStaff $staff, string $secret): string
    {
        return $this->postJson('/api/v1/staff/auth/login', [
            'email'    => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code'     => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');
    }

    /**
     * A code from the NEXT time-step.
     *
     * Each code is single-use, so a test that authenticates twice needs a second
     * one. Google2FA reads the real clock and Carbon's time travel cannot move
     * it, so clearing the recorded step is how a test says "thirty seconds
     * passed". The replay rule itself is tested directly, in
     * test_a_totp_code_cannot_be_used_twice.
     */
    private function nextCode(PlatformStaff $staff, string $secret): string
    {
        $staff->forceFill(['last_totp_timestamp' => null])->save();

        return $this->totp->getCurrentOtp($secret);
    }

    /** Password plus a fresh code — what /staff/me/totp now demands. */
    private function reauth(PlatformStaff $staff, string $secret): array
    {
        return [
            'password' => 'a-sufficiently-long-password',
            'code'     => $this->nextCode($staff, $secret),
        ];
    }

    // ── who may manage staff ─────────────────────────────────────

    public function test_a_developer_cannot_create_staff(): void
    {
        [$dev, $secret] = $this->staff(PlatformStaff::TIER_DEV, 'dev@ezbizi.test');

        // A Developer who could mint another Developer could mint one for
        // anybody, and the tier ceiling would mean nothing.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($dev, $secret))
            ->postJson('/api/v1/staff/accounts', [
                'name' => 'Sneaky', 'email' => 'sneaky@ezbizi.test',
                'password' => 'a-sufficiently-long-password', 'tier' => 'developer',
            ])->assertForbidden();
    }

    public function test_a_developer_cannot_list_staff(): void
    {
        [$dev, $secret] = $this->staff(PlatformStaff::TIER_DEV, 'dev@ezbizi.test');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($dev, $secret))
            ->getJson('/api/v1/staff/accounts')->assertForbidden();
    }

    // ── creating ─────────────────────────────────────────────────

    public function test_a_new_account_arrives_with_a_pending_secret_and_cannot_sign_in(): void
    {
        [$lead, $secret] = $this->staff();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $secret))
            ->postJson('/api/v1/staff/accounts', [
                'name' => 'New Dev', 'email' => 'new@ezbizi.test',
                'password' => 'a-sufficiently-long-password', 'tier' => 'developer',
            ])->assertCreated()
            ->assertJsonPath('data.totp_confirmed', false)
            ->assertJsonPath('data.totp_pending', true);

        $this->assertStringStartsWith('otpauth://totp/', $response->json('enrolment.uri'));

        // Inert until the QR is proven — the account exists but is not a way in.
        $this->postJson('/api/v1/staff/auth/login', [
            'email'    => 'new@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'code'     => $this->totp->getCurrentOtp($response->json('enrolment.secret')),
        ])->assertForbidden()->assertJsonPath('code', 'totp_enrolment_required');
    }

    public function test_confirming_the_new_account_makes_it_usable_and_yields_recovery_codes(): void
    {
        [$lead, $secret] = $this->staff();
        $auth = 'Bearer '.$this->tokenFor($lead, $secret);

        $created = $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/accounts', [
                'name' => 'New Dev', 'email' => 'new@ezbizi.test',
                'password' => 'a-sufficiently-long-password', 'tier' => 'developer',
            ])->assertCreated();

        $newSecret = $created->json('enrolment.secret');
        $newId     = $created->json('data.id');

        $confirm = $this->withHeader('Authorization', $auth)
            ->postJson("/api/v1/staff/accounts/{$newId}/totp/confirm", [
                'code' => $this->totp->getCurrentOtp($newSecret),
            ])->assertOk()->assertJsonPath('data.totp_confirmed', true);

        $this->assertCount(8, $confirm->json('recovery_codes'));

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'new@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'code' => $this->totp->getCurrentOtp($newSecret),
        ])->assertOk();
    }

    public function test_a_short_password_is_refused(): void
    {
        [$lead, $secret] = $this->staff();

        // 16, not the tenant default of 12 — this account reaches every customer.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $secret))
            ->postJson('/api/v1/staff/accounts', [
                'name' => 'New Dev', 'email' => 'new@ezbizi.test',
                'password' => 'short-one', 'tier' => 'developer',
            ])->assertStatus(422);
    }

    // ── not locking yourself out ─────────────────────────────────

    public function test_you_cannot_deactivate_yourself(): void
    {
        [$lead, $secret] = $this->staff();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $secret))
            ->patchJson("/api/v1/staff/accounts/{$lead->id}", ['is_active' => false])
            ->assertStatus(422);
    }

    public function test_the_last_lead_developer_cannot_be_demoted(): void
    {
        [$lead, $secret] = $this->staff();
        $this->staff(PlatformStaff::TIER_DEV, 'dev@ezbizi.test');

        // Demoting the only lead leaves nobody able to manage staff — recoverable
        // only from a shell on the box.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $secret))
            ->patchJson("/api/v1/staff/accounts/{$lead->id}", ['tier' => 'developer'])
            ->assertStatus(422);
    }

    public function test_deactivating_someone_revokes_their_sessions(): void
    {
        [$lead, $leadSecret] = $this->staff();
        [$dev, $devSecret] = $this->staff(PlatformStaff::TIER_DEV, 'dev@ezbizi.test');

        $devToken = $this->tokenFor($dev, $devSecret);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $leadSecret))
            ->patchJson("/api/v1/staff/accounts/{$dev->id}", ['is_active' => false])
            ->assertOk();

        // "Disabled" has to describe the access, not a flag.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$devToken}")
            ->getJson('/api/v1/staff/me')->assertUnauthorized();
    }

    // ── moving to a new phone ────────────────────────────────────

    public function test_rotating_your_own_authenticator_keeps_the_old_one_working_until_confirmed(): void
    {
        [$staff, $secret] = $this->staff();
        $auth = 'Bearer '.$this->tokenFor($staff, $secret);

        $rotation = $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/me/totp', $this->reauth($staff, $secret))->assertOk();

        $newSecret = $rotation->json('enrolment.secret');
        $this->assertNotSame($secret, $newSecret);

        // The whole point: a mis-scan must not lock you out of your own account.
        $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'a-sufficiently-long-password',
            'code' => $this->nextCode($staff, $secret),
        ])->assertOk();

        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/me/totp/confirm', [
                'code' => $this->nextCode($staff, $newSecret),
            ])->assertOk();

        // Once confirmed, the old phone stops working.
        $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'a-sufficiently-long-password',
            'code' => $this->nextCode($staff, $secret),
        ])->assertUnauthorized();
    }

    public function test_rotating_your_own_authenticator_needs_both_factors_again(): void
    {
        [$staff, $secret] = $this->staff();
        $auth = 'Bearer '.$this->tokenFor($staff, $secret);

        // A stolen token lives twelve hours. Without this it could bind a phone
        // the thief owns and turn an expiring session into a standing credential.
        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/me/totp', [
                'password' => 'a-sufficiently-long-password',
                'code'     => '000000',
            ])->assertStatus(422);

        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/me/totp', [
                'password' => 'the-wrong-password-entirely',
                'code'     => $this->nextCode($staff, $secret),
            ])->assertStatus(422);

        $this->assertFalse($staff->fresh()->hasPendingTotp());
    }

    public function test_you_cannot_re_enrol_yourself_through_the_lost_phone_path(): void
    {
        [$staff, $secret] = $this->staff();

        // That endpoint kills the live secret AND every token in one request.
        // On yourself it strands the account: no session left to confirm the
        // replacement, and no confirmed factor left to sign in with.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staff, $secret))
            ->postJson("/api/v1/staff/accounts/{$staff->id}/totp")
            ->assertStatus(422);

        $this->assertTrue($staff->fresh()->hasTotp());
    }

    public function test_a_lead_can_still_re_enrol_another_lead(): void
    {
        [$lead, $leadSecret] = $this->staff();
        [$other, ] = $this->staff(PlatformStaff::TIER_LEAD, 'other@ezbizi.test');

        // The self-refusal must not become a blanket one — a colleague losing
        // their phone is exactly what this endpoint is for.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $leadSecret))
            ->postJson("/api/v1/staff/accounts/{$other->id}/totp")
            ->assertOk()
            ->assertJsonPath('data.totp_confirmed', false);
    }

    public function test_a_totp_code_cannot_be_used_twice(): void
    {
        [$staff, $secret] = $this->staff();

        $code = $this->totp->getCurrentOtp($secret);

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'a-sufficiently-long-password', 'code' => $code,
        ])->assertOk();

        // Six digits overheard during an enrolment hand-off, or lifted from a
        // screenshot, would otherwise stay good for the rest of their window.
        $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'a-sufficiently-long-password', 'code' => $code,
        ])->assertUnauthorized();
    }

    public function test_disabling_with_a_falsy_value_still_revokes_tokens(): void
    {
        [$lead, $leadSecret] = $this->staff();
        [$dev, $devSecret] = $this->staff(PlatformStaff::TIER_DEV, 'dev@ezbizi.test');

        $devToken = $this->tokenFor($dev, $devSecret);

        // Laravel's `boolean` rule accepts 0 and "0" as well as false. A strict
        // comparison further down would disable the account while leaving live
        // tokens to spring back the moment it is re-enabled.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $leadSecret))
            ->patchJson("/api/v1/staff/accounts/{$dev->id}", ['is_active' => 0])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $dev->id,
        ]);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$devToken}")
            ->getJson('/api/v1/staff/me')->assertUnauthorized();
    }

    public function test_a_lost_phone_reset_kills_the_old_secret_immediately(): void
    {
        [$lead, $leadSecret] = $this->staff();
        [$dev, $devSecret] = $this->staff(PlatformStaff::TIER_DEV, 'dev@ezbizi.test');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $leadSecret))
            ->postJson("/api/v1/staff/accounts/{$dev->id}/totp")
            ->assertOk()
            ->assertJsonPath('data.totp_confirmed', false);

        // Presumed compromised, so it dies now rather than when convenient.
        $this->postJson('/api/v1/staff/auth/login', [
            'email' => $dev->email, 'password' => 'a-sufficiently-long-password',
            'code' => $this->totp->getCurrentOtp($devSecret),
        ])->assertForbidden()->assertJsonPath('code', 'totp_enrolment_required');
    }

    public function test_a_wrong_confirmation_code_leaves_the_pending_secret_pending(): void
    {
        [$staff, $secret] = $this->staff();
        $auth = 'Bearer '.$this->tokenFor($staff, $secret);

        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/me/totp', $this->reauth($staff, $secret))->assertOk();

        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/me/totp/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertTrue($staff->fresh()->hasPendingTotp());
        $this->assertTrue($staff->fresh()->hasTotp());
    }

    // ── the audit carve-out ──────────────────────────────────────

    public function test_creating_staff_is_audited_even_for_the_lead_developer(): void
    {
        [$lead, $secret] = $this->staff();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $secret))
            ->postJson('/api/v1/staff/accounts', [
                'name' => 'New Dev', 'email' => 'new@ezbizi.test',
                'password' => 'a-sufficiently-long-password', 'tier' => 'developer',
            ])->assertCreated();

        // Their exemption covers development noise. Minting an account that can
        // read every customer's data is not development noise.
        $this->assertDatabaseHas('audit_events', [
            'action'   => 'staff.account_created',
            'actor_id' => $lead->id,
        ]);
    }

    public function test_the_enrolment_secret_is_never_returned_by_the_listing(): void
    {
        [$lead, $secret] = $this->staff();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($lead, $secret))
            ->getJson('/api/v1/staff/accounts')->assertOk();

        $body = $response->getContent();

        // The value itself, not just the field name — a leak through some other
        // key would pass a name-only check.
        $this->assertStringNotContainsString($secret, $body);

        foreach (['totp_secret', 'pending_totp_secret', 'recovery_codes', 'password'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }
}
