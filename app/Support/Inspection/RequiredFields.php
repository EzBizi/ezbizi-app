<?php

namespace App\Support\Inspection;

use Illuminate\Validation\ValidationException;

/**
 * First missing required field, named in the sentence Next and Submit share.
 *
 * A HIDDEN field is skipped, and that is the whole reason this file changed.
 * Without it a masonry inspection is blocked at Submit by an unanswered
 * wood-stove question that was never on screen — the technician is told a field
 * they cannot see needs an answer, and there is nothing they can do about it.
 *
 * The condition removes the QUESTION, it does not excuse it: the moment the
 * field is shown again it is required again.
 */
final class RequiredFields
{
    /** @param  list<array<string, mixed>>  $screens */
    public static function firstMissing(array $screens, array $answers): ?string
    {
        foreach ($screens as $screen) {
            if (! is_array($screen)) {
                continue;
            }

            // A screen nobody is shown asks nothing.
            if (! Condition::passes($screen['visible_if'] ?? null, $answers)) {
                continue;
            }

            $fields = is_array($screen['fields'] ?? null) ? $screen['fields'] : [];
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }
                if (! Condition::passes($field['visible_if'] ?? null, $answers)) {
                    continue;
                }

                if (self::isRequired($field, $answers) && ! self::isFilled($field, $answers)) {
                    return (string) ($field['label'] ?? $field['key'] ?? 'This field');
                }

                /*
                 * The fields this field's chosen option reveals.
                 *
                 * Checked AFTER the field itself, so "pick a condition" is asked
                 * before "photograph the fault" — the order they appear on
                 * screen, and the order a technician can act on.
                 */
                foreach (OptionSet::revealedBy($field, $answers) as $inner) {
                    if (! Condition::passes($inner['visible_if'] ?? null, $answers)) {
                        continue;
                    }
                    if (self::isRequired($inner, $answers) && ! self::isFilled($inner, $answers)) {
                        return (string) ($inner['label'] ?? $inner['key'] ?? 'This field');
                    }
                }
            }
        }

        return null;
    }

    /** @param  list<array<string, mixed>>  $screens */
    public static function assertFilled(array $screens, array $answers): void
    {
        $label = self::firstMissing($screens, $answers);
        if ($label === null) {
            return;
        }

        throw ValidationException::withMessages([
            'answers' => "{$label} needs an answer before you can continue.",
        ]);
    }

    /** @param  array<string, mixed>  $field */
    private static function isRequired(array $field, array $answers): bool
    {
        if (($field['required'] ?? false) === true) {
            return true;
        }

        $rule = $field['required_if'] ?? null;
        if (! is_array($rule)) {
            return false;
        }

        $other = (string) ($rule['field'] ?? '');
        $equals = $rule['equals'] ?? $rule['value'] ?? null;

        return $other !== '' && ($answers[$other] ?? null) == $equals;
    }

    /** @param  array<string, mixed>  $field */
    private static function isFilled(array $field, array $answers): bool
    {
        $value = $answers[$field['key'] ?? ''] ?? null;
        if ($value === null || $value === '') {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
