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
        $user    = $request->user();
        $subject = $this->subject($request);

        return response()->json(['data' => [
            'enabled'              => $subject->hasTotp(),
            'pending'              => $subject->hasPendingTotp(),
            'recovery_code_count'  => $subject->recoveryCodeCount(),
            'required_by_organization' => $this->required($user),

            // A linked account's factor lives on the staff record. It used to
            // be reported as "managed elsewhere" and left with no control at
            // all, which sent somebody looking for a screen they may not be
            // able to reach. It is now changed from here, on the record that
            // actually holds it — the flag stays only so the UI can say the
            // change covers both sign-ins.
            'covers_control_plane' => $subject !== $user,
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
        $subject = $this->subject($request);

        // Re-enrolment no longer means "turn it off first". Making somebody
        // strip their only factor to move to a new phone leaves a window with
        // no protection at all, and the old secret is replaced the moment the
        // new one is confirmed rather than the moment it is issued.
        if ($subject->hasTotp() || $subject->hasPendingTotp()) {
            $refusal = $this->reauthenticate($request, $subject);

            if ($refusal !== null) {
                return $this->refuse($refusal);
            }
        }

        $enrolment = $this->totp->begin($subject, $subject->email, revokeExisting: false);

        return response()->json(['enrolment' => $enrolment]);
    }

    /** POST /api/v1/me/totp/confirm */
    public function confirm(Request $request): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string']]);

        $subject = $this->subject($request);
        $user    = $request->user();

        if (! $subject->hasPendingTotp()) {
            return $this->refuse('There is no pending authenticator to confirm.');
        }

        $recovery = $this->totp->complete($subject, $input['code']);

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
        $subject = $this->subject($request);
        $user    = $request->user();

        $refusal = $this->reauthenticate($request, $subject);

        if ($refusal !== null) {
            return $this->refuse($refusal);
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

        $this->totp->disable($subject);

        $this->audit->log('user.two_factor_disabled', $user);

        return response()->json(['message' => 'Two-factor is off.']);
    }

    private function required($user): bool
    {
        return (bool) $this->settings->get('security.require_two_factor', $user->organization);
    }

    /**
     * Whose second factor this actually is.
     *
     * A linked account signs in with the STAFF credentials and the staff
     * authenticator (§4.2), so the factor being changed lives there. Operating
     * on the user row instead would enrol a second authenticator that nothing
     * ever asks for, and leave the real one untouched — the change appears to
     * work and protects nothing.
     */
    private function subject(Request $request)
    {
        return PlatformStaffLink::staffFor($request->user()->getKey())
            ?? $request->user();
    }

    /**
     * Prove it is really them before touching the second factor.
     *
     * The password alone is not enough: somebody at a borrowed unlocked laptop
     * who also knows the password is exactly the case the second factor exists
     * for. So when a factor is already enrolled, a current code is required as
     * well — you must hold the thing you are about to remove.
     *
     * @return string|null  the refusal, or null if they proved it
     */
    private function reauthenticate(Request $request, $subject): ?string
    {
        $input = $request->validate([
            'password' => ['required', 'string'],
            'code'     => ['nullable', 'string'],
        ]);

        if (! Hash::check($input['password'], $subject->password)) {
            return 'That password is not right.';
        }

        if (! $subject->hasTotp()) {
            return null;
        }

        if (blank($input['code'] ?? null)) {
            return 'Enter a current code from your authenticator as well.';
        }

        // A recovery code counts. Somebody who has lost the device is precisely
        // who needs to change this, and refusing them would leave the only way
        // back through a support request.
        $verified = $this->totp->verify($subject, $input['code'])
            || $subject->consumeRecoveryCode($input['code']);

        return $verified ? null : 'That code did not verify.';
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
