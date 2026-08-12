<?php

namespace App\Support\Auth;

use App\Models\Organization;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * How long a tenant session lasts (plan §6 — `security.session_timeout_minutes`).
 *
 * ── Which product this setting is ────────────────────────────────
 *
 * "Sign out after inactivity (minutes)" and "sign out N minutes after signing
 * in" are two different features, and the setting could have been read as
 * either. The label says inactivity, so inactivity is what is built — relabelling
 * the control to match a weaker implementation would be the same disguised
 * failure this work exists to remove.
 *
 * Before this, neither existed: the token was minted with a flat thirty-day
 * expiry and there was no idle check anywhere, so an unattended browser stayed
 * signed in for a month. That minting was a private `issueToken` on
 * AuthController at the time; it is now App\Services\Auth\IssueSession, which
 * is the one place any entry point gets a session from.
 *
 * ── Where the refusal happens ────────────────────────────────────
 *
 * Not here, and not in the middleware either. Sanctum's own guard already
 * refuses a token whose `expires_at` has passed (Guard::isValidAccessToken), and
 * that refusal is the one thing in this path that does not need writing or
 * testing. So the idle window is expressed AS that expiry, and
 * App\Http\Middleware\SlideIdleWindow pushes it forward on every authenticated
 * request. Stop making requests and it stops moving; when it passes, Sanctum
 * answers 401 and the client shows the sign-in screen it already shows for any
 * other dead token.
 *
 * ── The absolute ceiling, which is a separate thing ──────────────
 *
 * A sliding window alone never ends: a browser polling once an hour would hold a
 * session for years. ABSOLUTE_DAYS keeps the thirty-day outer bound the tokens
 * already had, measured from when the token was minted. No setting controls it,
 * and none should without somebody deciding that it is a product feature rather
 * than a security invariant (§2.1).
 */
final class SessionLifetime
{
    /**
     * The longest a session may live no matter how much it is used.
     *
     * Thirty days because that is what the login path already minted before any
     * of this, and nothing about this change is a reason to extend it.
     */
    public const ABSOLUTE_DAYS = 30;

    /**
     * The shortest idle window an organization may configure.
     *
     * Not a preference — a recovery margin. A mistyped `5` instead of `50` is
     * survivable; a `0` or a `1` would sign the administrator out before they
     * could reach the Settings screen to undo it, and on a platform that cannot
     * send a password reset email (TODO #1) there is no second way back in.
     *
     * Clamped rather than refused at write time, matching
     * RecordNumberGenerator's `max(1, …)` on `numbering.pad_to_digits`:
     * SettingDefinition has no bounds facility, and giving one setting a private
     * validator is the beginning of thirty of them. The registry's help text
     * states the floor so the screen and the code say the same number.
     */
    public const MINIMUM_IDLE_MINUTES = 5;

    public function __construct(
        private readonly SettingsResolver $settings,
        private readonly TenantContext $tenant,
    ) {}

    /** The configured idle window, never shorter than the recovery margin. */
    public function idleMinutesFor(Organization $organization): int
    {
        // Setting rows are tenant-scoped and this is called from both inside a
        // request that has a tenant (SlideIdleWindow) and from IssueSession,
        // which runs during login before there is one. Entering the
        // organization explicitly covers both.
        $configured = $this->tenant->run(
            $organization,
            fn () => $this->settings->get('security.session_timeout_minutes', $organization),
        );

        return max(self::MINIMUM_IDLE_MINUTES, (int) $configured);
    }

    /**
     * When a token should stop being accepted, given when it was issued.
     *
     * The nearer of "idle from now" and "absolute from issue". Taking the nearer
     * rather than the later is what keeps the ceiling a ceiling: an organization
     * configuring a sixty-day idle window gets thirty days, not sixty.
     */
    public function expiryFor(Organization $organization, Carbon $issuedAt): Carbon
    {
        $idle     = now()->addMinutes($this->idleMinutesFor($organization));
        $absolute = $issuedAt->copy()->addDays(self::ABSOLUTE_DAYS);

        return $idle->lt($absolute) ? $idle : $absolute;
    }
}
