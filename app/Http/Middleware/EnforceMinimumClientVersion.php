<?php

namespace App\Http\Middleware;

use App\Models\PlatformMinimumClientVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Refuse client builds the API can no longer serve correctly.
 *
 * ── THE TWO HEADERS ──────────────────────────────────────────────
 *
 * The client must send exactly these, spelled exactly this way:
 *
 *     X-EzBizi-Client-Platform: windows
 *     X-EzBizi-Client-Version:  0.0.0030
 *
 * A header contract that lives in two files is a header contract that drifts,
 * and this one did: the client shipped `X-Client-Platform` / `X-Client-Version`
 * while this class read the `X-EzBizi-` spelling, so the gate saw no platform
 * header from any real client and fell open on every request. The whole feature
 * was inert while both test suites were green, because each side built its
 * expectations from its own spelling and the two literals never met.
 *
 * So the names are now pinned as LITERAL STRINGS on both sides — here in
 * tests/Feature/Api/MinimumClientVersionTest (the test named
 * `..._the_header_names_the_client_actually_sends`, which deliberately does not
 * use the constants below) and in client/test/client_version_gate_test. A test
 * that reads PLATFORM_HEADER to build the request it then asserts on is a test
 * of nothing: the middleware cannot disagree with itself.
 *
 * Platform is one of web, windows, macos, linux, android, ios — the list on
 * PlatformMinimumClientVersion::PLATFORMS. Matched case-insensitively and
 * trimmed, because "Windows" from a platform API and "windows " from a
 * hand-written config are the same answer and refusing one of them would be a
 * lockout caused by capitalisation.
 *
 * Version is the contents of client/VERSION, verbatim — "0.0.0030". A Flutter
 * build suffix ("0.0.0030+41") is accepted and ignored.
 *
 * Custom `X-` headers rather than squeezing this into User-Agent: the browser
 * owns User-Agent and will not let the web client set it, so the one platform
 * that is guaranteed to be present could not have participated.
 *
 * ── THE HEADERS ARE UNTRUSTED, AND THAT IS FINE ──────────────────
 *
 * Nothing stops a client sending "ios" and "9.9.9999" and sailing through. That
 * is a considered conclusion rather than a hole nobody noticed.
 *
 * This is a COURTESY CHECK, NOT A SECURITY BOUNDARY. The only thing a liar wins
 * is the right to keep using an app that will misbehave against an API that has
 * moved past it — they have dodged their own upgrade prompt and nothing else.
 * Nothing anywhere in this application may be authorised on the strength of
 * these two headers: not a permission, not a tenancy, not a feature flag, not a
 * rate limit. Authorisation comes from the token, which the client cannot forge.
 *
 * Making these trustworthy would mean signed builds and an attestation exchange
 * on every request — a large amount of machinery to defend against a customer
 * choosing to keep their own software broken.
 *
 * ── IT FAILS OPEN ────────────────────────────────────────────────
 *
 * Every uncertainty allows: no headers, unknown platform, unreadable version,
 * no configured minimum, a database that will not answer, an exception from
 * anywhere in the decision. The reasoning in full is on
 * PlatformMinimumClientVersion::permits(), and it is the single most important
 * property of this feature: every client shipped to date sends no version header
 * at all, so a gate that refused what it could not identify would lock every
 * customer — and every member of staff who would need to sign in to switch it
 * off — out of the entire product the moment it deployed.
 *
 * ── WHY 426 ──────────────────────────────────────────────────────
 *
 * 426 Upgrade Required is exactly this situation and nothing else in EzBizi
 * emits it, so no existing client path can mistake it for something familiar.
 *
 * The alternatives were all worse in the same specific way — they mean something
 * the client already reacts to. 401 means the credentials are wrong, and every
 * client answers it by discarding the session and showing the sign-in screen: an
 * out-of-date app would silently sign the user out and then fail to sign back
 * in, forever, with no explanation. 403 means this account may not, which sends
 * the customer to their administrator to ask for a permission that would not
 * help. 400 means the request was malformed, which is a bug report against us.
 */
class EnforceMinimumClientVersion
{
    public const PLATFORM_HEADER = 'X-EzBizi-Client-Platform';

    public const VERSION_HEADER = 'X-EzBizi-Client-Version';

    /**
     * The paths this gate must never close.
     *
     * A gate that blocks the way out of itself is a dead end, and a dead end
     * here means a customer with a stale app who cannot upgrade, cannot sign
     * out, and cannot be diagnosed.
     *
     * Written as line comments rather than one block comment because the
     * slash-star in a path pattern would close it — the same reason
     * EnforceSubscriptionState's exemption list is commented this way.
     */
    private const ALWAYS_PERMITTED = [
        // Signing out. Somebody stuck behind this refusal must still be able to
        // put the app down cleanly and hand the machine to a colleague. The
        // pattern's `*` crosses slashes, so this covers the tenant plane
        // (api/v1/auth/logout) and the control plane (api/v1/staff/auth/logout)
        // in one line.
        'api/*/auth/logout',

        // The health screen, and the mail test that hangs off it. This is the
        // page somebody opens to find out WHY the product is behaving oddly; a
        // version gate that blanked it would be hiding the evidence of its own
        // decision. It is staff-only and authenticated in its own right, so
        // exempting it from this gate grants nobody anything.
        'api/*/staff/health',
        'api/*/staff/health/*',

        // Reading and setting the minimums themselves. If a raise turns out to
        // have been too aggressive, the person undoing it must not be refused by
        // the very rule they are trying to lift — otherwise a bad number is
        // unrecoverable without a shell on the server.
        'api/*/staff/client-versions',
        'api/*/staff/client-versions/*',

        // Signing IN to the control plane, and setting up the second factor that
        // signing in requires.
        //
        // Exempting the two lines above is not enough on its own, and the gap
        // was a real one: both of them sit behind auth:sanctum, so they answer
        // 401 without a staff token, and the request that OBTAINS that token was
        // being refused by the very minimum it exists to lower. A staff member
        // whose twelve-hour token had lapsed — or who was at a different machine
        // — had no way back in through EzBizi's own client. Worse, signing out
        // is deliberately exempt one screen up, so for staff under a mistyped
        // minimum, signing out was a one-way door.
        //
        // Exempting them grants nobody anything. Both are unauthenticated by
        // necessity (a staff account with no confirmed factor cannot sign in,
        // which is the whole reason enrolment exists), and both are throttled
        // and audited in their own right — see routes/api.php and
        // EnrolmentController. The tenant sign-in is deliberately NOT here: a
        // customer signing in on a stale build should be told their app is out
        // of date, which is the one moment they can still act on it.
        'api/*/staff/auth/login',
        'api/*/staff/auth/enrolment',
        'api/*/staff/auth/enrolment/confirm',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $refusal = $this->refusalFor($request);

        if ($refusal === null) {
            return $next($request);
        }

        return response()->json($refusal, Response::HTTP_UPGRADE_REQUIRED);
    }

    /**
     * The decision, or null to let the request through.
     *
     * Separated from handle() so that the try/catch below wraps the JUDGEMENT
     * and nothing else. Wrapping $next($request) in it would swallow every
     * exception the rest of the application throws and answer 200 to a broken
     * request — a fail-open gate must not become a fail-open application.
     *
     * @return array<string, mixed>|null
     */
    private function refusalFor(Request $request): ?array
    {
        try {
            if ($this->isAlwaysPermitted($request)) {
                return null;
            }

            $platform = $request->header(self::PLATFORM_HEADER);

            if (! is_string($platform)) {
                return null;
            }

            $platform = strtolower(trim($platform));

            // An unrecognised platform is not judged. A client on a target we
            // have not shipped yet, or a proxy that mangled the header, is
            // something we cannot have set a minimum for — and guessing which
            // minimum it "probably" meant is how you refuse somebody for a
            // reason that is not written down anywhere.
            if (! in_array($platform, PlatformMinimumClientVersion::PLATFORMS, strict: true)) {
                return null;
            }

            /*
             * One indexed lookup, and only once a recognised platform header has
             * arrived — so this costs nothing at all until clients start sending
             * the header, and costs one primary-key-ish read afterwards. Not
             * cached: the row is read on the request that a raise is supposed to
             * take effect on, and a cache would mean staff raising a minimum and
             * then waiting an unspecified number of minutes to find out whether
             * it did anything.
             */
            $minimum = PlatformMinimumClientVersion::query()
                ->where('platform', $platform)
                ->first();

            if ($minimum === null) {
                return null;
            }

            $version = $request->header(self::VERSION_HEADER);
            $version = is_string($version) ? $version : null;

            return $minimum->permits($version) ? null : $minimum->refusal($version);
        } catch (Throwable $e) {
            /*
             * Anything at all went wrong deciding — the database is unreachable,
             * the row is malformed, a future edit throws — and the request is
             * ALLOWED.
             *
             * Reported rather than swallowed, so it reaches the log and the audit
             * trail through the handler in bootstrap/app.php. A gate that fails
             * open silently is indistinguishable from a gate that is working,
             * and somebody would eventually conclude the feature was fine while
             * it had been inert for a month.
             */
            report($e);

            return null;
        }
    }

    private function isAlwaysPermitted(Request $request): bool
    {
        foreach (self::ALWAYS_PERMITTED as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
