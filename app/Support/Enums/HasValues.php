<?php

namespace App\Support\Enums;

/**
 * `values()` on a backed enum, written once.
 *
 * Twenty-three enums carried an identical five-line copy of this. The bodies
 * were the same character for character, so the only thing the duplication
 * bought was twenty-three places for one of them to drift.
 */
trait HasValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
