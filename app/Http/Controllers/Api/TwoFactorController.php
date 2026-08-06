<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformStaffLink;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\TotpEnrolment;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tenant user's own second factor (plan §6 — `security.require_two_factor`).
 *
 * The setting has been in the registry since the settings work and nothing
 * implemented it. This is the other half, and it is self-service by design:
 * anybody signed in can switch it on for themselves, without an administrator
 * and without anyone from EzBizi being available.
 *
 * No policy on these routes, deliberately. Every one is bound to
 * $request->user() — there is nothing to authorize beyond being signed in, and a
 * permission would imply one user might manage another's, which is never true.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TotpEnrolment $totp,
        private readonly SettingsResolver $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/me/totp — what state am I in? */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'enabled'              => $user->hasTotp(),
            'pending'              => $user->hasPendingTotp(),
            'recovery_code_count'  => $user->recoveryCodeCount(),
            'required_by_organization' => $this->required($user),
            // A linked account's factor lives on the staff record, not here.
            // Saying so stops somebody enrolling a second one and wondering why
            // the first still gets asked for.
            'managed_elsewhere'    => PlatformStaffLink::staffFor($user->getKey()) !== null,
        ]]);
    }

    /**
     * POST /api/v1/me/totp
     *
     * Issue a secret. It stays PENDING — the account still signs in exactly as
     * before until a code proves the authenticator works, because a mis-scan
     * that took effect immediately would lock somebody out of their own CRM.
     */
    public function begin(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($this->managedElsewhere($user)) {
            return $this->refuse(
                'Your sign-in is managed by your EzBizi staff account. Change your '
                .'authenticator there — it covers both.'
            );
        }

        if ($user->hasTotp()) {
            return $this->refuse('Two-factor is already on. Turn it off first to re-enrol.');
        }

        $enrolment = $this->totp->begin($user, $user->email);

        return response()->json(['enrolment' => $enrolment]);
    }

    /** POST /api/v1/me/totp/confirm */
    public function confirm(Request $request): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();

        if (! $user->hasPendingTotp()) {
            return $this->refuse('There is no pending authenticator to confirm.');
        }

        $recovery = $this->totp->complete($user, $input['code']);

        if ($recovery === null) {
            return $this->refuse(
                'That code did not verify. Check the clock on the device, then try again.'
            );
        }

        $this->audit->log('user.two_factor_enabled', $user);

        return response()->json([
            // Once, and never again. Shown here because this is the only moment
            // they exist outside the database.
            'recovery_codes' => $recovery,
        ]);
    }

    /**
     * DELETE /api/v1/me/totp
     *
     * Requires the password, not just the session. A borrowed unlocked laptop
     * should not be able to strip somebody's second factor — which is the one
     * thing protecting them from that laptop.
     */
    public function disable(Request $request): JsonResponse
    {
        $input = $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if ($this->managedElsewhere($user)) {
            return $this->refuse('Your sign-in is managed by your EzBizi staff account.');
        }

        if (! Hash::check($input['password'], $user->password)) {
            return $this->refuse('That password is not right.');
        }

        if ($this->required($user)) {
            // The organization decided this, and one user must not be able to
            // opt out of a policy set above them (§6 — allowed scopes exist so a
            // franchise cannot weaken what the organization set).
            return $this->refuse(
                'Your organization requires two-factor authentication, so it cannot '
                .'be switched off here.'
            );
        }

        $this->totp->disable($user);

        $this->audit->log('user.two_factor_disabled', $user);

        return response()->json(['message' => 'Two-factor is off.']);
    }

    private function required($user): bool
    {
        return (bool) $this->settings->get('security.require_two_factor', $user->organization);
    }

    private function managedElsewhere($user): bool
    {
        return PlatformStaffLink::staffFor($user->getKey()) !== null;
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
