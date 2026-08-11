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

    /**
     * The types whose definition includes a list of answers to pick from.
     *
     * ── Why this is a declaration and not `=== CHOICE` at each site ──
     *
     * Three places need the answer — the validator here, the catalogue the client
     * builds its form out of, and the client's own options editor — and all three
     * had it written out as a comparison against `choice`. The designer's was the
     * one that mattered: it gated the options box on a literal `'choice'` in Dart,
     * in a screen whose own header says it does not re-state server rules. A
     * seventh type taking options would have been offered with no way to give it
     * any, and nothing would have said why.
     *
     * So the fact lives here once, the catalogue publishes it as `takes_options`,
     * and the client reads it. Adding a type that takes options is then one line.
     */
    private const TAKES_OPTIONS = [
        self::CHOICE => true,
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * Unknown types answer false, which is the honest reading: a type this build
     * has never heard of is refused by FieldSet anyway, and pretending it takes
     * options would put an editor on screen for a field that cannot be saved.
     */
    public static function takesOptions(string $type): bool
    {
        return self::TAKES_OPTIONS[$type] ?? false;
    }

    /** The types that take options, in the order ALL declares them. */
    public static function optionTakingLabels(): string
    {
        return implode(', ', array_map(
            fn (string $key) => self::ALL[$key],
            array_keys(self::TAKES_OPTIONS),
        ));
    }
}
