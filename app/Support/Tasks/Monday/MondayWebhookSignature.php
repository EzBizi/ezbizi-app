<?php

namespace App\Support\Tasks\Monday;

/**
 * Is this request from Monday? Monday signs the webhooks an app's token
 * creates with a JWT in the Authorization header, HS256 over the app's
 * Signing Secret (config services.monday.signing_secret). Anything unsigned,
 * signed with another secret, of another algorithm or expired is refused.
 * No secret configured refuses everything.
 */
final class MondayWebhookSignature
{
    /** @return array<string, mixed>|null the token's claims, or null when it is not Monday's */
    public static function verify(?string $header, ?string $secret): ?array
    {
        if (! is_string($secret) || $secret === '' || ! is_string($header) || trim($header) === '') {
            return null;
        }
        $jwt = trim((string) preg_replace('/^Bearer\s+/i', '', trim($header)));
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$head, $body, $signature] = $parts;
        $header = json_decode(self::decode($head) ?? '', true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $head.'.'.$body, $secret, true)), '+/', '-_'), '=');
        if (! hash_equals($expected, $signature)) {
            return null;
        }
        $claims = json_decode(self::decode($body) ?? '', true);
        if (! is_array($claims)) {
            return null;
        }
        if (isset($claims['exp']) && is_numeric($claims['exp']) && (int) $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    /** A token as Monday would sign it; for tests. */
    public static function sign(array $claims, string $secret): string
    {
        $enc = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $enc((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $enc((string) json_encode($claims));

        return $head.'.'.$body.'.'.$enc(hash_hmac('sha256', $head.'.'.$body, $secret, true));
    }

    private static function decode(string $part): ?string
    {
        $raw = base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);

        return $raw === false ? null : $raw;
    }
}
