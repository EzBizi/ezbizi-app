<?php

namespace App\Support\Social;

use Illuminate\Support\Str;

/** RFC 7636 S256, for networks that require PKCE (X, YouTube). */
final class Pkce
{
    public static function verifier(): string
    {
        return Str::random(96);
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
