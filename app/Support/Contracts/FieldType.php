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
    public const CHECKLIST = 'checklist';
    public const FILE      = 'file';
    public const SIGNATURE = 'signature';

    /** Hourly rate stages: "after N days, $X/hr" rows (Merav, 2026-11-17). See RateSteps. */
    public const RATE_STEPS = 'rate_steps';

    /** key => the wording a person choosing a type reads. */
    public const ALL = [
        self::TEXT      => 'Text',
        self::NUMBER    => 'Number',
        self::DATE      => 'Date',
        self::CHOICE    => 'One of a list',
        self::CHECKLIST => 'A checklist of items',
        self::FILE      => 'A file they upload',
        self::SIGNATURE => 'A signature',
        self::RATE_STEPS => 'Pay rate stages (after N days, a new hourly rate)',
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
        self::CHOICE    => true,
        self::CHECKLIST => true,
    ];

    /**
     * The types whose answer is NOT text that can be merged into the wording.
     *
     * ── The same argument TAKES_OPTIONS makes, one column along ──
     *
     * `MergeTags::resolvable()` skips signature fields and `refusalFor()`
     * refuses a tag naming one, with a paragraph explaining that a signature is
     * an act rather than a sentence. The body editor did not know: it offered
     * every live field with a key as an insertable chip, so a template with a
     * signature field offered `{{signed_by_manager}}`, and clicking it produced
     * a tag that 422s the preview and blocks the publish.
     *
     * The editor cannot ask the registry for that answer while the contract is
     * being CREATED — `MergeTags::resolvable()` is published by
     * `POST {contract}/preview`, and a contract that does not exist yet has no
     * id to preview against. So the rule is published here, on the type, where
     * the client already reads `takes_options` from the same entry.
     *
     * `file` is deliberately absent. An uploaded file has no text either, but
     * `MergeTags` does not refuse it today and this list must not be the place a
     * second opinion about that appears. It says what the server enforces, and
     * nothing more.
     */
    private const NOT_MERGED = [
        self::SIGNATURE => true,
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

    /**
     * Whether a field of this type can be named by a merge tag.
     *
     * Unknown types answer TRUE, which is the opposite default to takesOptions
     * and right for the opposite reason. That one puts a control on screen, so
     * absent must mean no. This one takes a tag AWAY, and a type this build has
     * never heard of is one FieldSet refuses outright — a field of it cannot
     * exist to be tagged. Answering false would let a stale catalogue quietly
     * withdraw a tag that publishes perfectly well, which is the failure the
     * greyed-out reserved keys were fixed for.
     */
    public static function merges(string $type): bool
    {
        return ! (self::NOT_MERGED[$type] ?? false);
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
