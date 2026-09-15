<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\AuthenticateUser;
use App\Services\Auth\EmailVerifications;
use App\Services\Auth\IssueSession;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Auth\PasswordPolicy;
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
 * login: its success body has the same keys as `login`'s, and both are built by
 * IssueSession — one object, so a second copy of the session contract is not a
 * second thing to keep in step with the client.
 *
 * That used to be a PRIVATE method here, and the argument for keeping it private
 * was the same argument for keeping signup in this file. Accepting an invitation
 * is a third entry point that also ends in a session and cannot live here — it
 * is public, tenant-less and belongs to the HR module — so the method moved out
 * whole rather than being made public or copied.
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
        // The session body, shared with the other two entry points that end in
        // one — signup below, and accepting an invitation. See IssueSession for
        // why the private method this replaced could not stay private.
        private readonly IssueSession $session,
        // TODO #22. Signup sends the first confirmation; the resend endpoint
        // sends the rest. Both go through this one object.
        private readonly EmailVerifications $verifications,
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
        if (! config('ezbizi.signup.enabled')) {
            return response()->json([
                'message' => 'Self-serve signup is closed. Ask whoever invited you for a link.',
                'code'    => 'signup_closed',
            ], Response::HTTP_FORBIDDEN);
        }

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

                // The ONE caller that passes false (TODO #22). Somebody just
                // typed an address nobody has checked, which is the entire
                // difference between this route and every other way an owner
                // comes into being. RequireVerifiedEmail refuses writes until
                // the confirmation below is used.
                administratorVerified: false,
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

        /*
         * The confirmation message (TODO #22).
         *
         * AFTER the transaction, never inside it: a mail failure must not roll
         * back an organization that is perfectly good, and the person can ask
         * for another link. The account exists either way - it simply cannot
         * write anything until this address is confirmed, which is what
         * RequireVerifiedEmail enforces.
         *
         * The return value is deliberately not surfaced in the response body.
         * IssueSession builds that body and login and invitation-acceptance
         * share it, so a key added here would change three responses to say
         * something only one of them knows. "Check your email" plus a resend
         * endpoint is the honest shape.
         */
        $this->verifications->issueAndSend($tree['administrator'], $request->ip());

        // 201, and otherwise the login body key for key — the client is going to
        // treat this response as one. Not byte for byte: the token differs, and
        // so does the status.
        //
        // Inside the tenant as well, because IssueSession audits the sign-in.
        return $this->tenant->run(
            $tree['organization'],
            fn () => ($this->session)($request, $tree['administrator'], Response::HTTP_CREATED),
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
            'remember' => ['sometimes', 'boolean'],
        ]);

        // Throttled per email+IP. Without this, the same-email design gives an
        // attacker a way to test one password against several organizations at
        // once, which is strictly better value than a single-tenant login.
        $throttleKey = $this->loginThrottleKey($request, $credentials['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            return $this->throttled($throttleKey);
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
            // A missing code is the login form's first step and must not spend
            // the budget — the client posts the password, learns it needs a
            // code, then posts both. A WRONG code is a second-factor guess and
            // is charged. Leaving the counter untouched (the previous code)
            // meant a stolen password had unlimited TOTP attempts, because a
            // correct password never `hit()` in the first place.
            if ($result->message !== null) {
                RateLimiter::hit($throttleKey, decaySeconds: 300);
            }

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

        return ($this->session)($request, $result->user);
    }

    /** POST /api/v1/auth/choose — complete an ambiguous login. */
    public function choose(Request $request): JsonResponse
    {
        $input = $request->validate([
            'user_id'  => ['required', 'uuid'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'code'     => ['nullable', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        // Same key as login. A stolen password that could not grind TOTP on
        // /login must not grind it here instead.
        $throttleKey = $this->loginThrottleKey($request, $input['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            return $this->throttled($throttleKey);
        }

        // Re-verifies the password: the chosen id comes from the client, so
        // trusting it as proof of the earlier step would let anyone post a user
        // id and skip authentication.
        $result = $this->authenticate->completeChoice(
            $input['user_id'], $input['email'], $input['password'], $input['code'] ?? null,
        );

        if (! $result->succeeded()) {
            RateLimiter::hit($throttleKey, decaySeconds: 300);

            return response()->json([
                'message' => 'Those credentials do not match our records.',
                'code'    => 'invalid_credentials',
            ], Response::HTTP_UNAUTHORIZED);
        }

        RateLimiter::clear($throttleKey);

        return ($this->session)($request, $result->user);
    }

    /** POST /api/v1/auth/logout — revoke the token that made this request. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        $this->audit->log('auth.signed_out', actor: $request->user());

        return response()->json(['message' => 'Signed out.']);
    }

    private function loginThrottleKey(Request $request, string $email): string
    {
        return strtolower($email).'|'.$request->ip();
    }

    private function throttled(string $key): JsonResponse
    {
        return response()->json([
            'message'     => 'Too many attempts. Try again shortly.',
            'code'        => 'throttled',
            'retry_after' => RateLimiter::availableIn($key),
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
