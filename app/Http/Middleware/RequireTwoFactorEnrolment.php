<?php

namespace App\Http\Middleware;

use App\Support\Auth\TwoFactorPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send an un-enrolled user to enrolment when their organization requires a
 * second factor (plan §6 — `security.require_two_factor`).
 *
 * ── The refusal is a detour, never a locked door ─────────────────
 *
 * Read TwoFactorPolicy for why. In one line: production holds a single account,
 * the owner's, and the only recovery from a mistaken lockout is a password reset
 * email that this platform cannot send (TODO #1). So sign-in is untouched and
 * the account screen stays reachable; what closes is the business.
 *
 * ── Why the exemptions are a list of paths, not a list of routes ─
 *
 * Same reasoning as EnforceSubscriptionState next door, and deliberately almost
 * the same list. A route-by-route allowlist means every endpoint added later is
 * exempt until somebody remembers to protect it, which is the wrong default for
 * a security control. Matching by path means the default is refusal and the
 * exceptions are visible in one place.
 *
 * Everything below `/me` is open, not just the enrolment endpoints. The screen
 * that holds the enrolment control is the account screen, and it also loads the
 * session list, the avatar and the profile — refuse any one of those and the
 * page renders an error instead of the control somebody was sent there to use.
 * `/me` itself is open for a blunter reason: the client calls it before it can
 * draw anything at all, so refusing it replaces the whole app with a sign-in
 * screen that will not help.
 *
 * None of that widens the hole meaningfully. Everything under `/me` is bound to
 * the caller's own record — their password, their sessions, their preferences,
 * their second factor. No customer, job, setting or export is reachable.
 */
class RequireTwoFactorEnrolment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // EzChat's chat-only token asks no authenticator (Jose, 11-27).
        if ($user === null || $this->isAlwaysPermitted($request)
            || \App\Support\Auth\ChatToken::is($user->currentAccessToken())) {
            return $next($request);
        }

        if (! app(TwoFactorPolicy::class)->mustEnrol($user)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Your organization requires two-factor authentication. '
                .'Set up your authenticator to carry on.',

            // Distinct from a permission failure, because the fix is different
            // and so is the screen. The client routes on this rather than on the
            // status, the same way it does for `subscription_inactive`.
            'code'    => 'two_factor_enrolment_required',
            'detail'  => 'Your account settings stay available so you can enrol.',
        ], Response::HTTP_FORBIDDEN);
    }

    private function isAlwaysPermitted(Request $request): bool
    {
        $exempt = [
            // Signing out. Somebody who does not want to enrol must be able to
            // leave rather than be stuck holding a token they cannot use.
            'api/*/auth/*',

            // The launch payload, and everything about your own account —
            // enrolment included. See the docblock.
            //
            // (Line comments, not a block: the slash-star in these patterns
            // would close one.)
            'api/*/me',
            'api/*/me/*',
        ];

        foreach ($exempt as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
