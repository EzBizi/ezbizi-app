<?php

namespace App\Support\Inspection;

/**
 * A form walked with test answers, every decision explained, nothing saved.
 *
 * The Builder's Test tab (Jose, 2026-10-26): run any version, a draft
 * included, as a technician would, and see WHY each screen and question
 * appeared or was skipped. The verdict on every line is Condition::passes —
 * the same call the run and the report make — so the explanation cannot
 * disagree with what a technician would see; the words only describe it.
 */
final class Rehearsal
{
    /**
     * @param  list<mixed>  $screens
     * @param  array<string, mixed>  $answers
     * @return array{screens: list<array<string, mixed>>, first_missing: ?string}
     */
    public static function walk(array $screens, array $answers): array
    {
        $out = [];

        foreach ($screens as $index => $screen) {
            if (! is_array($screen)) {
                continue;
            }

            $condition = $screen['visible_if'] ?? null;
            $fields = [];

            foreach (is_array($screen['fields'] ?? null) ? $screen['fields'] : [] as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $fieldCondition = $field['visible_if'] ?? null;
                $key = (string) ($field['key'] ?? '');

                $fields[] = [
                    'key' => $key,
                    'label' => (string) ($field['label'] ?? $key),
                    'type' => (string) ($field['type'] ?? ''),
                    'required' => ($field['required'] ?? false) === true,
                    'shown' => Condition::passes($fieldCondition, $answers),
                    'why' => self::explain($fieldCondition, $answers),
                    'answer' => $key === '' ? null : ($answers[$key] ?? null),
                ];
            }

            $out[] = [
                'index' => $index,
                'name' => (string) ($screen['name'] ?? 'Screen '.($index + 1)),
                'shown' => Condition::passes($condition, $answers),
                'why' => self::explain($condition, $answers),
                'fields' => $fields,
            ];
        }

        return [
            'screens' => $out,
            'first_missing' => RequiredFields::firstMissing(
                array_values(array_filter($screens, 'is_array')),
                $answers,
            ),
        ];
    }

    /**
     * One sentence per condition: what it asks, what was answered, the verdict.
     *
     * "Always shown" for none; combiners explain each part. The verdict comes
     * from Condition::passes, never from this method's own reading.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function explain(mixed $condition, array $answers): string
    {
        if (! is_array($condition) || $condition === []) {
            return 'Always shown.';
        }

        $verdict = Condition::passes($condition, $answers) ? 'shown' : 'skipped';
        $op = (string) ($condition['op'] ?? '');

        if (in_array($op, Condition::COMBINERS, true)) {
            $parts = array_map(
                static fn ($child) => rtrim(self::explain($child, $answers), '.'),
                is_array($condition['conditions'] ?? null) ? $condition['conditions'] : [],
            );

            return ucfirst($op === 'and' ? 'all of' : 'any of').' ['.implode('; ', $parts)."] → {$verdict}.";
        }

        $field = (string) ($condition['field'] ?? '');
        $wanted = in_array($op, ['in', 'not_in'], true)
            ? implode(', ', array_map('strval', is_array($condition['values'] ?? null) ? $condition['values'] : []))
            : (is_bool($condition["value"] ?? null) ? ($condition["value"] ? "true" : "false") : (string) ($condition["value"] ?? ""));

        $answer = $answers[$field] ?? null;
        $held = $answer === null || $answer === '' || $answer === []
            ? 'not answered'
            : '"'.(is_array($answer) ? implode(', ', array_map('strval', $answer)) : (is_bool($answer) ? ($answer ? 'true' : 'false') : (string) $answer)).'"';

        $wants = match ($op) {
            'eq' => "is {$wanted}",
            'ne' => "is not {$wanted}",
            'in' => "is one of {$wanted}",
            'not_in' => "is none of {$wanted}",
            default => "({$op})",
        };

        return "When {$field} {$wants}; it is {$held} → {$verdict}.";
    }
}
