<?php

namespace App\Support\Monitoring;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Whose devices are never screen-captured, live-viewed or remote-controlled:
 *
 *   - a person whose role holds `monitoring.unmonitored` (Jose, 2026-11-17:
 *     "Lead Managers should not be desktop monitored as they may handle
 *     financial information"). Administrators hold every permission
 *     implicitly, so they are left out of this rule and stay monitored;
 *   - the Lead Developer, whatever their role (below).
 *
 * The Lead Developer's own device is never screen-captured, live-viewed or
 * remote-controlled — a SECURITY exemption the owner confirmed. It still shows
 * online and reports PC specs; only the screen-reaching capabilities are cut.
 *
 * ── The single source of truth ────────────────────────────────────
 *
 * Every guard in the module — the heartbeat that strips the capabilities, and
 * each ingest/view/control endpoint that refuses defence-in-depth — asks this
 * one question, so "who is exempt" is decided in exactly one place rather than
 * re-derived, slightly differently, at each door.
 *
 * // ponytail: keyed on EMAIL, not on a join. A monitored device belongs to an
 * // org `User`; the Lead Developer is a `PlatformStaff` — a SEPARATE auth
 * // identity, in its own table, with no `user_id` column pointing back at any
 * // user. `platform_staff.email` is globally unique, and the owner confirmed
 * // his org-user login uses the SAME email as his Lead-Developer staff
 * // account, so a matching active lead-developer email is the only link the
 * // two identities have. A lower-cased compare because an email is the same
 * // address whatever its case.
 */
final class ScreenMonitoringExemption
{
    /**
     * Is this device's owner exempt from screen monitoring?
     *
     * True iff an ACTIVE `lead_developer` PlatformStaff shares the user's
     * (lower-cased) email. One `exists()` query, no rows loaded.
     */
    /**
     * The `specs` keys that are SCREEN CONTENT rather than facts about the
     * machine — what a person has in front of them, not what they are running
     * it on. Everything else in that blob describes hardware.
     *
     * They ride in `specs` only because the agent reads them in the same
     * pass, and they are named here, once, because three places have to agree
     * about them: the agent declines to send them when exempt, the heartbeat
     * strips them again if a tampered client sends them anyway, and the
     * roster shows them only to a role that may also see screenshots. A key
     * added to the agent and forgotten in one of those three would leak
     * quietly, so the list lives in one file and the others read it.
     *
     * @var string[]
     */
    public const SCREEN_CONTENT_SPEC_KEYS = ['active_window', 'browser_tabs'];

    /** The grant that takes a role's own PCs out of screen monitoring. */
    public const UNMONITORED = 'monitoring.unmonitored';

    /** The role's tick; an administrator "holds" everything, so not theirs. */
    private static function roleExempts(User $user): bool
    {
        // Once per loaded user: the heartbeat and capture routes ask on every
        // poll (Monitoring, 11-17). A WeakMap entry dies with the request's
        // User object, so a role change is read on the next request.
        static $memo = null;
        $memo ??= new \WeakMap;

        // The user's own role, by id: safe without a tenant, and some callers
        // run outside one.
        return $memo[$user] ??= app(TenantContext::class)->runWithoutTenant(
            fn () => ! $user->isAdministrator() && $user->hasPermission(self::UNMONITORED),
        );
    }

    /** The sentence every screen-reaching guard refuses with. */
    public const REFUSAL = "This device's owner is exempt from screen monitoring.";

    /**
     * [isExempt] for a roster, with the Lead Developer emails read once
     * ([exemptEmails]) instead of per row.
     *
     * @param  string[]  $exemptEmails
     */
    public static function isExemptAmong(User $user, array $exemptEmails): bool
    {
        // ponytail: hasPermission per row (role permissions are loaded once
        // per role and cached); batch it if a roster ever runs to thousands.
        return self::roleExempts($user)
            || ($user->email !== null
                && in_array(mb_strtolower($user->email), $exemptEmails, true));
    }

    public static function isExempt(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::roleExempts($user)) {
            return true;
        }

        if ($user->email === null) {
            return false;
        }

        return PlatformStaff::query()
            ->where('is_active', true)
            ->where('tier', PlatformStaff::TIER_LEAD)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])
            ->exists();
    }

    /**
     * The set of active lead-developer emails, lower-cased — for a batch check
     * that would otherwise fire [isExempt] once per row (an N+1 across a device
     * roster). Membership is `in_array($user->email lower-cased, ...)`.
     *
     * @return array<int, string>
     */
    public static function exemptEmails(): array
    {
        return PlatformStaff::query()
            ->where('is_active', true)
            ->where('tier', PlatformStaff::TIER_LEAD)
            ->pluck('email')
            ->map(fn (string $email) => mb_strtolower($email))
            ->all();
    }
}
