<?php

namespace App\Support\Inspection;

use App\Models\InspectionDefinition;

/**
 * Create cannot leave the name blank — the unique index is on a real string —
 * so the first save gets a working title the unique index can see.
 *
 * Publish still refuses these: the picker must not offer a blank-feeling row.
 */
final class WorkingTitle
{
    public const BASE = 'Untitled inspection';

    public static function isPlaceholder(string $name): bool
    {
        return (bool) preg_match('/^'.preg_quote(self::BASE, '/').'(?: \d+)?$/', $name);
    }

    /** Call inside a transaction that has locked the organization's definitions. */
    public static function next(): string
    {
        $taken = InspectionDefinition::query()
            ->where('name', 'like', self::BASE.'%')
            ->lockForUpdate()
            ->pluck('name')
            ->all();

        $n = 1;
        while (true) {
            $candidate = $n === 1 ? self::BASE : self::BASE.' '.$n;
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
            $n++;
        }
    }
}
