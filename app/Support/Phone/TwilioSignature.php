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
     * @param  array<string, mixed>  $params
     */
    public static function valid(
        PhoneConnection $connection,
        string $url,
        array $params,
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
