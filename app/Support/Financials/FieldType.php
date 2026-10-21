<?php

namespace App\Support\Financials;

use Illuminate\Validation\ValidationException;

/**
 * The column types a Financials table offers — Airtable's everyday set.
 * `formula` is computed by the client from the other cells and never stored.
 */
final class FieldType
{
    public const TYPES = [
        'text', 'long_text', 'number', 'currency', 'percent', 'date', 'checkbox',
        'single_select', 'multi_select', 'email', 'phone', 'url', 'rating', 'formula', 'attachment',
    ];

    /** Normalize one incoming cell; null clears it. */
    public static function coerce(string $type, mixed $value, array $options, string $fieldName): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $fail = fn (string $why) => throw ValidationException::withMessages(["values.{$fieldName}" => "{$fieldName}: {$why}"]);

        return match ($type) {
            'text', 'long_text', 'phone' => is_scalar($value) ? (string) $value : $fail('must be text.'),
            'number', 'currency', 'percent' => is_numeric($value) ? $value + 0 : $fail('must be a number.'),
            'rating' => is_numeric($value) && $value >= 0 && $value <= 5 ? (int) $value : $fail('must be 0 to 5.'),
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $fail('must be true or false.'),
            'date' => is_string($value) && strtotime($value) !== false ? date('Y-m-d', strtotime($value)) : $fail('must be a date.'),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ?: $fail('must be an email address.'),
            'url' => filter_var($value, FILTER_VALIDATE_URL) ?: $fail('must be a web address.'),
            'single_select' => in_array($value, self::choices($options), true) ? $value : $fail("“{$value}” is not one of its choices."),
            'multi_select' => is_array($value) && array_diff($value, self::choices($options)) === []
                ? array_values(array_unique($value)) : $fail('has a value that is not one of its choices.'),
            'formula' => null,
            // Files as Airtable gives them: [{filename, url, type?, size?}]. Only mirrored for now.
            'attachment' => is_array($value) && collect($value)->every(fn ($a) => is_array($a) && isset($a['url']))
                ? array_values(array_map(fn ($a) => array_intersect_key($a, array_flip(['id', 'filename', 'url', 'type', 'size'])), $value))
                : $fail('must be a list of files.'),
            default => $fail('unknown field type.'),
        };
    }

    /** @return list<string> */
    public static function choices(array $options): array
    {
        return array_values(array_map(fn ($c) => is_array($c) ? (string) ($c['name'] ?? '') : (string) $c, $options['choices'] ?? []));
    }
}
