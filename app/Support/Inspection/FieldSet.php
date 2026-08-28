<?php

namespace App\Support\Inspection;

use Illuminate\Validation\ValidationException;

/**
 * The screens JSON of one inspection version.
 *
 * Unknown types are refused. Photo max_count defaults to 1. required_if may
 * only name a field on the same screen.
 */
final class FieldSet
{
    /** @param list<array<string, mixed>> $screens */
    private function __construct(
        public readonly array $screens,
    ) {}

    /**
     * @param  mixed  $raw  ordered screens, each with an ordered `fields` list
     *
     * @throws ValidationException
     */
    public static function fromInput(mixed $raw): self
    {
        $screens = is_array($raw) ? array_values($raw) : [];
        $errors = [];
        $out = [];

        foreach ($screens as $si => $screen) {
            $screen = is_array($screen) ? $screen : [];
            $rows = is_array($screen['fields'] ?? null) ? array_values($screen['fields']) : [];
            $keysOnScreen = [];
            $fields = [];

            foreach ($rows as $fi => $row) {
                $row = is_array($row) ? $row : [];
                $at = "screens.{$si}.fields.{$fi}";
                $type = (string) ($row['type'] ?? '');

                if (! array_key_exists($type, FieldType::ALL)) {
                    $errors["{$at}.type"] = sprintf(
                        "'%s' is not a field type. Choose one of: %s.",
                        $type === '' ? '(empty)' : $type,
                        implode(', ', FieldType::keys()),
                    );
                }

                if ($type === FieldType::PHOTO) {
                    $row['max_count'] = isset($row['max_count']) ? (int) $row['max_count'] : 1;
                    if ($row['max_count'] < 1) {
                        $row['max_count'] = 1;
                    }
                }

                if ($type === FieldType::CHOICE) {
                    $row['multiple'] = (bool) ($row['multiple'] ?? false);

                    /*
                     * A list to choose from, or it is not a choice.
                     *
                     * This was accepted, and the reason nobody noticed is that
                     * the technician's screen drew every field as a text box —
                     * so a choice with no options looked exactly like a choice
                     * with options, and both were typed by hand. Now that it
                     * draws a dropdown, the same definition is a control with
                     * nothing in it: a question that cannot be answered, on a
                     * form somebody is required to finish.
                     *
                     * Refused here rather than in the builder because the
                     * builder is not the only writer — the seed command and any
                     * future import reach this same normaliser.
                     *
                     * ── What was checked before this shipped, and what was not ──
                     *
                     * A refusal can stop somebody working on a form they already
                     * have, so production was scanned first: every
                     * `inspection_definitions` row and every
                     * `inspection_definition_versions` row, without global
                     * scopes, for a choice field with no options.
                     *
                     * The answer was ZERO OUT OF TWO — nought bad, and a total
                     * population of one definition and one version. Both halves
                     * of that belong here. Nothing exists that this refuses, so
                     * nobody is stopped today; and a clean scan across two rows
                     * is very nearly the answer an empty database gives, so it
                     * is not evidence that the tightening is safe against a
                     * company with real forms. There is no such company yet.
                     *
                     * RE-RUN THE SCAN before Inspection reaches a populated
                     * tenant. If optionless choice fields turn up there, the
                     * answer is to grandfather what already exists — refuse the
                     * NEW one, leave the old one publishable — rather than make
                     * somebody repair a form before they can touch it.
                     *
                     * Versions matter less than definitions and it is worth
                     * knowing why: a version is written once and nothing ever
                     * re-publishes it, so a bad one is inert. The definition is
                     * the row the builder loads and saves, so that is the one
                     * where a refusal is felt.
                     */
                    $row['options'] = array_values(array_filter(
                        array_map(
                            fn ($option) => trim((string) $option),
                            is_array($row['options'] ?? null) ? $row['options'] : [],
                        ),
                        fn (string $option) => $option !== '',
                    ));

                    if ($row['options'] === []) {
                        $errors["{$at}.options"] = sprintf(
                            '“%s” asks the technician to pick one of a list, but the list is '
                            .'empty. Give it at least one choice, or make it a text field.',
                            ($row['label'] ?? '') !== '' ? $row['label'] : ($row['key'] ?? 'This field'),
                        );
                    }
                }

                $key = (string) ($row['key'] ?? '');
                if ($key !== '') {
                    $keysOnScreen[] = $key;
                }

                $fields[] = $row;
            }

            foreach ($fields as $fi => $row) {
                $target = $row['required_if']['field'] ?? null;
                if (! is_string($target) || $target === '') {
                    continue;
                }
                if (! in_array($target, $keysOnScreen, true)) {
                    $errors["screens.{$si}.fields.{$fi}.required_if"] =
                        'A field can only be required when another field on the same screen '
                        .'has a given answer. Move both onto one screen, or drop the condition.';
                }
            }

            $screen['fields'] = $fields;
            $out[] = $screen;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($out);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return $this->screens;
    }
}
