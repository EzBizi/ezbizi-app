<?php

namespace App\Support;

use RuntimeException;

/**
 * The RFC 7519 pieces more than one sender needs: base64url without padding,
 * and an RS256-signed token. A Google service-account assertion is signed by
 * FCM (PushSender, no subject) and by Workspace (GoogleWorkspace, with `sub`
 * impersonating a mailbox owner); APNs signs ES256 itself and only borrows
 * the encoding.
 */
final class Jwt
{
    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  string  $unusable  the sentence thrown when the key cannot sign — it names whose key
     *
     * @throws RuntimeException
     */
    public static function signRs256(array $claims, string $privateKey, string $unusable): string
    {
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $signing = $header.'.'.$body;
        $signature = '';

        if (openssl_sign($signing, $signature, $privateKey, OPENSSL_ALGO_SHA256) !== true || $signature === '') {
            throw new RuntimeException($unusable);
        }

        return $signing.'.'.self::base64Url($signature);
    }
}
