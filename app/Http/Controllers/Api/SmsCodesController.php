<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit\AuditLogger;
use App\Support\Sms\PlatformSms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person asking EzBizi to text their verification codes.
 *
 * ── This endpoint IS the consent record ──────────────────────────
 *
 * Every carrier that carries a code asks the same two questions afterwards:
 * who agreed to receive this, and when. The answer has to be a row somebody
 * wrote deliberately, not an inference from a phone number sitting in a
 * profile — which is why [begin] refuses without an explicit consent and
 * stamps the moment it was given in the same statement as the number.
 *
 * `consent` is the word `accepted` rather than a boolean on purpose. A boolean
 * arriving false is indistinguishable from a field the client forgot to send,
 * and "forgot to send" must never be able to read as consent.
 *
 * ── Off when EzBizi has no number, rather than broken ────────────
 *
 * [show] answers `available: false` until the platform has a sender, and the
 * card draws nothing at all. Taking a mobile number and a consent for a code
 * that can never arrive is worse than offering nothing: it records an
 * agreement nobody can act on and leaves somebody waiting for a text.
 *
 * ── Why the code is hashed and short-lived ───────────────────────
 *
 * It is a password for the ten minutes it exists. Hashed for the same reason
 * the password column is, and expiring so a code read off an old handset
 * months later opens nothing.
 */
class SmsCodesController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    public function __construct(
        private readonly PlatformSms $sms,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/me/sms-codes */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'available' => $this->sms->configured(),
            'number' => $this->mask($user->sms_code_number),
            'consented' => $user->sms_code_consent_at !== null,
            'confirmed' => $user->sms_code_confirmed_at !== null,
            'pending' => $user->sms_code_number !== null
                && $user->sms_code_confirmed_at === null,
        ]]);
    }

    /**
     * POST /api/v1/me/sms-codes — the form submission itself.
     *
     * Takes the number and the tick together because they are one act. Storing
     * a number now and a consent later would leave a window in which EzBizi
     * holds a mobile number nobody has agreed to it holding.
     */
    public function begin(Request $request): JsonResponse
    {
        if (! $this->sms->configured()) {
            return $this->refuse(
                'EzBizi cannot send text messages yet. This becomes available once '
                .'the sending number is set up.'
            );
        }

        $input = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'consent' => ['required', 'in:accepted'],
        ]);

        $number = $this->e164($input['phone']);

        if ($number === null) {
            return $this->refuse(
                'That does not look like a mobile number. Enter it with the area '
                .'code, for example 305 555 0199.'
            );
        }

        $user = $request->user();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'sms_code_number' => $number,
            'sms_code_consent_at' => now(),
            'sms_code_confirmed_at' => null,
            'sms_code_hash' => Hash::make($code),
            'sms_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ])->save();

        // Worded to match the sample message filed with the carrier. A code
        // that arrives reading differently from the filing is the mismatch
        // that gets a sender's registration pulled.
        $sent = $this->sms->send(
            $number,
            $code.' is your EzBizi verification code. It expires in '
            .self::CODE_TTL_MINUTES.' minutes. Do not share this code with anyone.'
        );

        $this->audit->log('user.sms_codes_consent_given', $user);

        if (! $sent) {
            // The consent stands — they gave it, and it is theirs. What failed
            // is the send, and saying so keeps somebody from re-ticking a box
            // that was never the problem.
            return $this->refuse(
                'Your number is saved, but the code could not be sent. Check the '
                .'number and try again.'
            );
        }

        return response()->json(['data' => [
            'number' => $this->mask($number),
            'sent' => true,
        ]]);
    }

    /** POST /api/v1/me/sms-codes/confirm */
    public function confirm(Request $request): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if ($user->sms_code_hash === null) {
            return $this->refuse('There is no code waiting to be confirmed.');
        }

        if ($user->sms_code_expires_at === null || $user->sms_code_expires_at->isPast()) {
            return $this->refuse('That code has expired. Ask for a new one.');
        }

        if (! Hash::check(trim($input['code']), $user->sms_code_hash)) {
            return $this->refuse('That code did not match. Check the message and try again.');
        }

        $user->forceFill([
            'sms_code_confirmed_at' => now(),
            'sms_code_hash' => null,
            'sms_code_expires_at' => null,
        ])->save();

        $this->audit->log('user.sms_codes_confirmed', $user);

        return response()->json(['data' => [
            'confirmed' => true,
            'number' => $this->mask($user->sms_code_number),
        ]]);
    }

    /**
     * DELETE /api/v1/me/sms-codes — the opt-out, and it is unconditional.
     *
     * No password, no confirmation step, unlike turning the authenticator off.
     * Withdrawing consent to be texted has to be at least as easy as giving
     * it, and a control that asks somebody to prove themselves before they can
     * stop receiving messages is the thing STOP exists to make impossible.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'sms_code_number' => null,
            'sms_code_consent_at' => null,
            'sms_code_confirmed_at' => null,
            'sms_code_hash' => null,
            'sms_code_expires_at' => null,
        ])->save();

        $this->audit->log('user.sms_codes_consent_withdrawn', $user);

        return response()->json(['data' => ['available' => $this->sms->configured()]]);
    }

    /**
     * Last four digits only.
     *
     * Enough for somebody to recognise their own phone, not enough for a
     * borrowed screen to read a colleague's mobile number off it.
     */
    private function mask(?string $number): ?string
    {
        if ($number === null || strlen($number) < 4) {
            return $number;
        }

        return '••• ••• '.substr($number, -4);
    }

    /**
     * The same rule the dial pad uses: refuse rather than guess.
     *
     * A number missing its country code used to be dialled as typed, which is
     * how +3057242199 reached Twilio and came back as an unroutable
     * destination. Ten digits are taken as North American; anything else has
     * to arrive already carrying its country code.
     */
    private function e164(string $input): ?string
    {
        $text = trim($input);

        if (str_starts_with($text, '+')) {
            $digits = preg_replace('/\D/', '', substr($text, 1));

            return strlen($digits) >= 8 ? '+'.$digits : null;
        }

        $digits = preg_replace('/\D/', '', $text);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return strlen($digits) === 10 ? '+1'.$digits : null;
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
