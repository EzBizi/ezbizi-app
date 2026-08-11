<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\PlatformStaff;
use App\Support\Audit\AuditLogger;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * What the control plane records (plan §4.2, §10).
 *
 * The Lead Developer's exemption is deliberate. These are the carve-outs it must
 * not swallow, and the plumbing that has to work for any of it to be traceable.
 */
class StaffAuditTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(Google2FA::class);
    }

    private function signedIn(
        string $tier = PlatformStaff::TIER_LEAD,
        bool $exempt = false,
        ?string $email = null,
    ): array {
        $staff = PlatformStaff::create([
            'name' => 'Jose', 'email' => $email ?? $tier.'@ezbizi.test',
            'password' => 'a-sufficiently-long-password', 'tier' => $tier, 'is_active' => true,
        ]);

        $secret = $this->totp->generateSecretKey(32);
        $staff->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            // Not fillable, deliberately — see the migration. Set here the way
            // the controller sets it, on its own line.
            'audit_exempt' => $exempt,
        ])->save();

        $token = $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email, 'password' => 'a-sufficiently-long-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');

        return [$staff, "Bearer {$token}"];
    }

    // ── the plumbing ─────────────────────────────────────────────

    public function test_the_correlation_id_returned_to_the_client_is_the_one_on_the_row(): void
    {
        [$staff, $auth] = $this->signedIn();

        $response = $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/auth/logout')->assertOk();

        // AuditLogger holds per-request state and must be a shared instance. Left
        // unbound, the middleware stamps one id on the header while every writer
        // mints its own — and "it broke at 3pm" stays unmatchable against the log,
        // which is the one thing a correlation id exists to fix.
        $this->assertSame(
            $response->headers->get('X-Correlation-Id'),
            AuditEvent::where('action', 'staff.signed_out')->value('correlation_id'),
        );
    }

    public function test_a_malformed_correlation_id_header_is_replaced_rather_than_stored(): void
    {
        [$staff, $auth] = $this->signedIn();

        // It lands in a CHAR(36) column. Anything longer would be a 500 raised
        // after the side effect had already committed.
        $this->withHeader('Authorization', $auth)
            ->withHeader('X-Correlation-Id', str_repeat('a', 200))
            ->postJson('/api/v1/staff/auth/logout')
            ->assertOk();

        $stored = AuditEvent::where('action', 'staff.signed_out')->value('correlation_id');

        $this->assertNotSame(str_repeat('a', 200), $stored);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($stored));
    }

    public function test_lead_developer_suppression_reaches_beyond_the_middleware(): void
    {
        [$staff, $auth] = $this->signedIn();

        // Resolved the way a controller would resolve it — a different object
        // entirely unless the binding is shared.
        $this->withHeader('Authorization', $auth)->getJson('/api/v1/staff/organizations');

        $logger = app(AuditLogger::class);

        $written = $logger->suppressForLeadDeveloper(
            fn () => app(AuditLogger::class)->log('something.ordinary')
        );

        $this->assertNull($written, 'Suppression did not reach a separately resolved logger.');
    }

    // ── the carve-outs ───────────────────────────────────────────

    public function test_a_failed_sign_in_is_recorded(): void
    {
        PlatformStaff::create([
            'name' => 'Jose', 'email' => 'lead@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
        ]);

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'lead@ezbizi.test', 'password' => 'wrong', 'code' => '000000',
        ])->assertUnauthorized();

        // Successes alone are not a security record — someone grinding the
        // control plane would otherwise leave nothing but a rate-limiter counter
        // that expires in fifteen minutes.
        $event = AuditEvent::where('action', 'staff.sign_in_failed')->first();

        $this->assertNotNull($event);
        $this->assertSame('lead@ezbizi.test', $event->metadata['email']);
        $this->assertSame('password', $event->metadata['stage']);
    }

    public function test_a_module_state_change_is_recorded_even_for_the_lead_developer(): void
    {
        // 'jobs' is a real registered module now that the vertical slice exists
        // (§18 Phase 2) — this used to register a stand-in for it.
        $this->assertNotNull(app(ModuleRegistry::class)->get('jobs'));

        [$staff, $auth] = $this->signedIn();

        $this->withHeader('Authorization', $auth)
            ->patchJson('/api/v1/staff/modules/jobs', [
                'state'  => 'disabled',
                'reason' => 'Broken export, disabled while it is fixed.',
            ])->assertOk();

        // The only staff action that changes what every customer sees at once,
        // and `changed_by` on the flag row is overwritten by the next change.
        $event = AuditEvent::where('action', 'staff.module_state_changed')->first();

        $this->assertNotNull($event);
        $this->assertSame('jobs', $event->metadata['module']);
        $this->assertSame('enabled', $event->metadata['before']);
        $this->assertSame('disabled', $event->metadata['after']);
    }

    public function test_an_impersonation_row_carries_the_organization_it_concerns(): void
    {
        [$staff, $auth] = $this->signedIn();

        ['organization' => $org] = app(\App\Services\Organizations\ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'admin@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/staff/impersonations', [
                'organization_id' => $org->id,
                'reason'          => 'Investigating a reported export failure.',
            ])->assertCreated();

        // Staff run in platform scope, so nothing infers this — and "everything
        // that happened to this client" is the query the row exists to answer.
        $this->assertSame(
            $org->id,
            AuditEvent::where('action', 'staff.impersonation_started')->value('organization_id'),
        );
    }

    // ── the exemption is a flag on one account, not a tier ───────

    public function test_the_exemption_follows_the_flag_and_not_the_tier(): void
    {
        // The defect §4.2 names in so many words: "a Developer promoted by
        // mistake shouldn't silently stop being audited". suppressesBusinessAudit()
        // returned `tier === lead_developer`, and tier is an ordinary editable
        // field on an ordinary PATCH — so promoting somebody removed them from
        // the business audit trail with nothing anywhere saying it had happened.
        [$promoted] = $this->signedIn(PlatformStaff::TIER_LEAD, exempt: false);
        [$exempt]   = $this->signedIn(PlatformStaff::TIER_DEV, exempt: true, email: 'owner@ezbizi.test');

        $this->assertFalse(
            $promoted->suppressesBusinessAudit(),
            'A Lead Developer without the flag must still be audited.'
        );

        // The flag is what decides, which is the property the whole fix rests on.
        $this->assertTrue($exempt->suppressesBusinessAudit());
    }

    /**
     * A staff endpoint whose only job is to write an ordinary, suppressible
     * entry.
     *
     * Suppression lives for the length of the request — StaffOnly wraps `$next`
     * and restores in a `finally` — so it can only be observed from INSIDE one.
     * Every real staff endpoint records through logStaff, which is deliberately
     * unsuppressible, so there is nothing already routed that answers this
     * question.
     */
    private function routeThatWritesAnOrdinaryEntry(): string
    {
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'staff'])
            ->get('api/staff-audit-probe', function (AuditLogger $audit) {
                $audit->log('something.ordinary');

                return response()->json(['ok' => true]);
            });

        return '/api/staff-audit-probe';
    }

    public function test_a_promoted_developer_is_still_audited_end_to_end(): void
    {
        $probe = $this->routeThatWritesAnOrdinaryEntry();

        [$lead, $leadAuth] = $this->signedIn();
        [$dev, $devAuth]   = $this->signedIn(PlatformStaff::TIER_DEV);

        $this->withHeader('Authorization', $leadAuth)
            ->patchJson("/api/v1/staff/accounts/{$dev->id}", ['tier' => PlatformStaff::TIER_LEAD])
            ->assertOk();

        // Their token survives a tier change, so this is the same session
        // continuing across the promotion — which is what made the old behaviour
        // so quiet: nothing about the request changed, only what it recorded.
        $this->withHeader('Authorization', $devAuth)->getJson($probe)->assertOk();

        $this->assertSame(
            1,
            AuditEvent::where('action', 'something.ordinary')->count(),
            'A promoted Developer stopped being audited.',
        );
    }

    public function test_granting_the_exemption_is_itself_recorded(): void
    {
        [$lead, $auth] = $this->signedIn();

        $this->withHeader('Authorization', $auth)
            ->patchJson("/api/v1/staff/accounts/{$lead->id}", ['audit_exempt' => true])
            ->assertOk()
            ->assertJsonPath('data.audit_exempt', true);

        $event = AuditEvent::where('action', 'staff.account_updated')->latest('occurred_at')->first();

        // The account that grants itself the exemption is the one whose next
        // actions vanish, so the grant is the last thing that can still be
        // evidence. logStaff writes it, and suppression does not reach logStaff.
        $this->assertNotNull($event);
        $this->assertFalse($event->metadata['before']['audit_exempt']);
        $this->assertTrue($event->metadata['after']['audit_exempt']);
    }

    public function test_a_developer_may_not_be_exempt(): void
    {
        [$lead, $auth] = $this->signedIn();
        [$dev]         = $this->signedIn(PlatformStaff::TIER_DEV);

        $response = $this->withHeader('Authorization', $auth)
            ->patchJson("/api/v1/staff/accounts/{$dev->id}", ['audit_exempt' => true])
            ->assertStatus(422);

        // A Developer reaches client records only through an impersonation the
        // client approved; exempting them would remove the record of approved
        // access, which is the opposite of what §4.2 asks for.
        $this->assertFalse($dev->fresh()->suppressesBusinessAudit());

        /*
         * And it has to say the right thing, because the screen shows server
         * refusals verbatim. Both directions used to share one message, so
         * granting the exemption to a Developer answered "This account is exempt
         * from the audit trail ... Revoke the exemption first, then change the
         * tier" — the account is not exempt, they were trying to make it so, and
         * there is nothing to revoke. Every clause wrong for what was clicked.
         */
        $message = $response->json('message');

        $this->assertStringNotContainsString('Revoke the exemption first', $message);
        $this->assertStringContainsString('Only a Lead Developer may be exempt', $message);
    }

    public function test_the_demotion_refusal_still_talks_about_the_demotion(): void
    {
        [$lead, $auth] = $this->signedIn(PlatformStaff::TIER_LEAD, exempt: true);
        [$other]       = $this->signedIn(PlatformStaff::TIER_LEAD, exempt: false, email: 'second@ezbizi.test');

        // The other direction into the same guard: the exemption is not moving,
        // the tier is, and "revoke it first" is the thing to do about that.
        $message = $this->withHeader('Authorization', $auth)
            ->patchJson("/api/v1/staff/accounts/{$lead->id}", ['tier' => PlatformStaff::TIER_DEV])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('Revoke the exemption first', $message);
    }

    public function test_a_removed_account_cannot_still_be_holding_the_exemption(): void
    {
        [$actor, $auth] = $this->signedIn();
        [$ghost]        = $this->signedIn(PlatformStaff::TIER_LEAD, exempt: true, email: 'ghost@ezbizi.test');

        $ghost->delete();

        /*
         * PlatformStaff uses SoftDeletes, so a deleted holder disappears from
         * the holder query and does NOT lose the flag. "Only one account may
         * hold it at a time" was therefore only true of accounts that happened
         * not to be in the bin: grant it to a second one and restoring the first
         * gives you two, silently.
         */
        $message = $this->withHeader('Authorization', $auth)
            ->patchJson("/api/v1/staff/accounts/{$actor->id}", ['audit_exempt' => true])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString($ghost->email, $message);
        $this->assertFalse($actor->fresh()->suppressesBusinessAudit());
    }

    public function test_only_one_account_may_hold_the_exemption(): void
    {
        [$lead, $auth] = $this->signedIn(PlatformStaff::TIER_LEAD, exempt: true);
        [$other]       = $this->signedIn(PlatformStaff::TIER_LEAD, exempt: false, email: 'second@ezbizi.test');

        $response = $this->withHeader('Authorization', $auth)
            ->patchJson("/api/v1/staff/accounts/{$other->id}", ['audit_exempt' => true])
            ->assertStatus(422);

        // §4.2 says "the single Lead Developer account". The refusal names who
        // holds it, so the answer to "why can't I?" is on the screen.
        $this->assertStringContainsString($lead->email, $response->json('message'));
        $this->assertFalse($other->fresh()->suppressesBusinessAudit());
    }

    public function test_an_exempt_account_cannot_be_demoted_without_revoking_it_first(): void
    {
        [$lead, $auth] = $this->signedIn(PlatformStaff::TIER_LEAD, exempt: true);
        [$other]       = $this->signedIn(PlatformStaff::TIER_LEAD, exempt: false, email: 'second@ezbizi.test');

        // Demoting the exempt account would otherwise leave an exempt Developer —
        // the same mistake as exempting one, arriving from the other direction.
        $this->withHeader('Authorization', $auth)
            ->patchJson("/api/v1/staff/accounts/{$other->id}", ['audit_exempt' => true])
            ->assertStatus(422);

        $this->withHeader('Authorization', $auth)
            ->patchJson("/api/v1/staff/accounts/{$lead->id}", ['tier' => PlatformStaff::TIER_DEV])
            ->assertStatus(422);

        $this->assertTrue($lead->fresh()->isLeadDeveloper());
    }

    public function test_the_exempt_accounts_business_actions_are_not_recorded(): void
    {
        $probe = $this->routeThatWritesAnOrdinaryEntry();

        [$lead, $auth] = $this->signedIn(PlatformStaff::TIER_LEAD, exempt: true);

        $this->withHeader('Authorization', $auth)->getJson($probe)->assertOk();

        // The owner's decision, faithfully: ordinary work by that one account
        // does not reach the business trail. Everything above is what keeps the
        // set of accounts this is true of equal to one.
        $this->assertSame(0, AuditEvent::where('action', 'something.ordinary')->count());
    }

    public function test_recovery_codes_would_be_redacted_if_they_ever_reached_metadata(): void
    {
        $redacted = app(AuditLogger::class)->redact([
            'recovery_codes'      => ['ABCDE-FGHIJ'],
            'recovery_code_count' => 8,
        ]);

        // A recovery code is a full second-factor bypass. The count is just a
        // number and has to stay readable, which is why the needle is plural.
        $this->assertSame('[redacted]', $redacted['recovery_codes']);
        $this->assertSame(8, $redacted['recovery_code_count']);
    }
}
