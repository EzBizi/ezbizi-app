<?php

namespace App\Support\Workiz;

use Illuminate\Support\Facades\DB;

/**
 * What the one-off Workiz twin merge (2026-09-29; the command is in git at
 * 133d3cb0) left behind that the projection and the jobs sweep still use.
 */
final class WorkizTwins
{
    /**
     * Street lines that are nobody's house: the placeholder A-1's test records
     * and staff bookers sit on, across franchises (Customers, 2026-09-29). Two
     * records meeting there are two junk records, not a person's twins, and
     * whether they stay at all is Jose's to decide. The postal guard cannot see
     * them: they all share 33009. Compared normalised, see street().
     */
    public const PLACEHOLDER_STREETS = ['800 se 4th ave'];

    /** "800 Southeast 4th Avenue" and "800 SE 4th Ave." alike: lower case, no punctuation, the usual short forms. */
    public static function street(?string $line): string
    {
        $s = mb_strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', (string) $line));
        $s = preg_replace(['/\bsouth\s*east\b/', '/\bavenue\b/', '/\bav\b/', '/\bfourth\b/'], ['se', 'ave', 'ave', '4th'], $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Which of these ids some row names: every `…_id` column in the schema,
     * deleted rows included, less the columns the caller is about to re-point
     * or remove itself.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $except  'table.column'
     * @return array<string, true>
     */
    public static function namedElsewhere(array $ids, array $except): array
    {
        if ($ids === []) {
            return [];
        }

        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('COLUMN_NAME', 'like', '%\_id')
            ->whereIn('DATA_TYPE', ['char', 'varchar'])
            ->get(['TABLE_NAME', 'COLUMN_NAME'])
            ->reject(fn ($c) => in_array($c->TABLE_NAME.'.'.$c->COLUMN_NAME, $except, true));

        $named = [];

        foreach ($columns as $column) {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (DB::table($column->TABLE_NAME)->whereIn($column->COLUMN_NAME, $chunk)->distinct()->pluck($column->COLUMN_NAME) as $id) {
                    $named[$id] = true;
                }
            }
        }

        return $named;
    }
}
