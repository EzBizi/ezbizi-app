<?php

namespace App\Support\Inspection;

/**
 * The options of a `choice` field, and the fields that live inside them.
 *
 * ── The pattern this exists for ──────────────────────────────────
 *
 * A1's inspection generates every finding the same way: Pass / Fail /
 * Not-Applicable, where the FAIL branch carries a required photograph, an
 * explanation and priced work. A technician cannot record a failure and move on
 * — the evidence is structurally part of failing, rather than something a form
 * politely asks for and nobody supplies.
 *
 * That is the most valuable idea in the tool being ported, and it is why one
 * level of nesting is worth its complexity.
 *
 * ── One level, and no more ───────────────────────────────────────
 *
 * The old tool never goes deeper. Uncapped, this is a Builder nobody can draw,
 * a validator recursing on request data, and a run screen with no layout that
 * makes sense on a phone. `FieldSet` refuses depth two by name.
 *
 * ── Keys stay FLAT ───────────────────────────────────────────────
 *
 * A nested field has an ordinary `key` and its answer sits in the same flat
 * `answers` map as everything else. Nesting is a DISPLAY and VALIDITY
 * relationship, not a data one — which is what leaves the report renderer, the
 * §9 purge, the PDF and the attachment re-point completely unchanged.
 */
final class OptionSet
{
    public const VARIANTS = ['neutral', 'pass', 'fail', 'not_applicable'];

    public const DEFAULT_VARIANT = 'neutral';

    /**
     * Normalise one option into the full shape.
     *
     * A plain string is still an option, and that is not a courtesy: every
     * definition in the product has string options, and a richer form that
     * REPLACED them rather than joining them would refuse every existing form
     * on the day it shipped. So a string becomes `{value, label, variant,
     * fields}` and everything downstream reads one shape.
     *
     * @return array{value: string, label: string, variant: string, fields: list<mixed>}|null
     */
    public static function normalise(mixed $option): ?array
    {
        if (is_string($option) || is_numeric($option)) {
            $value = trim((string) $option);

            return $value === '' ? null : [
                'value' => $value,
                'label' => $value,
                'variant' => self::DEFAULT_VARIANT,
                'fields' => [],
            ];
        }

        if (! is_array($option)) {
            return null;
        }

        $value = trim((string) ($option['value'] ?? ''));
        if ($value === '') {
            return null;
        }

        $label = trim((string) ($option['label'] ?? ''));

        return [
            'value' => $value,
            // An option with no label shows its value, which is what a plain
            // string option has always done.
            'label' => $label === '' ? $value : $label,
            'variant' => (string) ($option['variant'] ?? self::DEFAULT_VARIANT),
            'fields' => is_array($option['fields'] ?? null)
                ? array_values($option['fields'])
                : [],
        ];
    }

    /**
     * The fields a field's options currently reveal, given the answers.
     *
     * The single home of "which nested fields are live", called by
     * RequiredFields when deciding what blocks Submit and by BoundAnswers when
     * deciding what to freeze. Two copies of this rule would eventually
     * disagree, and the disagreement would be a report carrying a photograph of
     * a fault it says does not exist.
     *
     * A MULTI-choice reveals every chosen option's fields, not the first —
     * "cracked bricks" and "loose mortar" both selected has to ask both
     * follow-ups.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    public static function revealedBy(array $field, array $answers): array
    {
        if (($field['type'] ?? '') !== FieldType::CHOICE) {
            return [];
        }

        $answer = $answers[$field['key'] ?? ''] ?? null;
        if ($answer === null || $answer === '' || $answer === []) {
            return [];
        }

        $chosen = array_map(
            static fn ($v) => is_scalar($v) ? (string) $v : '',
            is_array($answer) ? $answer : [$answer],
        );

        $revealed = [];

        foreach ((is_array($field['options'] ?? null) ? $field['options'] : []) as $option) {
            $option = self::normalise($option);

            if ($option === null || ! in_array($option['value'], $chosen, true)) {
                continue;
            }

            foreach ($option['fields'] as $nested) {
                if (is_array($nested)) {
                    $revealed[] = $nested;
                }
            }
        }

        return $revealed;
    }
}
