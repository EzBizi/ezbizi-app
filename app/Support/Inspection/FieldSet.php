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
