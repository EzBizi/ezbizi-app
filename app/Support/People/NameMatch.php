<?php

namespace App\Support\People;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * An outside system's name for a person (Workiz's "Booked by") to an active user here, when the
 * spellings differ (desk 11-20: "Hyanne Kylla Faelnar" is Hyanne Faelnar, "Gino Russo Folco" is
 * Gino Russo-Folco, "Jennine Lizar" is Jennine Lizer, "Mar Quino" is Marjulie Quino).
 *
 * Same first name (or one a 3+ letter prefix of the other), and a surname word in common or
 * one letter apart. Only a single candidate counts: two possible people means nobody.
 */
final class NameMatch
{
    private const SUFFIXES = ['jr', 'sr', 'ii', 'iii', 'iv'];

    public static function find(string $name, ?Collection $users = null): ?User
    {
        $words = self::words($name);
        if (count($words) < 2) {
            return null;
        }
        $users ??= User::query()->where('is_active', true)->get(['id', 'name', 'organization_id', 'is_active']);
        $hits = $users->filter(function (User $u) use ($words) {
            $theirs = self::words((string) $u->name);

            return count($theirs) >= 2 && self::sameFirst($words[0], $theirs[0]) && self::shareSurname(array_slice($words, 1), array_slice($theirs, 1));
        });

        return $hits->count() === 1 ? $hits->first() : null;
    }

    /** @return list<string> lower case, punctuation and suffixes dropped, hyphenated parts apart */
    public static function words(string $name): array
    {
        $parts = preg_split('/[\s\-]+/u', mb_strtolower(preg_replace('/[^\pL\s\-]/u', '', $name)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($parts, fn ($w) => mb_strlen($w) > 1 && ! in_array($w, self::SUFFIXES, true)));
    }

    private static function sameFirst(string $a, string $b): bool
    {
        return $a === $b || (min(mb_strlen($a), mb_strlen($b)) >= 3 && (str_starts_with($a, $b) || str_starts_with($b, $a)));
    }

    /** @param list<string> $a @param list<string> $b */
    private static function shareSurname(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($x === $y || (mb_strlen($x) >= 4 && mb_strlen($y) >= 4 && levenshtein($x, $y) <= 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
