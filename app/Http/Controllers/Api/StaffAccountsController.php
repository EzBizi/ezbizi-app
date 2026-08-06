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

        // Locking yourself out of the control plane leaves the console on the box
        // as the only way back in. Refusing is cheaper than that evening.
        if ($staff->is($request->user())) {
            if (array_key_exists('is_active', $input) && $input['is_active'] === false) {
                return $this->refuse('You cannot deactivate your own account.');
            }

            if (($input['tier'] ?? $staff->tier) !== $staff->tier) {
                return $this->refuse('You cannot change your own tier.');
            }
        }

        // Demoting or disabling the last remaining Lead Developer leaves nobody
        // able to manage staff — recoverable only from a shell on the box.
        $staysLead   = ($input['tier'] ?? $staff->tier) === PlatformStaff::TIER_LEAD;
        $staysActive = $input['is_active'] ?? $staff->is_active;

        if ($staff->isLeadDeveloper() && $staff->is_active
            && ! ($staysLead && $staysActive)
            && $this->activeLeadCount() <= 1) {
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

        $enrolment = $this->auth->beginEnrolment($staff, revokeExisting: false);

        $this->record($request, 'staff.totp_rotation_started', $staff);

        return response()->json(['enrolment' => $enrolment]);
    }

    /** POST /api/v1/staff/me/totp/confirm */
    public function confirmOwnTotp(Request $request): JsonResponse
    {
        return $this->confirm($request, $request->user());
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

    private function activeLeadCount(): int
    {
        return PlatformStaff::where('tier', PlatformStaff::TIER_LEAD)
            ->where('is_active', true)
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
            'created_at'        => $staff->created_at->toIso8601String(),
        ];
    }
}
