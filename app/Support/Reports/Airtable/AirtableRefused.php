<?php

namespace App\Support\Reports\Airtable;

use RuntimeException;

/**
 * Airtable said no, and WHICH FIELD the person should look at.
 *
 * ── Why the field is carried rather than inferred ───────────────
 *
 * The first version threw a plain `RuntimeException` and the controller chose
 * the field with `str_contains($message, 'token')`. It picked wrong on its
 * first real case: the message for a base the token cannot reach is "That
 * token cannot reach that base…", which contains the word `token` and so sent
 * somebody to regenerate a credential that was fine.
 *
 * Matching on prose makes every sentence in this file load-bearing in a way
 * nothing states — a copy edit becomes a behaviour change, silently. The
 * thrower knows which field it means; it should say so.
 */
final class AirtableRefused extends RuntimeException
{
    private function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** The credential itself is wrong — regenerating it is the fix. */
    public static function token(string $message): self
    {
        return new self('api_token', $message);
    }

    /** The credential is fine; it does not reach the base that was named. */
    public static function base(string $message): self
    {
        return new self('base_id', $message);
    }
}
