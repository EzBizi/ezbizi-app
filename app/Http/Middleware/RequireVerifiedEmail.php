<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block writes until the signup address has been confirmed (TODO #22).
 *
 * The rule matches EnforceSubscriptionState next door: you may sign in and READ
 * everything, and you may not enter data. Written the same way and for the same
 * reason — keyed off the HTTP VERB rather than a list of protected routes, so a
 * write endpoint added next month is covered without anybody remembering. An
 * allowlist gets this backwards and leaves every new endpoint open until noticed.
 *
 * ── Why a gate at all ────────────────────────────────────────────
 *
 * config/ezbizi.php states the reason self-serve signup was closed: "an open
 * front door with no mailbox check is a way to fill the database from a script".
 * A confirmation email that gates nothing does not answer that — the script
 * still gets a working tenant, it just also gets mail. The gate is what makes
 * the mailbox check mean something.
 *
 * ── Why writes rather than everything ────────────────────────────
 *
 * Refusing reads too would mean signing up and being shown a locked door with no
 * explanation the client could read, since /me is how the client learns who you
 * are and what state you are in. SignupTest pinned this shape before the feature
 * existed: "a WRITE, not just a read: `verified` middleware would let /me through
 * and refuse this."
 *
 * ── Only ever a User ─────────────────────────────────────────────
 *
 * Staff accounts are created by `ezbizi:create-staff`, not by anybody proving
 * they can read a mailbox, so they carry no verification state and this must not
 * invent one for them. Anything that is not a User with a null timestamp passes
 * straight through.
 */
class RequireVerifiedEmail
{
    /** Verbs that mutate. Everything else is a read. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::WRITE_METHODS, strict: true)) {
            return $next($request);
        }

        $user = $request->user();

        // Not a User at all (staff), or already confirmed. Note the property is
        // read rather than a hasVerifiedEmail() helper: User does not implement
        // MustVerifyEmail, because Laravel's implementation of it signs a URL
        // and nginx logs request lines — which is the trap routes/api.php
        // records for invitations.
        if (! $user instanceof \App\Models\User || $user->email_verified_at !== null) {
            return $next($request);
        }

        if ($this->isAlwaysPermitted($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Confirm your email address before adding anything.',
            'code'    => 'email_unverified',
            // Says only what a reader can actually do, and that is a claim
            // about the CLIENT rather than about this file.
            //
            // It briefly said less than this. The original wording told people
            // to ask for another link while nothing in the Flutter client
            // called POST auth/verify/resend - a sentence naming a control on
            // no screen, which is worse than a shorter one because the reader
            // believes there is a way out and goes looking for it. The
            // endpoint existing was never the point; being reachable was.
            //
            // The chip is in the chrome now, wide and narrow both, and it
            // calls that endpoint. If it is ever removed, shorten this back:
            // the sentence is only true while the button is there.
            'detail'  => 'We sent a confirmation link to your address when you signed '
                        .'up. You can view everything here in the meantime, and the '
                        .'"Confirm your email" button at the top will send another.',
        ], Response::HTTP_FORBIDDEN);
    }

    private function isAlwaysPermitted(Request $request): bool
    {
        $exempt = [
            // The remedy itself. Confirming and resending are POSTs under /auth,
            // so without this the gate refuses the only two actions that can
            // lift it — a guard whose own premise makes itself permanent.
            'api/*/auth/*',

            // Everything BELOW /me: your own password, second factor, sessions
            // and preferences. Same reasoning EnforceSubscriptionState gives —
            // somebody locked out of their own account cannot fix anything,
            // including this. The trailing segment matters: it deliberately does
            // NOT match `api/v1/me` itself, so renaming yourself stays refused,
            // because a display name is what the rest of the organization sees
            // against your work.
            'api/*/me/*',
        ];

        // Deliberately NOT exempt, unlike the subscription gate: billing and
        // exports. That gate protects an established tenant with data and an
        // invoice; this one stands in front of an account that has existed for a
        // minute, has nothing to export, and owes nothing.

        foreach ($exempt as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
