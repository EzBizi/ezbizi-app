<?php

namespace App\Support\Jobs;

use App\Models\Estimate;
use App\Models\Job;
use App\Models\WorkizRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A job's money, the one definition every reader uses (Jose,
 * 2026-10-27: "EzBizi is the master system and Workiz a mirror"; "Pending,
 * Paid, Total"). When Jobs records its own payments, this is the one file
 * that changes.
 *
 * - Total: the job's invoice (its own lines, `amount_minor`, else the total
 *   Workiz mirrors), else its latest live estimate (its own, else Workiz's)
 *   that has an amount. Neither: no total.
 * - Paid: succeeded payments less refunds, from the Workiz mirror until EzBizi
 *   records its own.
 * - Due: Total less Paid, never below nothing.
 *
 * Every rule is one SQL expression on `jobs.id`, so a list's row, a stage's
 * filter and a dashboard's sum count the same thing. Nothing here needs a
 * Workiz connection: without one, a job's own lines and estimates are its money.
 */
final class JobMoney
{
    /** Live: not declined or archived. Workiz capitalises its statuses. */
    private const DEAD = ['declined', 'archived'];

    /** @return array<string, array{total: ?int, paid: int, due: int}> in cents, by job id */
    public static function of(array|Collection $jobIds): array
    {
        return Job::query()->whereIn('id', $jobIds)->toBase()->selectRaw('jobs.id, '.self::total().' AS total, '.self::paid().' AS paid')->get()
            ->mapWithKeys(fn ($r) => [$r->id => [
                'total' => $r->total === null ? null : (int) $r->total,
                'paid' => (int) $r->paid,
                'due' => max((int) $r->total - (int) $r->paid, 0),
            ]])->all();
    }

    /** Narrow a job query to jobs with money still due. */
    public static function due(Builder $jobs): Builder
    {
        return $jobs->whereRaw(self::dueSql().' > 0');
    }

    /** The jobs' totals, or what they still owe, summed in cents. */
    public static function sum(Builder $jobs, string $what): int
    {
        return (int) (clone $jobs)->toBase()->sum(\DB::raw(['total' => 'COALESCE('.self::total().', 0)', 'due' => self::dueSql()][$what]));
    }

    /** @return Collection<string, Collection<int, array{job_id: string, at: Carbon, cents: int}>> each job's payments, oldest first */
    public static function payments(array|Collection $jobIds): Collection
    {
        return self::paidRecords()->whereIn('job_id', $jobIds)->orderBy('starts_at')->selectRaw('job_id, starts_at, '.self::cents().' AS cents')->get()
            ->map(fn ($p) => ['job_id' => $p->job_id, 'at' => $p->starts_at, 'cents' => (int) $p->cents])->groupBy('job_id');
    }

    /**
     * Every estimate on these jobs, EzBizi's own and Workiz's, oldest first. The
     * total above takes the latest live one; this is for a timeline.
     *
     * @return Collection<string, Collection<int, array{job_id: string, at: ?Carbon, status: ?string, cents: int}>>
     */
    public static function estimates(array|Collection $jobIds): Collection
    {
        $ours = Estimate::query()->whereIn('job_id', $jobIds)->get(['job_id', 'issued_on', 'created_at', 'status', 'total_minor'])
            ->map(fn (Estimate $e) => ['job_id' => $e->job_id, 'at' => $e->issued_on ?? $e->created_at, 'status' => $e->status, 'cents' => (int) $e->total_minor]);
        $theirs = WorkizRecord::query()->whereIn('job_id', $jobIds)->where('type', 'estimate')->get(['job_id', 'starts_at', 'created_at', 'status', 'amount'])
            ->map(fn (WorkizRecord $r) => ['job_id' => $r->job_id, 'at' => $r->starts_at ?? $r->created_at, 'status' => $r->status, 'cents' => (int) round(100 * (float) $r->amount)]);

        return $ours->concat($theirs)->sortBy(fn ($e) => $e['at']?->getTimestamp() ?? 0)->values()->groupBy('job_id');
    }

    /**
     * Paid per job, in cents, within [$from, $to] when given: the same rule as Paid.
     *
     * @param  array|Builder  $jobIds  ids, or a query selecting them
     * @return array<string, int>
     */
    public static function paidCents(array|Builder $jobIds, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return self::paidRecords()->whereIn('job_id', $jobIds)->when($from, fn ($q) => $q->whereBetween('starts_at', [$from, $to]))
            ->groupBy('job_id')->selectRaw('job_id, SUM('.self::cents().') AS cents')->pluck('cents', 'job_id')
            ->map(fn ($c) => (int) $c)->all();
    }

    /** @return array<string, array{first: Carbon, last: Carbon}> each paid job's first and last payment */
    public static function paidDates(array|Collection $jobIds): array
    {
        return self::paidRecords()->whereIn('job_id', $jobIds)->groupBy('job_id')
            ->selectRaw('job_id, MIN(starts_at) AS first_paid, MAX(starts_at) AS last_paid')->get()
            ->mapWithKeys(fn ($r) => [$r->job_id => ['first' => Carbon::parse($r->first_paid), 'last' => Carbon::parse($r->last_paid)]])->all();
    }

    /** The ids of jobs with a payment, within [$from, $to] when given, as a subquery. */
    public static function paidJobIds(?Carbon $from = null, ?Carbon $to = null): Builder
    {
        return self::paidRecords()->when($from, fn ($q) => $q->whereBetween('starts_at', [$from, $to]))->select('job_id');
    }

    /** The ids of jobs first paid on or before $at, as a subquery. */
    public static function firstPaidBy(Carbon $at): Builder
    {
        return self::paidRecords()->groupBy('job_id')->havingRaw('MIN(starts_at) <= ?', [$at])->select('job_id');
    }

    /** The ids of jobs last paid before $at, as a subquery. */
    public static function lastPaidBefore(Carbon $at): Builder
    {
        return self::paidRecords()->groupBy('job_id')->havingRaw('MAX(starts_at) < ?', [$at])->select('job_id');
    }

    private static function paidRecords(): Builder
    {
        return WorkizRecord::query()->where('type', 'payment')->where('status', 'succeeded')->whereNotNull('job_id');
    }

    /** A payment row's cents, a refund negative: Workiz writes a refund's amount unsigned. */
    private static function cents(string $t = ''): string
    {
        $t = $t === '' ? '' : $t.'.';

        return "ROUND(100 * CASE WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT({$t}payload, '$.type'))) = 'refund' THEN -ABS({$t}amount) ELSE ABS({$t}amount) END)";
    }

    /** Total, in cents, of the `jobs` row in the enclosing query; NULL for none. */
    private static function total(): string
    {
        $dead = "'".implode("', '", self::DEAD)."'";

        return 'COALESCE('
            .'NULLIF(jobs.amount_minor, 0), '
            ."NULLIF((SELECT ROUND(100 * CAST(JSON_UNQUOTE(JSON_EXTRACT(w.payload, '$.JobTotalPrice')) AS DECIMAL(12,2))) FROM workiz_jobs w WHERE w.job_id = jobs.id LIMIT 1), 0), "
            // A $0 estimate is an empty draft, never a price: on Workiz job 500033871 an
            // Unsent $0 tied a Pending $7,597 on date and won (51 jobs at $0, 2026-10-28).
            // Two priced ones on one date: the larger, so the pick is stable.
            ."(SELECT e.total_minor FROM estimates e WHERE e.job_id = jobs.id AND e.total_minor > 0 AND LOWER(e.status) NOT IN ({$dead}) ORDER BY COALESCE(e.issued_on, e.created_at) DESC, e.created_at DESC, e.total_minor DESC LIMIT 1), "
            ."(SELECT ROUND(100 * r.amount) FROM workiz_records r WHERE r.job_id = jobs.id AND r.type = 'estimate' AND r.amount > 0 AND LOWER(COALESCE(r.status, '')) NOT IN ({$dead}) ORDER BY COALESCE(r.starts_at, r.created_at) DESC, r.amount DESC LIMIT 1)"
            .')';
    }

    /** Paid, in cents, of the `jobs` row in the enclosing query. */
    private static function paid(): string
    {
        return '(SELECT COALESCE(SUM('.self::cents('p').'), 0) FROM workiz_records p '
            ."WHERE p.job_id = jobs.id AND p.type = 'payment' AND p.status = 'succeeded')";
    }

    private static function dueSql(): string
    {
        return 'GREATEST(COALESCE('.self::total().', 0) - '.self::paid().', 0)';
    }
}
