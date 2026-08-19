<?php

namespace App\Support\Inspection;

use Illuminate\Validation\ValidationException;

/** First missing required field, named in the sentence Next and Submit share. */
final class RequiredFields
{
    /** @param  list<array<string, mixed>>  $screens */
    public static function firstMissing(array $screens, array $answers): ?string
    {
        foreach ($screens as $screen) {
            $fields = is_array($screen['fields'] ?? null) ? $screen['fields'] : [];
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }
                if (! self::isRequired($field, $answers)) {
                    continue;
                }
                if (! self::isFilled($field, $answers)) {
                    return (string) ($field['label'] ?? $field['key'] ?? 'This field');
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
