<?php

namespace App\Support\Auth;

use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * What `security.require_two_factor` actually means (plan §6).
 *
 * ── The shape of the enforcement, and why it is this shape ───────
 *
 * The obvious reading of "require two-factor authentication" is: refuse the
 * sign-in of anybody without one. That reading would have locked the owner out
 * of their own platform the first time they switched it on, permanently —
 * production holds exactly one account, and the recovery path is a password
 * reset email, which cannot be sent because nothing on this platform can send
 * mail at all (TODO #1).
 *
 * So the requirement routes people TO enrolment rather than away from the door:
 *
 *   · Sign-in is untouched. Somebody with the setting on and no authenticator
 *     signs in exactly as before, and gets a token.
 *   · That token can reach their own account and nothing else — the enrolment
 *     endpoints, their password, their sessions, and the /me the client needs
 *     to draw a screen at all. Business data is refused until they enrol.
 *   · The moment a code confirms the authenticator, everything opens.
 *
 * The refusal is therefore a detour with a visible exit, not a wall. The test
 * that pins it is `test_requiring_two_factor_never_locks_an_unenrolled_user_out`
 * in tests/Feature/Api/SecuritySettingsTest.php, and it was written before this
 * class was.
 *
 * ── Why a class rather than a method on the controller ───────────
 *
 * TwoFactorController asks "is this required?" to decide whether somebody may
 * switch their factor OFF; the middleware asks the same question to decide
 * whether they may go anywhere without one. Two copies of that read would drift
 * the first time either changed — and they must agree about the SUBJECT in
 * particular, which is the part that is easy to get subtly wrong.
 */
final class TwoFactorPolicy
{
    public function __construct(
        private readonly SettingsResolver $settings,
        private readonly TenantContext $tenant,
    ) {}

    /** Has this user's organization made a second factor mandatory? */
    public function requiredFor(User $user): bool
    {
        $organization = $user->organization;

        if ($organization === null) {
            return false;
        }

        // Setting rows are tenant-scoped. Every caller today reaches here inside
        // a request that has already established the tenant, so this re-enters
        // the organization it is already in — but stating it means a caller from
        // a queue, a command or a test helper cannot silently read nothing.
        return (bool) $this->tenant->run(
            $organization,
            fn () => $this->settings->get('security.require_two_factor', $organization),
        );
    }

    /**
     * Whose second factor this actually is.
     *
     * A linked account signs in with the STAFF credentials and the STAFF
     * authenticator (§4.2), so the factor that matters lives there. Asking the
     * user row instead would tell an EzBizi staff member with a perfectly good
     * authenticator that they had not enrolled — and send them enrolling a second
     * one that no sign-in ever asks for.
     */
    public function subjectFor(User $user): Model
    {
        return PlatformStaffLink::staffFor($user->getKey()) ?? $user;
    }

    /** Required by the organization, and not yet satisfied. */
    public function mustEnrol(User $user): bool
    {
        return $this->requiredFor($user) && ! $this->subjectFor($user)->hasTotp();
    }
}
