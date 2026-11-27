<?php

namespace App\Support\Social\Liveness;

/**
 * One connection's answer to "does this token still work?".
 *
 * Three answers, not two: a timeout, a 5xx or a 429 says nothing about the
 * grant, so it must never be read as a refusal (GoogleToken explains what
 * that cost on 10-11 September).
 */
final class Liveness
{
    public const ALIVE = 'alive';

    public const REFUSED = 'refused';

    public const UNKNOWN = 'unknown';

    private function __construct(public readonly string $verdict, public readonly ?string $reason = null) {}

    public static function alive(): self
    {
        return new self(self::ALIVE);
    }

    /** The provider said the grant itself is gone; $reason is the sentence the card shows. */
    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, $reason);
    }

    /** No verdict: the provider did not answer, or answered about this moment rather than the grant. */
    public static function unknown(?string $why = null): self
    {
        return new self(self::UNKNOWN, $why);
    }
}
