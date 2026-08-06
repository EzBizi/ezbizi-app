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
        foreach (['api/*/billing/*', 'api/*/exports/*', 'api/*/privacy/*', 'api/*/auth/*'] as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
