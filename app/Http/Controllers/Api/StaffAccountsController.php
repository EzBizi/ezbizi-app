<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformStaff;
use App\Services\Staff\StaffAuthenticator;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Managing EzBizi staff accounts (plan §4.2).
 *
 * Lead Developer only, apart from `/staff/me/totp*` which is how anyone moves
 * their own authenticator to a new phone. A Developer who could mint another
 * Developer could mint one for anybody, and the tier ceiling would mean nothing.
 *
 * ── How a new account gets its second factor ─────────────────────
 *
 * The account cannot sign in until TOTP is confirmed, so the new person cannot
 * confirm it themselves — there is no session for them to do it in. The Lead
 * Developer creating the account shows them the QR, they scan it, they read the
 * six digits back, and the Lead Developer confirms. Everything happens inside an
 * authenticated session, so no unauthenticated enrolment endpoint has to exist.
 */
class StaffAccountsController extends Controller
{
    public function __construct(
        private readonly StaffAuthenticator $auth,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/staff/accounts */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);

        return response()->json([
            'data' => PlatformStaff::orderBy('name')->get()->map(fn ($s) => $this->present($s)),
        ]);
    }

    /** POST /api/v1/staff/accounts */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);

        $input = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'unique:platform_staff,email'],
            // Longer than the tenant default. This account reaches every
            // customer on the platform; the ordinary policy is not enough.
            'password' => ['required', 'string', 'min:16'],
            'tier'     => ['required', Rule::in([PlatformStaff::TIER_LEAD, PlatformStaff::TIER_DEV])],
        ]);

        $staff = PlatformStaff::create([
            'name'      => $input['name'],
            'email'     => strtolower($input['email']),
            'password'  => $input['password'],
            'tier'      => $input['tier'],
            'is_active' => true,
        ]);

        $enrolment = $this->auth->beginEnrolment($staff);

        $this->record($request, 'staff.account_created', $staff, [
            'tier' => $staff->tier,
        ]);

        return response()->json([
            'data'      => $this->present($staff),
            // Shown once, so the new person can scan it while you are both here.
            'enrolment' => $enrolment,
        ], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/staff/accounts/{staff} */
    public function update(Request $request, PlatformStaff $staff): JsonResponse
    {
        $this->authorizeManagement($request);

        $input = $request->validate([
            'name'      => ['string', 'max:120'],
            'tier'      => [Rule::in([PlatformStaff::TIER_LEAD, PlatformStaff::TIER_DEV])],
            'is_active' => ['boolean'],
        ]);

        // Normalised ONCE, up front. Laravel's `boolean` rule accepts 0, 1, "0"
        // and "1" as well as real booleans, so a strict `=== false` further down
        // would quietly miss `{"is_active": 0}` — disabling the account while
        // leaving every live token valid for the next time it is re-enabled.
        if (array_key_exists('is_active', $input)) {
            $input['is_active'] = filter_var($input['is_active'], FILTER_VALIDATE_BOOL);
        }

        $staysLead   = ($input['tier'] ?? $staff->tier) === PlatformStaff::TIER_LEAD;
        $staysActive = $input['is_active'] ?? $staff->is_active;

        // Locking yourself out of the control plane leaves the console on the box
        // as the only way back in. Refusing is cheaper than that evening.
        if ($staff->is($request->user())) {
            if ($staysActive === false) {
                return $this->refuse('You cannot deactivate your own account.');
            }

            if (($input['tier'] ?? $staff->tier) !== $staff->tier) {
                return $this->refuse('You cannot change your own tier.');
            }
        }

        // Demoting or disabling the last remaining Lead Developer leaves nobody
        // able to manage staff — recoverable only from a shell on the box.
        if ($staff->isLeadDeveloper() && $staff->is_active
            && ! ($staysLead && $staysActive)
            && $this->usableLeadCount() <= 1) {
            return $this->refuse(
                'This is the last active Lead Developer. Promote someone else first.'
            );
        }

        $before = $staff->only(['name', 'tier', 'is_active']);
        $staff->fill($input)->save();

        // Deactivation must end the sessions too, or "disabled" describes a flag
        // and not the access.
        if ($staysActive === false) {
            $staff->tokens()->delete();
        }

        $this->record($request, 'staff.account_updated', $staff, [
            'before' => $before,
            'after'  => $staff->only(['name', 'tier', 'is_active']),
        ]);

        return response()->json(['data' => $this->present($staff->fresh())]);
    }

    /**
     * POST /api/v1/staff/accounts/{staff}/totp
     *
     * Re-enrol someone else — the lost-phone path. Their current secret dies
     * immediately, which is the point: it is presumed compromised or gone.
     */
    public function resetTotp(Request $request, PlatformStaff $staff): JsonResponse
    {
        $this->authorizeManagement($request);

        // Never on yourself. This path kills the live secret AND every token,
        // including the one making the request — so confirming the replacement
        // would need a session that no longer exists, and signing in again is
        // impossible without a confirmed factor. The account would be strandable
        // in one click, recoverable only from a shell on the box.
        if ($staff->is($request->user())) {
            return $this->refuse(
                'Use "Move authenticator" to change your own — that path keeps the '
                .'current one working until the replacement is confirmed.'
            );
        }

        // No last-lead check here, and that is not an oversight: the caller is
        // necessarily a Lead Developer who is signed in, so they are themselves a
        // usable one, and the line above guarantees they are not the target.
        // Revoking somebody else therefore always leaves at least one.
        $enrolment = $this->auth->beginEnrolment($staff, revokeExisting: true);
        $staff->tokens()->delete();

        $this->record($request, 'staff.totp_reset', $staff);

        return response()->json(['data' => $this->present($staff->fresh()), 'enrolment' => $enrolment]);
    }

    /**
     * POST /api/v1/staff/accounts/{staff}/totp/confirm
     *
     * Confirm someone else's pending secret from the six digits they read out.
     */
    public function confirmTotpFor(Request $request, PlatformStaff $staff): JsonResponse
    {
        $this->authorizeManagement($request);

        return $this->confirm($request, $staff);
    }

    // ── your own authenticator ───────────────────────────────────

    /**
     * POST /api/v1/staff/me/totp
     *
     * Moving to a new phone. The working secret KEEPS working until the new one
     * is confirmed — otherwise a mis-scan locks you out of your own account with
     * the console on the box as the only way back.
     */
    public function beginOwnTotp(Request $request): JsonResponse
    {
        $staff = $request->user();

        $input = $request->validate([
            'password' => ['required', 'string'],
            'code'     => ['required', 'string'],
        ]);

        /*
         * Both factors again, not just the bearer token.
         *
         * A staff token is valid for twelve hours. Without this, anyone holding a
         * stolen one could bind their own phone as a second factor and confirm it
         * with their own code — turning a session that expires into access that
         * does not. Re-proving both factors is what stops a token upgrading
         * itself into a credential.
         */
        if ($this->auth->verifyPassword($staff->email, $input['password']) === null
            || ! $this->auth->verifySecondFactor($staff, $input['code'])) {
            return $this->refuse('That password or code did not verify.');
        }

        $enrolment = $this->auth->beginEnrolment($staff, revokeExisting: false);

        $this->record($request, 'staff.totp_rotation_started', $staff);

        return response()->json(['enrolment' => $enrolment]);
    }

    /** POST /api/v1/staff/me/totp/confirm */
    public function confirmOwnTotp(Request $request): JsonResponse
    {
        return $this->confirm($request, $request->user());
    }

    /**
     * POST /api/v1/staff/accounts/{staff}/link
     *
     * Tie a staff account to a tenant user, so one human signs in once (§4.2,
     * revised). From then on that user authenticates against the STAFF password
     * and the staff authenticator — a stronger credential than the one they had.
     *
     * Lead Developer only, and audited: linking hands a tenant login the
     * platform's credentials, which should never happen as a side effect of
     * anything else.
     */
    public function link(Request $request, PlatformStaff $staff): JsonResponse
    {
        $this->authorizeManagement($request);

        $input = $request->validate(['user_id' => ['required', 'uuid']]);

        // Outside tenancy on purpose: staff span organizations, so the user being
        // linked is by definition in one this request has no context for.
        $user = app(\App\Support\Tenancy\TenantContext::class)->runWithoutTenant(
            fn () => \App\Models\User::query()->with('organization')->find($input['user_id'])
        );

        if ($user === null) {
            return $this->refuse('No such user.');
        }

        $existing = \App\Models\PlatformStaffLink::query()->where('user_id', $user->getKey())->first();

        if ($existing !== null && $existing->staff_id !== $staff->getKey()) {
            // One staff account per user. Silently repointing it would change who
            // a login answers to without anybody deciding that.
            return $this->refuse('That user is already linked to a different staff account.');
        }

        \App\Models\PlatformStaffLink::firstOrCreate(
            ['user_id' => $user->getKey()],
            ['staff_id' => $staff->getKey(), 'linked_by_staff_id' => $request->user()->getKey()],
        );

        $this->record($request, 'staff.account_linked', $staff, [
            'user_id'      => $user->getKey(),
            'user_email'   => $user->email,
            'organization' => $user->organization?->name,
        ]);

        return response()->json(['data' => $this->present($staff->fresh())]);
    }

    /** DELETE /api/v1/staff/accounts/{staff}/link/{user} */
    public function unlink(Request $request, PlatformStaff $staff, string $user): JsonResponse
    {
        $this->authorizeManagement($request);

        \App\Models\PlatformStaffLink::query()
            ->where('staff_id', $staff->getKey())
            ->where('user_id', $user)
            ->delete();

        // The tenant account keeps working — it falls back to its own password,
        // which is still in the users table and was never overwritten. Unlinking
        // must not lock somebody out of their own CRM.
        $this->record($request, 'staff.account_unlinked', $staff, ['user_id' => $user]);

        return response()->json(['data' => $this->present($staff->fresh())]);
    }

    /**
     * POST /api/v1/staff/accounts/{staff}/reset-link
     *
     * A reset link, returned rather than emailed.
     *
     * MAIL_MAILER is `log` on this box — SES is still an open item from §18
     * Phase 0 — so a "forgot password" email reaches nobody today. Until a
     * transport exists this is the working path: a Lead Developer generates the
     * link and hands it over by whatever channel they already trust.
     *
     * It is the same single-use, one-hour token the email would carry, and it is
     * audited the same way. When mail is wired this stays useful for the case
     * where somebody has lost access to their mailbox entirely.
     */
    public function resetLink(Request $request, PlatformStaff $staff): JsonResponse
    {
        $this->authorizeManagement($request);

        $issued = app(\App\Services\Auth\PasswordResets::class)->issue($staff, $request->ip());

        $this->record($request, 'staff.password_reset_link_issued', $staff, [
            'expires_at' => $issued['expires_at']->toIso8601String(),
        ]);

        return response()->json(['data' => [
            'url'        => 'https://dev.ezbizi.com/reset?token='.$issued['token'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'note'       => 'Single use, one hour. Email delivery is not wired up yet '
                .'(MAIL_MAILER=log), so hand this over directly.',
        ]]);
    }

    // ── shared ───────────────────────────────────────────────────

    private function confirm(Request $request, PlatformStaff $staff): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string']]);

        if (! $staff->hasPendingTotp()) {
            return $this->refuse('There is no pending authenticator to confirm.');
        }

        $recovery = $this->auth->completeEnrolment($staff, $input['code']);

        if ($recovery === null) {
            return $this->refuse(
                'That code did not verify. Check the clock on the device, then try again.'
            );
        }

        $this->record($request, 'staff.totp_confirmed', $staff);

        return response()->json([
            'data' => $this->present($staff->fresh()),
            // Once. Never retrievable again — a code the server can redisplay is
            // a code an attacker can read out of the database.
            'recovery_codes' => $recovery,
        ]);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless(
            $request->user()->mayManageStaff(),
            Response::HTTP_FORBIDDEN,
            'Only a Lead Developer may manage staff accounts.'
        );
    }

    /**
     * Lead Developers who can actually sign in.
     *
     * Not just active ones: an account whose TOTP is unconfirmed cannot
     * authenticate at all, so counting it would let the "last lead" guard be
     * satisfied by somebody nobody can be.
     */
    private function usableLeadCount(): int
    {
        return PlatformStaff::where('tier', PlatformStaff::TIER_LEAD)
            ->where('is_active', true)
            ->whereNotNull('totp_secret')
            ->whereNotNull('totp_confirmed_at')
            ->count();
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Staff account changes are audited for EVERY tier.
     *
     * The Lead Developer's exemption covers development noise (§4.2). Creating an
     * account that can read every customer's data is not development noise, and
     * an unrecorded one is indistinguishable from an intruder's.
     */
    private function record(Request $request, string $action, PlatformStaff $target, array $metadata = []): void
    {
        $actor = $request->user();

        $this->audit->logStaff(
            action: $action,
            staffId: $actor->getKey(),
            staffLabel: "{$actor->name} <{$actor->email}>",
            target: $target,
            metadata: $metadata + ['target_email' => $target->email],
        );
    }

    private function present(PlatformStaff $staff): array
    {
        return [
            'id'                => $staff->id,
            'name'              => $staff->name,
            'email'             => $staff->email,
            'tier'              => $staff->tier,
            'is_active'         => $staff->is_active,
            'totp_confirmed'    => $staff->hasTotp(),
            'totp_pending'      => $staff->hasPendingTotp(),
            'recovery_code_count' => count($staff->recovery_codes ?? []),
            'last_login_at'     => $staff->last_login_at?->toIso8601String(),
            // Which tenant logins now answer to this account's credentials.
            'linked_users'      => \App\Models\PlatformStaffLink::query()
                ->where('staff_id', $staff->getKey())
                ->pluck('user_id'),
            'created_at'        => $staff->created_at->toIso8601String(),
        ];
    }
}
