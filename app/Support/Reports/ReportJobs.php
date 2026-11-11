<?php

namespace App\Support\Reports;

use App\Support\Settings\Lists\BusinessLists;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where every report reads jobs and payments from: ONE place (Jose,
 * 2026-10-27: "EzBizi is the master system, Workiz is a mirror and will be
 * dropped").
 *
 * Every report goes through these methods and never names a store. Today they
 * read EzBizi's copy of Workiz (workiz_jobs / workiz_records) because Jobs'
 * own money does not exist yet; when it does, these bodies switch to `jobs` and
 * Jobs' payments, and no report changes. A tenant with no Workiz simply has
 * no rows here, and every report still renders.
 *
 * Columns every caller can rely on:
 *   jobs():     ref, serial, job_source, service_area, status ('Done' |
 *               'Canceled' | anything else is open), booked_at, closed_at,
 *               total (decimal), team (JSON list of {id, Name}), tags (JSON list), phone, created_by
 *   payments(): user_ref, amount (decimal), paid_at
 *   sales():    job_source, profit (decimal), job_date: Workiz's Sales report, a row a job
 */
final class ReportJobs
{
    /**
     * A branch's jobs, one row each. job_source is what Workiz's own Sources
     * report credits (WorkizJob::reportedSource): a sub-contracted job's
     * external company, else the job's source. Filter on the raw one as
     * j.job_source.
     */
    public static function jobs(string $branchId): Builder
    {
        return DB::table('workiz_jobs as j')->where('j.franchise_id', $branchId)
            ->leftJoin('workiz_records as ec', fn ($on) => $on->on('ec.franchise_id', '=', 'j.franchise_id')
                ->where('ec.type', 'external_company')
                ->whereRaw("ec.remote_id = concat('EC-', j.external_company_id)"))
            ->select([
                'j.remote_id as ref',
                DB::raw("json_unquote(json_extract(j.payload, '$.SerialId')) as serial"),
                DB::raw("coalesce(nullif(trim(json_unquote(json_extract(ec.payload, '$.company_name'))), ''), j.job_source) as job_source"),
                'j.service_area', 'j.status', 'j.sub_status', 'j.booked_at',
                DB::raw('coalesce(j.closed_at, j.last_status_update) as closed_at'),
                DB::raw("cast(coalesce(json_unquote(json_extract(j.payload, '$.JobTotalPrice')), 0) as decimal(12,2)) as total"),
                DB::raw("coalesce(json_extract(j.payload, '$.Team'), json_array()) as team"),
                DB::raw("coalesce(json_extract(j.payload, '$.Tags'), json_array()) as tags"),
            ]);
    }

    /** A branch's jobs as Workiz's Sales report has them: what each made the company (SyncWorkizWebSales). */
    public static function sales(string $branchId): Builder
    {
        return DB::table('workiz_records')->where('franchise_id', $branchId)->where('type', 'web_sale')
            ->select([
                DB::raw("json_unquote(json_extract(payload, '$.ad_group')) as job_source"),
                DB::raw("cast(coalesce(json_unquote(json_extract(payload, '$.profit')), 0) as decimal(12,2)) as profit"),
                'starts_at as job_date',
            ]);
    }

    /** A branch's payments received, one row each. */
    public static function payments(string $branchId): Builder
    {
        return self::received($branchId)
            ->select(['user_remote_id as user_ref', 'amount', 'starts_at as paid_at']);
    }

    /** What each person collected between two wall-clock stamps. @return array<string, float> user_ref => amount */
    public static function paidBy(string $branchId, string $from, string $to): array
    {
        return self::received($branchId)
            ->whereBetween('starts_at', [$from, $to])->groupBy('user_remote_id')
            ->selectRaw('user_remote_id, sum(amount) amount')->pluck('amount', 'user_remote_id')
            ->map(fn ($a) => (float) $a)->all();
    }

    /** A branch's payments between two wall-clock stamps, each with its job's team (null when the job isn't synced). */
    public static function paidFor(string $branchId, string $from, string $to): Collection
    {
        return self::received($branchId)->whereBetween('workiz_records.starts_at', [$from, $to])
            ->leftJoin('workiz_jobs as j', fn ($on) => $on->on('j.franchise_id', '=', 'workiz_records.franchise_id')->on('j.v2_id', '=', 'workiz_records.job_remote_id'))
            ->get(['workiz_records.user_remote_id as user_ref', 'workiz_records.amount', DB::raw("json_extract(j.payload, '$.Team') as team")]);
    }

    /** A branch's payments that went through: a pending ACH is not money yet ($1,459.08 at 1 AM, 2026-11-10). */
    private static function received(string $branchId): Builder
    {
        return DB::table('workiz_records')->where('workiz_records.franchise_id', $branchId)->where('workiz_records.type', 'payment')
            ->where(fn ($q) => $q->whereNull('workiz_records.status')->orWhere('workiz_records.status', 'succeeded'));
    }

    /** Who a user_ref / team id is, by name, in a branch. @return array<string, string> */
    public static function people(string $branchId): array
    {
        return DB::table('workiz_team_members')->where('franchise_id', $branchId)->pluck('name', 'remote_id')->all();
    }

    /** When the branch's jobs or payments were last brought up to date; null if never. */
    public static function syncedAt(string $branchId, string $what = 'jobs'): ?string
    {
        return DB::table($what === 'payments' ? 'workiz_records' : 'workiz_jobs')->where('franchise_id', $branchId)->max('synced_at');
    }

    /** The branches that have any job at all, for an organization's reports. @return list<string> */
    public static function branchesWithJobs(?string $organizationId = null): array
    {
        return DB::table('workiz_jobs')->when($organizationId, fn ($q, $o) => $q->where('organization_id', $o))
            ->distinct()->pluck('franchise_id')->all();
    }

    /** The team list a jobs() row carries, never empty: an unassigned job is "Unassigned". @return list<array{id: string, Name: string}> */
    public static function team(object $row): array
    {
        $team = json_decode((string) $row->team, true) ?: [];

        return $team === [] ? [['id' => 'none', 'Name' => 'Unassigned']] : $team;
    }

    /**
     * The jobs that count as created: less those with a tag the business
     * leaves out (Settings › Lists, reports.tags_not_created: "Paid –
     * Estimated", 22% of Nov 2-8's). Every "jobs created" figure reads it.
     *
     * @param  Collection<int, object|array>  $jobs  rows with their tags
     * @return Collection<int, object|array>
     */
    public static function created(Collection $jobs): Collection
    {
        $skip = self::tags('reports.tags_not_created');

        return $jobs->reject(fn ($job) => self::tagged(data_get($job, 'tags') ?? [], $skip))->values();
    }

    /** A tag as a person reads it, letters and digits only: "Paid – Estimated" with any dot in front is "paidestimated". */
    public static function tagKey(string $tag): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($tag));
    }

    /** @return array<string, string> a list's tags as written => as matched */
    public static function tags(string $list): array
    {
        return collect(app(BusinessLists::class)->rows($list))
            ->mapWithKeys(fn (array $row) => [(string) ($row['value']['tag'] ?? $row['key']) => self::tagKey((string) ($row['value']['tag'] ?? $row['key']))])
            ->all();
    }

    /** @param  array<string, string>  $tags  from tags() */
    public static function tagged(mixed $jobTags, array $tags): bool
    {
        $have = array_map(fn ($t) => self::tagKey((string) $t), (array) (is_string($jobTags) ? json_decode($jobTags, true) : $jobTags));

        return array_intersect(array_values($tags), $have) !== [];
    }
}
