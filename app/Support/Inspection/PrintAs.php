<?php

namespace App\Support\Inspection;

/**
 * How one field appears on the report.
 *
 * ── Why a report needs this at all ───────────────────────────────
 *
 * A1's inspection asks about a hundred and twenty things, and printing all of
 * them as label-and-value rows produces a document nobody reads. `system_type`
 * drives eleven branches and needs to be on the report once at most; "Chimney
 * crown condition" — with its photograph, its cited code and its recommended
 * work — is the thing the customer is actually being told, and it should not be
 * one row among a hundred and nineteen others.
 *
 * The old tool answers this with a `pdf` key per field. These are the same
 * three answers.
 */
final class PrintAs
{
    /**
     * A FINDING. A heading of its own, with everything it gathered beneath it —
     * the chosen option in words, the photograph, the citation, the recommended
     * work — and coloured by the option's variant so a failure is visible
     * without reading.
     */
    public const ROOT = 'root';

    /** A plain label-and-value line. The default. */
    public const TEXT = 'text';

    /**
     * Answered, never printed. For the questions that exist to steer the form:
     * a customer does not need to be told "masonry" eleven times.
     */
    public const IGNORE = 'ignore';

    public const ALL = [self::ROOT, self::TEXT, self::IGNORE];

    /**
     * How this field prints.
     *
     * TEXT unless the field says otherwise. Nothing in the product carries a
     * `print` key, so any other default would restyle every report ever
     * submitted — which the module's written-once design forbids.
     */
    public static function of(array $field): string
    {
        $print = (string) ($field['print'] ?? self::TEXT);

        return in_array($print, self::ALL, true) ? $print : self::TEXT;
    }
}
