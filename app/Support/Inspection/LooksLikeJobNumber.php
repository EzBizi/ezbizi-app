<?php

namespace App\Support\Inspection;

/**
 * Whether a search string looks like a job number (J-1042, JOB-00042, JOB-2026-00042)
 * rather than a client name.
 */
final class LooksLikeJobNumber
{
    public static function check(string $query): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9]*-\d+(?:-\d+)?$/', trim($query));
    }
}
