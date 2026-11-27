<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use Twilio\Security\RequestValidator;

/**
 * Validates X-Twilio-Signature using the connection’s Account Auth Token.
 *
 * Never the API key secret in `credentials`, and never the platform Twilio
 * token — either would break verification or cross a tenant boundary. The one
 * exception is heldOnPlatform(), for a number the platform account holds
 * for this connection.
 */
final class TwilioSignature
{
    /**
     * Form webhooks pass param arrays. JSON webhooks (Port In) pass the raw
     * body string; the URL must carry Twilio’s `bodySHA256` query param.
     *
     * @param  array<string, mixed>|string  $params
     */
    public static function valid(
        PhoneConnection $connection,
        string $url,
        array|string $params,
        ?string $header,
    ): bool {
        if ($header === null || $header === '') {
            return false;
        }

        // The previous token too, while numbers move to a new Twilio account
        // (2026-11-19): a number not yet moved still signs with the old one.
        foreach ([$connection->auth_token, $connection->previous_auth_token] as $token) {
            if (is_string($token) && $token !== '' && (new RequestValidator($token))->validate($header, $url, $params)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A message to a number the platform parent holds for this connection
     * (PhoneNumber::account_sid) is signed with the PARENT's token. Accepted
     * only when Twilio says the parent account sent it and `To` is one of this
     * connection's numbers held there, so the parent's signature never opens
     * another tenant's URL.
     *
     * @param  array<string, mixed>|string  $params
     */
    public static function heldOnPlatform(
        PhoneConnection $connection,
        string $url,
        array|string $params,
        ?string $header,
    ): bool {
        if ($header === null || $header === '' || ! is_array($params)) {
            return false;
        }

        // The current platform parent, and the previous one while numbers move
        // to a new Twilio account (2026-11-19). The one Twilio says sent it.
        $pairs = [
            [config('services.twilio.platform_account_sid'), config('services.twilio.platform_auth_token')],
            [config('services.twilio.platform_previous_account_sid'), config('services.twilio.platform_previous_auth_token')],
        ];
        foreach ($pairs as [$platform, $token]) {
            if (! is_string($platform) || $platform === '' || ! is_string($token) || $token === ''
                || ($params['AccountSid'] ?? null) !== $platform) {
                continue;
            }

            $held = PhoneNumber::query()
                ->where('connection_id', $connection->id)
                ->where('account_sid', $platform)
                ->where('e164', (string) ($params['To'] ?? ''))
                ->exists();

            return $held && (new RequestValidator($token))->validate($header, $url, $params);
        }

        return false;
    }
}
