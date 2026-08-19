<?php

namespace App\Support\Inspection;

/** How a named inspection is presented. The look is per named inspection. */
final class LayoutPreset
{
    public const DEFAULT = 'step_strip';

    public const STEP_STRIP  = 'step_strip';
    public const DOCUMENT    = 'document';
    public const QUIET_STACK = 'quiet_stack';

    /** @var array<string, string> */
    public const LABELS = [
        self::STEP_STRIP  => 'One screen at a time',
        self::DOCUMENT    => 'Screens as headings',
        self::QUIET_STACK => 'One scrolling column',
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return [self::STEP_STRIP, self::DOCUMENT, self::QUIET_STACK];
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }
}
