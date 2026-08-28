<?php

namespace App\Support\Inspection;

/**
 * Inspection's field types. Separate from Contracts\FieldType — photo belongs
 * here, and adding it to HR would offer a type that module cannot render.
 *
 * ── Chosen by counting, not by copying a type union ──────────────
 *
 * The A1 tool's union declares `address` and `time-slot`; its real inspection
 * uses `address` ZERO times — the customer step asks for line 1, line 2, city,
 * state, zip and country as six separate fields — and `time-slot` once, for a
 * Workiz booking window EzBizi schedules on the Job instead. Neither is here.
 * A type is forever, and one nobody asks for is a permanent obligation bought
 * with nothing.
 *
 * `checkbox-group` is likewise absent: `choice` with `multiple` is that control
 * under a name this module already has.
 *
 * ── Two of these carry no answer ─────────────────────────────────
 *
 * DIVIDER and ALERT are furniture. They need no key, they are never required,
 * and nothing is stored for them — see `carriesAnswer`.
 */
final class FieldType
{
    public const TEXT      = 'text';
    public const TEXTAREA  = 'textarea';
    public const NUMBER    = 'number';
    public const DATE      = 'date';
    public const EMAIL     = 'email';
    public const PHONE     = 'phone';
    public const CHOICE    = 'choice';
    public const CHECKBOX  = 'checkbox';
    public const FILE      = 'file';
    public const PHOTO     = 'photo';
    public const SIGNATURE = 'signature';

    /** Furniture. No key, no answer, never required. */
    public const DIVIDER = 'divider';

    public const ALERT = 'alert';

    /** @var array<string, string> */
    public const ALL = [
        self::TEXT      => 'Text',
        self::TEXTAREA  => 'Longer text',
        self::NUMBER    => 'Number',
        self::DATE      => 'Date',
        self::EMAIL     => 'Email address',
        self::PHONE     => 'Phone number',
        self::CHOICE    => 'One of a list',
        self::CHECKBOX  => 'A tick box',
        self::FILE      => 'A file they upload',
        self::PHOTO     => 'A photograph',
        self::SIGNATURE => 'A signature',
        self::DIVIDER   => 'A heading between fields',
        self::ALERT     => 'A note to the technician',
    ];

    /** The types that store nothing, so nothing can be missing from them. */
    public const FURNITURE = [self::DIVIDER, self::ALERT];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * Does an answer get stored for this type?
     *
     * False for a divider and an alert. They need no key, are never required,
     * and are skipped when deciding what blocks Submit — honouring `required`
     * on a horizontal rule would block a form with no way for anybody to
     * satisfy it.
     */
    public static function carriesAnswer(string $type): bool
    {
        return ! in_array($type, self::FURNITURE, true);
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
