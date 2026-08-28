<?php

namespace App\Support\Phone;

use App\Models\PhoneCall;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Counting calls (Phone > Reports).
 *
 * ── Why the day boundary is shifted in SQL ───────────────────────
 *
 * `created_at` is UTC. A business closing at 17:00 Eastern has its whole
 * afternoon land on the following UTC day for half the year, so grouping by
 * `DATE(created_at)` puts Monday's late calls on Tuesday. Every daily total
 * would be quietly wrong, and wrong in a way that looks plausible.
 *
 * CONVERT_TZ would be the tidy answer and it needs MySQL's timezone tables
 * loaded, which they usually are not. So the caller resolves the offset once
 * and it is added as minutes — correct wherever the query runs, and honest
 * about not handling a DST change that falls inside the period.
 *
 * ── Why "missed" is defined here and only here ───────────────────
 *
 * A missed call is one nobody answered: Twilio's no-answer, busy, failed and
 * cancelled. Spreading that list across a controller and a screen is how two
 * numbers on one page stop agreeing.
 */
final class CallReport
{
    /** Twilio statuses that mean nobody picked up. */
    public const MISSED_STATUSES = ['no-answer', 'busy', 'failed', 'canceled', 'cancelled'];

    /**
     * @param  list<string>  $numberIds  Lines this person may read.
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function build(
        array $numberIds,
        Carbon $from,
        Carbon $to,
        string $groupBy,
        int $offsetMinutes,
        array $filters = [],
    ): array {
        $base = fn (): Builder => self::scoped($numberIds, $from, $to, $filters);

        $missed = self::MISSED_STATUSES;

        $totals = $base()
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw("SUM(direction = 'inbound') as inbound")
            ->selectRaw("SUM(direction = 'outbound') as outbound")
            ->selectRaw('SUM(voicemail = 1) as voicemails')
            ->selectRaw(
                'SUM(CASE WHEN status IN ('.self::placeholders($missed).') THEN 1 ELSE 0 END) as missed',
                $missed,
            )
            ->selectRaw('SUM(COALESCE(duration_seconds, 0)) as seconds')
            ->selectRaw('SUM(tag_id IS NULL) as untagged')
            ->first();

        $calls = (int) ($totals->calls ?? 0);
        $missedCount = (int) ($totals->missed ?? 0);

        return [
            'totals' => [
                'calls' => $calls,
                'inbound' => (int) ($totals->inbound ?? 0),
                'outbound' => (int) ($totals->outbound ?? 0),
                'missed' => $missedCount,
                'voicemails' => (int) ($totals->voicemails ?? 0),
                'untagged' => (int) ($totals->untagged ?? 0),

                // Whole seconds; the screen decides how to say it.
                'total_seconds' => (int) ($totals->seconds ?? 0),
                'average_seconds' => $calls === 0
                    ? 0
                    : (int) round((int) ($totals->seconds ?? 0) / $calls),

                // The number a manager actually asks for. Null rather than 0
                // when there were no calls — "0% missed" out of nothing reads
                // as a good week.
                'missed_rate' => $calls === 0
                    ? null
                    : round($missedCount / $calls * 100, 1),
            ],
            'rows' => self::rows($base(), $groupBy, $offsetMinutes),
        ];
    }

    /**
     * @param  list<string>  $numberIds
     * @param  array<string, mixed>  $filters
     */
    private static function scoped(
        array $numberIds,
        Carbon $from,
        Carbon $to,
        array $filters,
    ): Builder {
        $query = PhoneCall::query()
            ->whereIn('number_id', $numberIds)
            ->whereBetween('created_at', [$from, $to]);

        if (! empty($filters['number_ids'])) {
            $query->whereIn('number_id', $filters['number_ids']);
        }

        if (! empty($filters['user_ids'])) {
            $query->whereIn('user_id', $filters['user_ids']);
        }

        if (! empty($filters['tag_ids'])) {
            $query->whereIn('tag_id', $filters['tag_ids']);
        }

        if (! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        return $query;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(Builder $query, string $groupBy, int $offsetMinutes): array
    {
        // The offset is an int from the caller, never user input, but it is
        // bound rather than interpolated so it cannot become one later.
        $local = 'DATE_ADD(created_at, INTERVAL ? MINUTE)';

        [$select, $bindings, $order] = match ($groupBy) {
            'hour' => ["LPAD(HOUR($local), 2, '0')", [$offsetMinutes], 'bucket'],
            'direction' => ['direction', [], 'bucket'],
            'tag' => ['tag_id', [], 'calls'],
            'user' => ['user_id', [], 'calls'],
            'line' => ['number_id', [], 'calls'],
            default => ["DATE($local)", [$offsetMinutes], 'bucket'],
        };

        $missed = self::MISSED_STATUSES;

        $rows = $query
            ->selectRaw("$select as bucket", $bindings)
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw(
                'SUM(CASE WHEN status IN ('.self::placeholders($missed).') THEN 1 ELSE 0 END) as missed',
                $missed,
            )
            ->selectRaw('SUM(COALESCE(duration_seconds, 0)) as seconds')
            ->groupBy(DB::raw($select === 'direction' || $select === 'tag_id'
                || $select === 'user_id' || $select === 'number_id'
                ? $select
                : 'bucket'))
            ->orderBy($order === 'calls' ? DB::raw('calls') : DB::raw('bucket'), $order === 'calls' ? 'desc' : 'asc')
            ->get();

        return $rows->map(fn ($row) => [
            // Always a string: MySQL hands back a date, an int id or null
            // depending on the grouping, and the client should not have to
            // care which. Null becomes empty, meaning "not set" — untagged
            // calls, or calls nobody answered.
            'bucket' => $row->bucket === null ? '' : (string) $row->bucket,
            'calls' => (int) $row->calls,
            'missed' => (int) $row->missed,
            'seconds' => (int) $row->seconds,
        ])->values()->all();
    }

    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
