<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff sign-in and enrolment are not served on the customer host.
 *
 * The Development tab still talks to /api/v1/staff with a token issued after
 * the authenticator on that tab, or from dev.ezbizi.com. What this stops is a
 * phish that looks like app.ezbizi.com having a staff login to POST to.
 */
class RefuseStaffAuthOnCustomerHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = parse_url((string) config('ezbizi.hosts.customer'), PHP_URL_HOST);

        if (is_string($customer) && strcasecmp($request->getHost(), $customer) === 0) {
            abort(Response::HTTP_NOT_FOUND, 'This endpoint is not served here.');
        }

        return $next($request);
    }
}
