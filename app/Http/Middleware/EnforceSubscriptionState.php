<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block writes when the subscription has lapsed (plan §13).
 *
 * The rule: the owner may still sign in and READ everything, but cannot enter
 * new data. Enforced here, on the request, rather than by hiding buttons in the
 * client — a hidden button stops nobody with an API token, and the API is a
 * published product surface (§11.4).
 *
 * Applied by HTTP verb rather than by route, so a write endpoint added later is
 * covered without anyone remembering to add it to a list. Getting this backwards
 * — an allowlist of protected routes — means every new endpoint is unprotected
 * until noticed.
 */
class EnforceSubscriptionState
{
    /** Verbs that mutate. Everything else is a read. */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::WRITE_METHODS, strict: true)) {
            return $next($request);
        }

        $organization = $request->user()?->organization;

        if ($organization === null || ! $organization->isReadOnly()) {
            return $next($request);
        }

        // Exempt: paying, exporting, and privacy rights must keep working when
        // the subscription lapses. Locking someone out of their own data — or
        // out of the ability to pay you — turns a billing problem into a
        // support incident and a chargeback.
        if ($this->isAlwaysPermitted($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This organization is read-only. Its subscription has ended.',
            'code'    => 'subscription_inactive',
            'detail'  => 'You can still view and export your data, and update billing.',
        ], Response::HTTP_PAYMENT_REQUIRED);
    }

    private function isAlwaysPermitted(Request $request): bool
    {
        $exempt = [
            'api/*/billing/*',
            'api/*/exports/*',
            'api/*/privacy/*',
            'api/*/auth/*',

            // Everything BELOW /me — your own password, second factor, sessions
            // and preferences.
            //
            // §13 lists "password & MFA recovery" among the writes that must
            // REMAIN allowed, on the grounds that otherwise expiry locks people
            // out permanently, and somebody who cannot get into their account
            // cannot update their billing either — the one action that ends the
            // situation. This pattern was missing, so a lapsed organization's
            // users could not change their own passwords: the model-layer guard
            // permitted it and the middleware refused it two layers earlier.
            //
            // The trailing segment matters. This covers the sub-resources and
            // deliberately does NOT match `api/v1/me` itself, so renaming
            // yourself is still refused — a display name is what the rest of the
            // organization sees against your work, which makes it business data
            // rather than account recovery.
            //
            // (Written as line comments on purpose: a block comment cannot hold
            // these patterns, because the slash-star in one of them closes it.)
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
