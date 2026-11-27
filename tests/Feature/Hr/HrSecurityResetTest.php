<?php

namespace Tests\Feature\Hr;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * An employer clears a colleague's second factor or hands them a reset link.
 *
 * Built 3 Sep after Rodrigo lost his authenticator and the only way back in
 * was tinker on the production box. The design points each have a test:
 * it is its own permission, never yourself, bounded by reach, kills every
 * session, and never hands the employer a secret.
 */
class HrSecurityResetTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** A person with a confirmed authenticator and two live sessions. */
    private function personWithTotp(array $t): \App\Models\User
    {
        $person = $this->userWithRole($t, 'Technician', ['hr.view']);
        $this->inTenant($t['org'], function () use ($person) {
            $person->forceFill([
                'totp_secret' => 'JBSWY3DPEHPK3PXP',
                'totp_confirmed_at' => now(),
                'recovery_codes' => ['aaaa-bbbb', 'cccc-dddd'],
            ])->save();
            $person->createToken('phone');
            $person->createToken('desktop');
        });

        return $person;
    }

    public function test_it_needs_its_own_permission_and_not_merely_hr_edit(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->personWithTotp($t);
        $editor = $this->userWithRole($t, 'Editor', ['hr.view', 'hr.edit']);

        $this->signedInAs($editor)
            ->postJson("/api/v1/hr/people/{$person->id}/totp/reset")
            ->assertForbidden();

        $this->assertTrue($this->inTenant($t['org'], fn () => $person->fresh()->hasTotp()), 'hr.edit alone cleared a second factor');
    }

    public function test_it_clears_the_factor_signs_them_out_everywhere_and_is_audited(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->personWithTotp($t);
        $admin = $this->userWithRole($t, 'Resetter', ['hr.view', 'hr.security.reset']);

        $this->assertSame(2, $this->inTenant($t['org'], fn () => $person->tokens()->count()));

        $this->signedInAs($admin)
            ->postJson("/api/v1/hr/people/{$person->id}/totp/reset")
            ->assertOk()
            ->assertJsonPath('data.had_factor', true);

        $this->inTenant($t['org'], function () use ($person) {
            $fresh = $person->fresh();
            $this->assertFalse($fresh->hasTotp());
            $this->assertFalse($fresh->hasPendingTotp());
            $this->assertNull($fresh->recovery_codes);
            $this->assertSame(0, $person->tokens()->count(), 'a reset factor left its sessions alive');
        });

        $this->assertDatabaseHas('audit_events', [
            'action' => 'person.totp_reset',
            'target_id' => $person->id,
            'actor_id' => $admin->id,
        ]);
    }

    /**
     * The design difference from the staff side, and the one that matters:
     * an employer who is handed the new secret can mint that employee's codes
     * forever. Nothing about the replacement factor is returned.
     */
    public function test_the_response_carries_no_secret(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->personWithTotp($t);
        $admin = $this->userWithRole($t, 'Resetter', ['hr.view', 'hr.security.reset']);

        $body = $this->signedInAs($admin)
            ->postJson("/api/v1/hr/people/{$person->id}/totp/reset")
            ->json();

        $flat = json_encode($body);
        $this->assertStringNotContainsString('secret', $flat);
        $this->assertStringNotContainsString('uri', $flat);
        $this->assertStringNotContainsString('otpauth', $flat);
    }

    public function test_never_on_yourself(): void
    {
        $t = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($t, 'Resetter', ['hr.view', 'hr.security.reset']);
        $this->inTenant($t['org'], fn () => $admin->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_confirmed_at' => now()])->save());

        $this->signedInAs($admin)
            ->postJson("/api/v1/hr/people/{$admin->id}/totp/reset")
            ->assertForbidden();

        $this->assertTrue($this->inTenant($t['org'], fn () => $admin->fresh()->hasTotp()), 'a stolen session could strip its own second factor');
    }

    /**
     * The sideways case: a branch administrator and a colleague at the other
     * branch. Reach is what keeps this endpoint from being "reset anyone in
     * the organization" for anybody holding the key.
     */
    public function test_bounded_by_reach(): void
    {
        $t = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($t, 'Alpha F2');
        $person = $this->userWithRole($t, 'Technician', ['hr.view'], onlyAt: $elsewhere);
        $this->inTenant($t['org'], fn () => $person->forceFill([
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'totp_confirmed_at' => now(),
        ])->save());

        $narrow = $this->userWithRole($t, 'Branch resetter', ['hr.view', 'hr.security.reset'], onlyAt: $t['franchise']);

        $this->signedInAs($narrow)
            ->postJson("/api/v1/hr/people/{$person->id}/totp/reset")
            ->assertForbidden();

        $this->assertTrue(
            $this->inTenant($t['org'], fn () => $person->fresh()->hasTotp()),
            'a branch administrator reset a factor at a branch they cannot act on',
        );
    }

    public function test_a_person_with_no_factor_is_still_signed_out_and_the_audit_says_so(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['hr.view']);
        $this->inTenant($t['org'], fn () => $person->createToken('phone'));
        $admin = $this->userWithRole($t, 'Resetter', ['hr.view', 'hr.security.reset']);

        $this->signedInAs($admin)
            ->postJson("/api/v1/hr/people/{$person->id}/totp/reset")
            ->assertOk()
            ->assertJsonPath('data.had_factor', false);

        $this->assertSame(0, $this->inTenant($t['org'], fn () => $person->tokens()->count()));
        $this->assertDatabaseHas('audit_events', ['action' => 'person.totp_reset', 'target_id' => $person->id]);
    }

    public function test_a_reset_link_is_issued_single_use_and_audited(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['hr.view']);
        $admin = $this->userWithRole($t, 'Resetter', ['hr.view', 'hr.security.reset']);

        $body = $this->signedInAs($admin)
            ->postJson("/api/v1/hr/people/{$person->id}/reset-link")
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('/reset?token=', $body['url']);
        $this->assertNotEmpty($body['expires_at']);
        $this->assertSame(1, DB::table('password_resets')->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'person.password_reset_link_issued',
            'target_id' => $person->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_the_reset_link_also_needs_the_permission(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['hr.view']);
        $editor = $this->userWithRole($t, 'Editor', ['hr.view', 'hr.edit']);

        $this->signedInAs($editor)
            ->postJson("/api/v1/hr/people/{$person->id}/reset-link")
            ->assertForbidden();

        $this->assertSame(0, DB::table('password_resets')->count());
    }

    /**
     * Never on somebody whose role you could not give (full-code audit,
     * 2026-11-27): a non-administrator with the permission cannot clear an
     * Administrator's authenticator or fetch their reset link.
     */
    public function test_a_non_administrator_cannot_reset_an_administrator(): void
    {
        $t = $this->provisionTenant('Alpha');
        $target = $t['admin'];
        $this->inTenant($t['org'], fn () => $target->forceFill([
            'totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_confirmed_at' => now(),
        ])->save());
        $resetter = $this->userWithRole($t, 'Resetter', ['hr.view', 'hr.security.reset']);

        $this->signedInAs($resetter)->postJson("/api/v1/hr/people/{$target->id}/totp/reset")->assertForbidden();
        $this->signedInAs($resetter)->postJson("/api/v1/hr/people/{$target->id}/reset-link")->assertForbidden();
        $this->assertTrue($this->inTenant($t['org'], fn () => $target->fresh()->hasTotp()), 'An administrator lost their second factor.');

        // The control: the same person still resets someone they could have hired.
        $this->signedInAs($resetter)->postJson("/api/v1/hr/people/{$this->personWithTotp($t)->id}/totp/reset")->assertOk();
    }
}
