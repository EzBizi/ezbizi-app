<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * RFC 7636 S256, for every sign-in that proves PKCE: the social networks that
 * require it, and the Google and Microsoft mail sign-ins.
 */
final class Pkce
{
    /**
     * A fresh verifier: 96 characters from the unreserved set (RFC 7636 §4.1
     * requires 43–128 of `[A-Za-z0-9-._~]`, and `Str::random` is alphanumeric).
     *
     * `Str::random` is Laravel's `random_bytes` wrapper, so this is CSPRNG
     * output rather than anything seeded — a predictable verifier is no verifier
     * at all.
     */
    public static function verifier(): string
    {
        return Str::random(96);
    }

    /**
     * The S256 challenge for a verifier — base64url of its SHA-256, unpadded.
     *
     * `strtr` for the alphabet and `rtrim` for the padding, because base64url is
     * base64 with two characters swapped and `=` removed; sending standard
     * base64 produces a challenge the provider will not match and an exchange
     * that fails for a reason no error message explains.
     */
    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
