<?php

namespace App\Support\Contracts;

/**
 * The kinds of question a contract template may ask
 * (EZBIZI-HR-PLAN.md, "A field definition carries").
 *
 * ── Why this list is in CODE and not tenant data ─────────────────
 *
 * The no-hardcoding rule (§2.1) says a tenant defines the fields, and it does:
 * the keys, the labels, the order, who fills each one, which are sensitive —
 * none of that is known here. What a tenant does NOT get to invent is a seventh
 * TYPE, because a type is not a label. Each one below has code behind it: how
 * the answer is validated, how it renders in the form, how it merges into the
 * document, and — for `signature` — a whole evidentiary record. A type with no
 * code behind it is a question nobody can answer, which is §2.1 category 1
 * ("validation schemas") rather than category 5.
 */
final class FieldType
{
    public const TEXT      = 'text';
    public const NUMBER    = 'number';
    public const DATE      = 'date';
    public const CHOICE    = 'choice';
    public const FILE      = 'file';
    public const SIGNATURE = 'signature';

    /** key => the wording a person choosing a type reads. */
    public const ALL = [
        self::TEXT      => 'Text',
        self::NUMBER    => 'Number',
        self::DATE      => 'Date',
        self::CHOICE    => 'One of a list',
        self::FILE      => 'A file they upload',
        self::SIGNATURE => 'A signature',
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }
}
