<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationLink;
use App\Notifications\PasswordResetLink;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The two queued auth mails must survive the QUEUE WORKER, which has no tenant.
 *
 * NotificationNotice failed 7,705 times between 2026-09-16 and 2026-09-28 for
 * one reason: SerializesModels records a model as its id PLUS the names of the
 * relations loaded on it, and the worker re-loads each of those relations
 * through its normal query — TenantScope included — in a process with no
 * tenant at all. See NotificationEmailTest for the full account.
 *
 * These two carry a User in their constructor, so they have the same exposure.
 * They were not failing: every caller today hands them a User loaded with
 * `organization` only, and Organization is deliberately unscoped. They were
 * safe because of what their callers happened to load, and the first caller
 * to pass a User that had been through a reach check — assignments loaded —
 * would lose that person's password reset in silence.
 *
 * ── Why the recipient is built with ASSIGNMENTS loaded ───────────
 *
 * It is the only case that can tell the fix from its absence. A User with just
 * `organization` restores fine either way, so a test built the way today's
 * callers build it would pass with the fix reverted.
 *
 * ── Why the mail is rendered after the restore ───────────────────
 *
 * EmailVerificationLink reads `$this->user->organization->name`. Stripping
 * relations means the worker now LAZY-loads it, which is only safe because
 * Organization is unscoped and nothing here enables strict mode. Rendering
 * toMail() in the worker's conditions is what proves the hardening did not
 * trade one silent failure for another.
 */
class QueuedAuthMailWorkerTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** A User the way a reach check leaves it, plus the org name to look for. */
    private function reachCheckedUser(): array
    {
        $tenant = $this->provisionTenant('Worker Test Org');

        $user = $this->inTenant($tenant['org'], function () use ($tenant): User {
            $admin = $tenant['admin'];
            $admin->loadMissing('assignments.assignedCompany', 'organization');

            return $admin;
        });

        $this->assertTrue($user->relationLoaded('assignments'), 'The case needs a tenant-scoped relation loaded.');

        // The control: if a tenant leaked out of inTenant(), the restore would
        // be scoped and succeed for the wrong reason.
        $this->assertFalse(app(TenantContext::class)->has(), 'The worker has no tenant; neither may this.');

        return [$user, $tenant['org']->name];
    }

    public function test_a_password_reset_survives_the_worker(): void
    {
        [$user] = $this->reachCheckedUser();

        $notice = new PasswordResetLink($user, 'a-token', Carbon::now()->addHour());

        // The worker.
        $restored = unserialize(serialize($notice));

        $mail = $restored->toMail(new AnonymousNotifiable);
        $this->assertSame("Hello {$user->name},", $mail->greeting);
    }

    public function test_an_email_verification_survives_the_worker_and_still_names_the_organization(): void
    {
        [$user, $orgName] = $this->reachCheckedUser();

        $notice = new EmailVerificationLink($user, 'a-token', Carbon::now()->addDay());

        // The worker.
        $restored = unserialize(serialize($notice));

        $mail = $restored->toMail(new AnonymousNotifiable);
        $this->assertSame("Hello {$user->name},", $mail->greeting);
        $this->assertStringContainsString($orgName, implode("\n", $mail->introLines));
    }
}
