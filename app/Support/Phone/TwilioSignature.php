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

        $token = (string) $connection->auth_token;
        if ($token === '') {
            return false;
        }

        return (new RequestValidator($token))->validate($header, $url, $params);
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
        $platform = (string) config('services.twilio.platform_account_sid');
        $token = (string) config('services.twilio.platform_auth_token');

        if ($header === null || $header === '' || ! is_array($params) || $platform === '' || $token === '') {
            return false;
        }

        if (($params['AccountSid'] ?? null) !== $platform) {
            return false;
        }

        $held = PhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->where('account_sid', $platform)
            ->where('e164', (string) ($params['To'] ?? ''))
            ->exists();

        return $held && (new RequestValidator($token))->validate($header, $url, $params);
    }
}
