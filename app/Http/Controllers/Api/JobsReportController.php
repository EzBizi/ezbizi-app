<?php

namespace App\Http\Controllers\Api;

use App\Support\Phone\NumberNames;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Reports\ReportView;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /api/v1/reports/jobs?from&to[&source&dialed&area&q&offset&limit]
 *
 * Every job created in the dates (Jose, 2026-11-11: "All created Jobs. Filter
 * by Source, Filter by Phone Number Dialed, Filter by Area. extra column with
 * workiz id and ezbizi id"): Workiz's jobs as every report reads them, with
 * the EzBizi job each one is. The number dialed is the line the client called:
 * the Workiz call tied to the job, else the client's last call in before the
 * job was created. Filters, count and total cover the whole range; the rows
 * come a page at a time.
 */
class JobsReportController extends Controller
{
    /** How far before a job its client's call can be (a call Friday, booked Monday). */
    private const CALL_DAYS = 7;

    public function show(Request $request, RingCentralCallLog $calls): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source' => ['nullable', 'string', 'max:191'],
            'dialed' => ['nullable', 'string', 'max:32'],
            'area' => ['nullable', 'string', 'max:191'],
            'q' => ['nullable', 'string', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        abort_if(CarbonImmutable::parse($q['from'])->diffInDays(CarbonImmutable::parse($q['to'])) > 92, 422, 'Choose a range of three months or less.');

        $jobs = DB::table('workiz_jobs as j')
            ->where('j.organization_id', app(TenantContext::class)->idOrFail())
            ->whereBetween('j.booked_at', [$q['from'].' 00:00:00', $q['to'].' 23:59:59'])
            ->when(ReportView::franchiseIds(), fn ($w, $ids) => $w->whereIn('j.franchise_id', $ids))
            ->leftJoin('franchises as f', 'f.id', '=', 'j.franchise_id')
            ->leftJoin('jobs as e', 'e.id', '=', 'j.job_id')
            // The source Workiz's own reports credit: a sub-contracted job's company (ReportJobs::jobs).
            ->leftJoin('workiz_records as ec', fn ($on) => $on->on('ec.franchise_id', '=', 'j.franchise_id')
                ->where('ec.type', 'external_company')
                ->whereRaw("ec.remote_id = concat('EC-', j.external_company_id)"))
            ->orderByDesc('j.booked_at')
            ->get([
                'e.id as ezbizi_id', 'e.number as ezbizi_number', 'j.remote_id as workiz_id', 'j.serial_id as workiz_number', 'j.v2_id',
                'j.booked_at as created_at', 'f.name as branch', 'f.timezone', 'j.service_area', 'j.job_type', 'j.status', 'j.created_by',
                DB::raw("coalesce(nullif(trim(json_unquote(json_extract(ec.payload, '$.company_name'))), ''), j.job_source) as source"),
                DB::raw("trim(concat_ws(' ', json_unquote(json_extract(j.payload, '$.FirstName')), json_unquote(json_extract(j.payload, '$.LastName')))) as client"),
                DB::raw("json_unquote(json_extract(j.payload, '$.Phone')) as phone"),
                DB::raw("cast(coalesce(json_unquote(json_extract(j.payload, '$.JobTotalPrice')), 0) as decimal(12,2)) as total"),
            ]);

        [$dialed, $read] = $this->dialed($jobs, $q['from'], $calls);
        $names = $read ? $calls->numberNames() : [];
        $rows = $jobs->map(fn (object $j) => [
            'ezbizi_id' => $j->ezbizi_id,
            'ezbizi_number' => $j->ezbizi_number,
            'workiz_id' => $j->workiz_id,
            'workiz_number' => $j->workiz_number === null ? null : (string) $j->workiz_number,
            'created_at' => $j->created_at,
            'client' => $j->client ?: null,
            'phone' => $j->phone ?: null,
            'branch' => $j->branch,
            'service_area' => trim((string) $j->service_area) ?: null,
            'source' => trim((string) $j->source) ?: null,
            'dialed' => $number = $dialed[$j->workiz_id] ?? null,
            'dialed_name' => $number === null ? null : ($names[$number] ?? null),
            'job_type' => $j->job_type,
            'status' => $j->status,
            'total' => (float) $j->total,
            'created_by' => $j->created_by,
        ]);

        // Each filter's choices are the whole range's; 'none' picks the jobs with none.
        $pick = fn (?string $want, string $key) => fn (array $r) => $want === null || ($want === 'none' ? $r[$key] === null : $r[$key] === $want);
        // The search: a client, who created it, either id, or three digits of the phone.
        $needle = mb_strtolower(trim((string) ($q['q'] ?? '')));
        $digits = (string) preg_replace('/\D/', '', $needle);
        $found = fn (array $r) => $needle === ''
            || str_contains(mb_strtolower(implode(' ', [$r['ezbizi_number'], $r['workiz_id'], $r['workiz_number'], $r['client'], $r['created_by']])), $needle)
            || (strlen($digits) >= 3 && str_contains((string) preg_replace('/\D/', '', (string) $r['phone']), $digits));
        $shown = $rows->filter($pick($q['source'] ?? null, 'source'))->filter($pick($q['dialed'] ?? null, 'dialed'))
            ->filter($pick($q['area'] ?? null, 'service_area'))->filter($found)->values();
        $counted = fn (string $key) => $rows->countBy(fn (array $r) => $r[$key] ?? 'none')->sortDesc()
            ->map(fn (int $n, string $value) => ['value' => $value, 'count' => $n])->values();

        return response()->json(['data' => [
            'rows' => $shown->slice((int) ($q['offset'] ?? 0), (int) ($q['limit'] ?? 200))->values(),
            'count' => $shown->count(),
            'total' => round($shown->sum('total'), 2),
            'sources' => $counted('source'),
            'areas' => $counted('service_area'),
            'dialed' => $counted('dialed')->map(fn (array $d) => $d + ['name' => $names[$d['value']] ?? null]),
            // False when the phone system could not be read: no number is then "none".
            'calls_read' => $read,
        ]]);
    }

    /**
     * Workiz job id => the number the client dialed, and whether the call log could be read.
     *
     * @param  Collection<int, object>  $jobs
     * @return array{0: array<string, string>, 1: bool}
     */
    private function dialed(Collection $jobs, string $from, RingCentralCallLog $calls): array
    {
        // A Workiz call tied to the job says it outright.
        $dialed = DB::table('workiz_calls')->where('direction', 'inbound')->whereNotNull('to_number')
            ->whereIn('job_remote_id', $jobs->pluck('v2_id')->filter()->all())->orderByDesc('called_at')
            ->pluck('to_number', 'job_remote_id')->all();
        $byJob = [];
        foreach ($jobs as $j) {
            if ($j->v2_id !== null && isset($dialed[$j->v2_id])) {
                $byJob[$j->workiz_id] = $dialed[$j->v2_id];
            }
        }

        if (! $calls->configured()) {
            return [$byJob, false];
        }
        try {
            $zone = ReportView::zone();
            $log = $calls->forPeriod(CarbonImmutable::parse($from, $zone)->subDays(self::CALL_DAYS), CarbonImmutable::now($zone));
        } catch (Throwable $e) {
            report($e);

            return [$byJob, false];
        }

        // A Workiz call tied to the job wins; else the client's last call in before it.
        return [$byJob + self::lastCallIn($log, $jobs, ReportView::zone()), true];
    }

    /**
     * Workiz job id => the line its client last called before the job was booked (Workiz's
     * wall clock, in the branch's zone), within CALL_DAYS. $log newest first, as forPeriod gives it.
     *
     * @param  list<array<string, mixed>>  $log
     * @param  Collection<int, object>  $jobs
     * @return array<string, string>
     */
    public static function lastCallIn(array $log, Collection $jobs, string $zone): array
    {
        $byPhone = [];
        foreach ($log as $call) {
            if (($phone = NumberNames::lastTen($call['from'] ?? null)) !== null && ($call['to_number'] ?? null) !== null) {
                $byPhone[$phone][] = [strtotime((string) $call['started_at']), (string) $call['to_number']];
            }
        }
        $byJob = [];
        foreach ($jobs as $j) {
            $phone = NumberNames::lastTen($j->phone);
            if ($phone === null || ! isset($byPhone[$phone])) {
                continue;
            }
            $booked = CarbonImmutable::parse((string) $j->created_at, $j->timezone ?: $zone)->getTimestamp();
            foreach ($byPhone[$phone] as [$at, $number]) {
                if ($at <= $booked + 300 && $at >= $booked - self::CALL_DAYS * 86400) {
                    $byJob[$j->workiz_id] = $number;
                    break;
                }
            }
        }

        return $byJob;
    }
}
