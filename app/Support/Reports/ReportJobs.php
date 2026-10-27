<?php

namespace App\Support\Reports;

use Illuminate\Database\Query\Builder;
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
 *               total (decimal), team (JSON list of {id, Name}), phone, created_by
 *   payments(): user_ref, amount (decimal), paid_at
 */
final class ReportJobs
{
    /** A branch's jobs, one row each. */
    public static function jobs(string $branchId): Builder
    {
        return DB::table('workiz_jobs')->where('franchise_id', $branchId)->select([
            'remote_id as ref',
            DB::raw("json_unquote(json_extract(payload, '$.SerialId')) as serial"),
            'job_source', 'service_area', 'status', 'booked_at',
            'last_status_update as closed_at',
            DB::raw("cast(coalesce(json_unquote(json_extract(payload, '$.JobTotalPrice')), 0) as decimal(12,2)) as total"),
            DB::raw("coalesce(json_extract(payload, '$.Team'), json_array()) as team"),
        ]);
    }

    /** A branch's payments received, one row each. */
    public static function payments(string $branchId): Builder
    {
        return DB::table('workiz_records')->where('franchise_id', $branchId)->where('type', 'payment')
            ->select(['user_remote_id as user_ref', 'amount', 'starts_at as paid_at']);
    }

    /** What each person collected between two wall-clock stamps. @return array<string, float> user_ref => amount */
    public static function paidBy(string $branchId, string $from, string $to): array
    {
        return DB::table('workiz_records')->where('franchise_id', $branchId)->where('type', 'payment')
            ->whereBetween('starts_at', [$from, $to])->groupBy('user_remote_id')
            ->selectRaw('user_remote_id, sum(amount) amount')->pluck('amount', 'user_remote_id')
            ->map(fn ($a) => (float) $a)->all();
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
}
