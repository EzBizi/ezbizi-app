<?php

namespace App\Support\Inspection;

/**
 * Inspection’s field types. Separate from Contracts\FieldType — photo belongs
 * here, and adding it to HR would offer a type that module cannot render.
 */
final class FieldType
{
    public const TEXT      = 'text';
    public const NUMBER    = 'number';
    public const DATE      = 'date';
    public const CHOICE    = 'choice';
    public const FILE      = 'file';
    public const PHOTO     = 'photo';
    public const SIGNATURE = 'signature';

    /** @var array<string, string> */
    public const ALL = [
        self::TEXT      => 'Text',
        self::NUMBER    => 'Number',
        self::DATE      => 'Date',
        self::CHOICE    => 'One of a list',
        self::FILE      => 'A file they upload',
        self::PHOTO     => 'A photograph',
        self::SIGNATURE => 'A signature',
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function takesOptions(string $type): bool
    {
        return $type === self::CHOICE;
    }

    public static function allowsMultiple(string $type): bool
    {
        return $type === self::CHOICE;
    }
}
