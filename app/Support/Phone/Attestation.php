<?php

namespace App\Support\Phone;

/**
 * The SHAKEN/STIR grade a call carried, normalised to one letter.
 *
 * Twilio says it two different ways depending on direction, and neither is a
 * bare grade:
 *
 *   inbound   StirVerstat   "TN-Validation-Passed-A" | "TN-Validation-Failed-C"
 *                           | "No-TN-Validation"
 *   outbound  StirStatus    "A" | "B" | "C"
 *
 * Both are read here so the two directions end up comparable, because the
 * question somebody asks — "are my calls arriving trusted" — does not care
 * which webhook happened to carry the answer.
 */
final class Attestation
{
    public const GRADES = ['A', 'B', 'C'];

    /** A call that carried no attestation at all. */
    public const NONE = 'none';

    /**
     * Read a grade from whichever field Twilio used.
     *
     * Returns null when neither field was present, which is NOT the same as
     * NONE: null means this call predates capture or came from a path that
     * carries no attestation data, and 'none' means the carrier really did
     * send it unsigned. Collapsing those two would turn "we were not looking"
     * into "your calls are untrusted", which is the more alarming of the two
     * and the wrong one.
     */
    public static function fromWebhook(?string $stirVerstat, ?string $stirStatus): ?string
    {
        $verstat = trim((string) $stirVerstat);
        $status = trim((string) $stirStatus);

        if ($verstat === '' && $status === '') {
            return null;
        }

        if ($status !== '') {
            $letter = strtoupper(substr($status, 0, 1));

            return in_array($letter, self::GRADES, true) ? $letter : self::NONE;
        }

        // "TN-Validation-Passed-A" → A. "No-TN-Validation" → none.
        if (preg_match('/-([ABC])$/i', $verstat, $m) === 1) {
            return strtoupper($m[1]);
        }

        return self::NONE;
    }
}
