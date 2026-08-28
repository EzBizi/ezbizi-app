<?php

namespace App\Support\Social;

/**
 * The `signed_request` Meta posts to the deauthorize and data-deletion
 * callbacks.
 *
 * ── What it is ──────────────────────────────────────────────────
 *
 * `<base64url signature>.<base64url json payload>` — an HMAC-SHA256 of the
 * payload string, keyed with the app secret. The payload carries `user_id`,
 * the app-scoped id of the person who removed the app, and `algorithm`.
 *
 * ── Why the verification is not optional ────────────────────────
 *
 * These endpoints cannot be authenticated the usual way: Meta calls them, not a
 * signed-in person, so there is no token and no session. The signature IS the
 * authentication. Skip it and anybody who can guess a user id can post to the
 * URL and revoke another organization's connections — the whole endpoint
 * becomes a way to disconnect other people's Pages.
 *
 * So: unknown algorithm is a refusal, a signature that does not match is a
 * refusal, and a payload without a user id is a refusal. Never a partial
 * accept, because there is nothing to fall back on.
 */
final class MetaSignedRequest
{
    /**
     * The payload, or null if this did not come from Meta.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(?string $signed, string $secret): ?array
    {
        if (! is_string($signed) || $secret === '' || ! str_contains($signed, '.')) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = explode('.', $signed, 2);

        $signature = self::decode($encodedSignature);
        $payload   = self::decode($encodedPayload);

        if ($signature === null || $payload === null) {
            return null;
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return null;
        }

        // Meta has only ever sent HMAC-SHA256 here. Anything else is either a
        // forgery or a change we have not read about yet, and guessing at it
        // would mean verifying with an algorithm an attacker chose.
        if (strtoupper((string) ($data['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        $expected = hash_hmac('sha256', $encodedPayload, $secret, binary: true);

        // hash_equals, not ===. A timing-safe compare costs nothing here and a
        // signature check is exactly the place a byte-at-a-time comparison
        // leaks what the right answer is.
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        return $data;
    }

    /** The app-scoped user id, or null when the request is not trustworthy. */
    public static function userId(?string $signed, string $secret): ?string
    {
        $data = self::parse($signed, $secret);
        $id   = $data === null ? null : ($data['user_id'] ?? null);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * base64url, which is base64 with two characters swapped and no padding.
     */
    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), strict: false);

        return $decoded === false ? null : $decoded;
    }
}
