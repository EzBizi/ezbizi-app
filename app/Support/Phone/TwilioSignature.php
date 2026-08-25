<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use Twilio\Security\RequestValidator;

/**
 * Validates X-Twilio-Signature using the connection’s Account Auth Token.
 *
 * Never the API key secret in `credentials`, and never the platform Twilio
 * token — either would break verification or cross a tenant boundary.
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
}
