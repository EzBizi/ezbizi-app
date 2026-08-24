<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use Twilio\Security\RequestValidator;

/**
 * Validates X-Twilio-Signature using the connection’s stored secret.
 *
 * Never the platform Twilio token — that would cross tenant boundaries.
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

        $token = (string) $connection->credentials;
        if ($token === '') {
            return false;
        }

        return (new RequestValidator($token))->validate($header, $url, $params);
    }
}
