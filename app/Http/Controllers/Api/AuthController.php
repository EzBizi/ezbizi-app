<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\Auth\AuthenticateUser;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\SessionContext;
use App\Support\Auth\SessionLifetime;
use App\Support\Auth\TwoFactorPolicy;
use App\Services\Auth\AuthenticationResult;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign up, sign in and sign out (plan §4).
 *
 * Sits OUTSIDE auth:sanctum and outside the tenant middleware — there is no
 * user and no organization until one of these succeeds.
 *
 * Signup lives here rather than in a controller of its own because it ENDS in a
 * login: its success body has the same keys as `login`'s, produced by the same
 * private method. Splitting it out would mean either making that method public
 * or copying it, and a second copy of the session contract is a second thing to
 * keep in step with the client.
 *
 * Same keys, not the same bytes — the token differs, the staff token may, and
 * signup answers 201 where login answers 200.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticateUser $authenticate,
        private readonly AuditLogger $audit,
        private readonly ProvisionOrganization $provision,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * POST /api/v1/auth/signup — the front door (§3, §13; TODO #10).
     *
     * ── One form, one request, one transaction ───────────────────
     *
     * §3 forbids a company without a franchise, so this is deliberately not a
     * resumable wizard: somebody abandoning a multi-step flow halfway through
     * would leave behind precisely the state the rule prohibits.
     * ProvisionOrganization already builds the whole minimum viable tree or
     * nothing at all, and this is its first non-test caller.
     *
     * ── Why `business_name` becomes BOTH the organization and the company ──
     *
     * A business signing up is one company. Asking a plumber to distinguish
     * "your organization" from "your first company" at the front door is asking
     * them to understand §3's hierarchy before they have seen the product. They
     * can add companies and sub-companies afterwards — the Organization screen
     * already does that — and renaming either one is a text field. This is a
     * decision about the form, not an oversight in the mapping.
     *
     * ── What is deliberately absent ──────────────────────────────
     *
     * EMAIL VERIFICATION. Nothing on this platform can send mail: AWS blocks
     * outbound port 25 on both instances and SES has no credential yet (TODO
     * #1). Gating signup on a verification link would therefore lock out every
     * account it created. It is recorded as a follow-up in EZBIZI-TODO.md tied
     * to #1 rather than left to look like something nobody thought of.
     *
     * A TIMEZONE. Left at the service default on purpose. TODO #20 — whether a
     * scheduled job is an instant or a local wall time — is deliberately
     * parked, and asking for a timezone here would pick an answer to it in
     * passing.
     *
     * `is_demo`. Stays false. TODO #9 asks what a demo organization actually
     * suppresses and is unanswered, so signup must not start minting them.
     */
    public function signup(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],

            // No `unique` rule, deliberately. The same address may hold accounts
            // in several organizations (§4) — a person working for two clients —
            // and tests/Feature/Api/AuthApiTest.php asserts login copes with it
            // via choose_organization. Refusing a known address here would break
            // that, and would also make this endpoint an oracle: type an address,
            // read the status code, learn whether it is registered.
            'password' => PasswordPolicy::rules(),

            // Both become real entities, so neither may be blank. TrimStrings and
            // ConvertEmptyStringsToNull run globally, so "   " arrives as null and
            // `required` refuses it.
            //
            // 120, matching CompaniesController and FranchisesController.
            //
            // The columns are varchar(255) and an earlier version used that,
            // which let the front door create a franchise the Structure screen
            // then refused to save: signup accepted 200 characters, renaming it
            // to the same value came back 422. The binding constraint in this
            // app is 120, not the column width.
            //
            // It also closes a reachable 500. `uniqueSlug` appends `-2` on a
            // collision, and `organizations.slug` is varchar(255) — so two
            // signups with the same 255-character business name overflowed the
            // column on the second. At 120 the suffix has nowhere near enough
            // room to matter.
            'business_name'  => ['required', 'string', 'max:120'],
            'first_location' => ['required', 'string', 'max:120'],

            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * The organization and the record of its creation commit together.
         *
         * ProvisionOrganization opens its own transaction; nesting makes that a
         * savepoint, so the audit row cannot survive a rolled-back tenant and a
         * failed audit cannot leave one unrecorded. Same shape as every other
         * write in this app — see CompaniesController::store.
         */
        $tree = DB::transaction(function () use ($input, $request) {
            $tree = ($this->provision)(
                organizationName: $input['business_name'],
                administrator: [
                    'name'     => $input['name'],
                    'email'    => $input['email'],
                    'password' => $input['password'],
                ],
                companyName:   $input['business_name'],
                franchiseName: $input['first_location'],
            );

            /*
             * The tenant, before anything is audited.
             *
             * ProvisionOrganization restores whatever tenancy it found when it
             * returns — which here is none — and AuditLogger reads the context to
             * decide which organization an event belongs to. Without this line the
             * row describing a tenant's creation lands with organization_id NULL,
             * so it is missing from that customer's own history from the first
             * second of its life.
             *
             * `run()` and not `set()`, because TenantContext is a singleton and
             * nothing sweeps this request: signup is in the PUBLIC group, so
             * SetTenantContext — whose whole job is the try/finally that clears
             * it — never runs. A bare set() left the tenant in place for
             * whatever the process handled next. Harmless under php-fpm, where
             * the process ends; not harmless in the test suite, where it made
             * the second and third signups in one test run with the first one's
             * tenant still in context, so a test that reads as exercising the
             * no-tenant path was not.
             */
            $this->tenant->run($tree['organization'], function () use ($tree, $request) {
                $this->audit->log('organization.provisioned', $tree['organization'], metadata: [
                    'company'   => $tree['company']->name,
                    'franchise' => $tree['franchise']->name,
                    'ip'        => $request->ip(),
                    'via'       => 'self-serve signup',
                ], actor: $tree['administrator']);
            });

            return $tree;
        });

        // 201, and otherwise the login body key for key — the client is going to
        // treat this response as one. Not byte for byte: the token differs, and
        // so does the status.
        //
        // Inside the tenant as well, because issueToken audits the sign-in.
        return $this->tenant->run(
            $tree['organization'],
            fn () => $this->issueToken($request, $tree['administrator'], Response::HTTP_CREATED),
        );
    }

    /**
     * POST /api/v1/auth/login
     *
     * Three outcomes, because one email may exist in several organizations
     * (§4). See AuthenticateUser for why the password is verified BEFORE any
     * organization is named.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            // Only accounts linked to a staff record need this (§4.2, revised),
            // so it is optional here and demanded by the service if it applies.
            'code'     => ['nullable', 'string'],
        ]);

        // Throttled per email+IP. Without this, the same-email design gives an
        // attacker a way to test one password against several organizations at
        // once, which is strictly better value than a single-tenant login.
        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            return response()->json([
                'message'     => 'Too many attempts. Try again shortly.',
                'code'        => 'throttled',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $result = ($this->authenticate)(
            $credentials['email'],
            $credentials['password'],
            $credentials['code'] ?? null,
        );

        if ($result->status === AuthenticationResult::FAILED) {
            RateLimiter::hit($throttleKey, decaySeconds: 300);

            // Deliberately identical whether the address exists or not — the
            // service already equalises timing; this equalises the wording.
            return response()->json([
                'message' => 'Those credentials do not match our records.',
                'code'    => 'invalid_credentials',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($result->needsSecondFactor()) {
            // Not a failure and not a success. The password was right; this
            // account carries platform reach, so it needs the authenticator too.
            // The throttle is NOT cleared — an attacker with a stolen password
            // must not get unlimited attempts at the second factor.
            return response()->json([
                'status'  => 'second_factor_required',

                // The reason when there is one — a code that did not verify —
                // and otherwise nothing but the ask. The old text named the
                // control plane on the one screen every customer sees; whether
                // a second system exists is nobody else's business.
                'message' => $result->message
                    ?? 'Enter the code from your authenticator.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($result->isAmbiguous()) {
            // Reachable only AFTER the password verified, so it discloses
            // nothing to someone who merely guessed an address.
            return response()->json([
                'status'  => 'choose_organization',
                'message' => 'This address has accounts in more than one organization.',
                'choices' => $result->choices(),
            ]);
        }

        return $this->issueToken($request, $result->user);
    }

    /** POST /api/v1/auth/choose — complete an ambiguous login. */
    public function choose(Request $request): JsonResponse
    {
        $input = $request->validate([
            'user_id'  => ['required', 'uuid'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'code'     => ['nullable', 'string'],
        ]);

        // Re-verifies the password: the chosen id comes from the client, so
        // trusting it as proof of the earlier step would let anyone post a user
        // id and skip authentication.
        $result = $this->authenticate->completeChoice(
            $input['user_id'], $input['email'], $input['password'], $input['code'] ?? null,
        );

        if (! $result->succeeded()) {
            return response()->json([
                'message' => 'Those credentials do not match our records.',
                'code'    => 'invalid_credentials',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $this->issueToken($request, $result->user);
    }

    /** POST /api/v1/auth/logout — revoke the token that made this request. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        $this->audit->log('auth.signed_out', actor: $request->user());

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * The session body every successful entry point returns.
     *
     * Takes a User rather than an AuthenticationResult: signup has no
     * authentication attempt behind it — it just created the account — and the
     * only thing this ever read off the result was `->user`.
     */
    private function issueToken(
        Request $request,
        User $user,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        // Named after the device so a user can revoke one client without
        // signing out everywhere.
        //
        // `?:` and not `input(..., 'web')`. A default applies only to a MISSING
        // key, and ConvertEmptyStringsToNull turns `"device_name": ""` into
        // null — which reached createToken() and raised a TypeError. On login
        // that was a 500 where a 401 belonged; on signup it was a 500 raised
        // AFTER the tenant transaction had committed, so the caller was told
        // the server broke while a real, reachable organization existed, and
        // retrying built a second one. Resolved here rather than in each
        // caller's validation, because this is the line that cannot accept it.
        $deviceName = trim((string) $request->input('device_name')) ?: 'web';

        /*
         * The idle window, not a flat month (§6 — `security.session_timeout_minutes`).
         *
         * It was `now()->addDays(30)`, which was the only lifetime this app had:
         * the setting labelled "Sign out after inactivity" was read by nothing,
         * so an unattended browser stayed signed in for thirty days.
         *
         * SlideIdleWindow pushes this forward on every request the token makes,
         * so the thirty days survives as the absolute ceiling and the setting
         * decides how long a session may sit untouched inside it.
         */
        $token = $user->createToken(
            $deviceName,
            expiresAt: app(SessionLifetime::class)->expiryFor($user->organization, now()),
        );

        // The user agent and address, so the session list can name this row
        // later. Every device sends `device_name: web`, so without this the
        // list is five identical entries and revoking one specific machine is
        // impossible.
        SessionContext::stamp($token->accessToken, $request);

        $this->audit->log('auth.signed_in', actor: $user, metadata: [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        /*
         * A linked account gets BOTH tokens from one sign-in.
         *
         * Both factors have just been proven against the staff credential set,
         * so making them authenticate again to open the Development tab would be
         * ceremony rather than security. The tokens stay separate — the tenant
         * one still cannot touch /api/v1/staff and the staff one still cannot
         * touch tenant endpoints — because that separation is what keeps a
         * stolen CRM session from being a platform session.
         *
         * The staff token is deliberately shorter-lived (12 hours, as at
         * dev.ezbizi.com) even though the tenant token lasts 30 days.
         */
        $staff = $this->authenticate->staffFor($user);

        $staffToken = null;

        if ($staff !== null) {
            $staffToken = $staff->createToken(
                "staff:{$deviceName} (via CRM)",
                ['staff'],
                expiresAt: now()->addHours(12),
            )->plainTextToken;

            $staff->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

            $this->audit->logStaff(
                action: 'staff.signed_in',
                staffId: $staff->getKey(),
                staffLabel: "{$staff->name} <{$staff->email}>",
                metadata: [
                    'ip' => $request->ip(),
                    'via' => 'linked tenant login',
                    'organization' => $user->organization->name,
                ],
            );
        }

        return response()->json([
            'status' => 'authenticated',
            'token'  => $token->plainTextToken,
            // Present only for linked accounts. The client shows the Development
            // section when it is there and hides it when it is not, so the tab
            // cannot appear for somebody who has no staff account behind them.
            'staff_token' => $staffToken,
            'staff' => $staff === null ? null : [
                'id' => $staff->id, 'name' => $staff->name, 'tier' => $staff->tier,
            ],
            'user'   => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            // The organization requires a second factor and this account has
            // none (§6). Sent at sign-in, so the client can take somebody
            // straight to enrolment rather than letting them find out by way of
            // a dashboard that answers 403. See RequireTwoFactorEnrolment for
            // why this is a detour and not a refusal.
            'two_factor_enrolment_required' => app(TwoFactorPolicy::class)->mustEnrol($user),
            'organization' => [
                'id'           => $user->organization->id,
                'name'         => $user->organization->name,
                // Surfaced at login so the client can explain the state up
                // front rather than after the first failed write (§13).
                'is_read_only' => $user->organization->isReadOnly(),
            ],
        ], $status);
    }
}
