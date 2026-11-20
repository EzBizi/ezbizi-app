<?php

namespace App\Http\Controllers\Api;

use App\Support\Reports\ReportView;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizClock;
use App\Models\Franchise;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/tech-checks?from&to[&tech&check]   (AI checks)
 * GET /api/v1/reports/technicians?from&to                (Tech report)
 *
 * Shoval, 2026-11-18: "AI to recognize techs not calling with eta in time,
 * closing on time, not making inspection reports, not enough photos" and
 * "Tech report and account (complaints and every issue automated)". Rules,
 * not a model (Grok, 11-20: the checks are rules; photo judging is not worth
 * it). Over the jobs done that were scheduled in the dates, per technician
 * (a job with two is on both rows):
 *  - eta: no call out to the client in the hours before the start (Workiz's
 *    calls; EzBizi's texts) — derived, a call from a personal phone is not seen;
 *  - close: closed more than N hours after the start (Workiz rewrites the
 *    scheduled end to the close, so the start is the measure);
 *  - inspection: a job of a type the business says needs an inspection
 *    report with none submitted (no types listed: not checked);
 *  - photos: fewer photos than the business's minimum (Workiz's attachments
 *    and EzBizi's own).
 * The hours and counts are Settings › Lists › Technician check rules; the
 * types, Settings › Lists › Job types that need an inspection report.
 * The Tech report adds, per technician: revenue, complaints raised about
 * their jobs, and redo jobs (types in Settings › Lists › Redo job types).
 * With tech and check, the jobs behind one cell instead.
 */
class TechnicianReportController extends Controller
{
    public const RULES = 'reports.tech_check_rules';

    public const INSPECTION_TYPES = 'reports.inspection_job_types';

    public const REDO_TYPES = 'reports.redo_job_types';

    public const CHECKS = ['eta', 'close', 'inspection', 'photos'];

    public function show(Request $request, BusinessLists $lists, string $kind): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'tech' => ['nullable', 'string', 'max:191'],
            'check' => ['nullable', 'string', 'in:'.implode(',', self::CHECKS)],
        ]);
        abort_if(CarbonImmutable::parse($q['from'])->diffInDays(CarbonImmutable::parse($q['to'])) > 92, 422, 'Choose a range of three months or less.');
        $org = app(TenantContext::class)->idOrFail();
        $rule = fn (string $key, float $default) => (float) (collect($lists->rows(self::RULES))
            ->filter(fn (array $r) => ($r['value']['rule'] ?? $r['key']) === $key)->last()['value']['value'] ?? $default);
        [$etaHours, $closeHours, $minPhotos] = [$rule('eta_hours', 3), $rule('close_hours', 12), (int) $rule('min_photos', 3)];
        $types = fn (string $list) => collect($lists->rows($list))->map(fn (array $r) => mb_strtolower(trim((string) ($r['value']['type'] ?? $r['key']))))->filter()->values()->all();
        $inspectionTypes = $types(self::INSPECTION_TYPES);

        $jobs = $this->jobs($org, $q['from'], $q['to']);
        $ahead = $this->contactsOut($org, $jobs);
        foreach ($jobs as $j) {
            $start = $j->start === null ? null : CarbonImmutable::parse($j->start, $j->zone)->utc();
            $from = $start?->subMinutes((int) round($etaHours * 60))->toDateTimeString();
            $until = $start?->addMinutes(30)->toDateTimeString();
            $j->misses = array_keys(array_filter([
                'eta' => $start !== null && $j->phone !== null
                    && ! collect($ahead[$j->phone] ?? [])->contains(fn (string $at) => $at >= $from && $at <= $until),
                'close' => $start !== null && $j->closed_at !== null
                    && CarbonImmutable::parse($j->closed_at, $j->zone)->utc()->greaterThan($start->addMinutes((int) round($closeHours * 60))),
                'inspection' => $inspectionTypes !== [] && in_array(mb_strtolower(trim((string) $j->job_type)), $inspectionTypes, true) && ! $j->inspected,
                'photos' => $j->photos < $minPhotos,
            ]));
        }

        if (isset($q['tech'], $q['check'])) {
            return response()->json(['data' => ['rows' => $jobs->filter(fn (object $j) => in_array($q['tech'], $j->techs ?: ['none'], true)
                && in_array($q['check'], $j->misses, true))->map(fn (object $j) => $this->jobRow($j))->values()]]);
        }

        $complaints = $kind === 'report' ? $this->complaints($org, $jobs) : [];
        $redo = $types(self::REDO_TYPES);
        $byTech = [];
        foreach ($jobs as $j) {
            foreach ($j->techs ?: ['none'] as $tech) {
                $byTech[$tech][] = $j;
            }
        }
        $rows = collect($byTech)->map(function (array $mine, string $tech) use ($kind, $complaints, $redo, $inspectionTypes) {
            $mine = collect($mine);
            $miss = fn (string $check) => $mine->filter(fn (object $j) => in_array($check, $j->misses, true))->count();
            $row = ['tech' => $tech === 'none' ? null : $tech, 'jobs' => $mine->count()];
            foreach (self::CHECKS as $check) {
                $row[$check.'_missed'] = $check === 'inspection' && $inspectionTypes === [] ? null : $miss($check);
            }
            if ($kind === 'report') {
                $row += [
                    'revenue_minor' => (int) $mine->sum('total_minor'),
                    'complaints' => $mine->sum(fn (object $j) => $complaints[$j->id] ?? 0),
                    'redo' => $redo === [] ? null : $mine->filter(fn (object $j) => in_array(mb_strtolower(trim((string) $j->job_type)), $redo, true))->count(),
                ];
            }

            return $row;
        })->sortByDesc('jobs')->values();

        return response()->json(['data' => [
            'rows' => $rows,
            'rules' => ['eta_hours' => $etaHours, 'close_hours' => $closeHours, 'min_photos' => $minPhotos, 'inspection_types' => $inspectionTypes],
            'jobs' => $jobs->count(),
        ]]);
    }

    /**
     * Done jobs scheduled in the dates: EzBizi's own, with the Workiz copy's
     * start, close, phone and photos where there is one.
     *
     * @return Collection<int, object>
     */
    private function jobs(string $org, string $from, string $to): Collection
    {
        $zone = ReportView::zone();
        $jobs = DB::table('jobs as j')->where('j.organization_id', $org)->whereNull('j.deleted_at')
            ->when(ReportView::franchiseIds(), fn ($w, $ids) => $w->whereIn('j.franchise_id', $ids))
            ->whereIn(DB::raw('lower(trim(j.status))'), ['done', 'completed'])
            ->leftJoin('workiz_jobs as w', 'w.job_id', '=', 'j.id')->leftJoin('customers as c', 'c.id', '=', 'j.customer_id')
            ->whereBetween(DB::raw('coalesce(w.scheduled_for, j.scheduled_for)'), [CarbonImmutable::parse($from)->subDay()->toDateString(), CarbonImmutable::parse($to)->addDays(2)->toDateString()])
            ->get(['j.id', 'j.number', 'j.franchise_id', 'j.job_type', 'j.amount_minor', 'j.scheduled_for as native_start', 'w.scheduled_for as workiz_start', 'w.closed_at',
                'w.serial_id', 'c.name as client', DB::raw("coalesce(json_unquote(json_extract(w.payload, '$.Phone')), c.phone) as phone"),
                DB::raw("coalesce(json_length(json_extract(w.payload, '$.Attachments')), 0) as workiz_photos"),
                DB::raw("cast(coalesce(json_unquote(json_extract(w.payload, '$.JobTotalPrice')), 0) as decimal(12,2)) as workiz_total")]);
        $ids = $jobs->pluck('id')->all();
        $techs = DB::table('job_technicians as jt')->join('scheduling_technicians as t', 't.id', '=', 'jt.technician_id')->whereIn('jt.job_id', $ids)
            ->get(['jt.job_id', 't.name'])->groupBy('job_id')->map(fn ($r) => $r->pluck('name')->unique()->values()->all());
        $photos = DB::table('attachments')->where('attachable_type', 'jobs')->whereIn('attachable_id', $ids)->groupBy('attachable_id')
            ->selectRaw('attachable_id, count(*) n')->pluck('n', 'attachable_id');
        $inspected = DB::table('inspection_reports')->whereIn('job_id', $ids)->whereNotNull('submitted_at')->pluck('job_id')->flip();
        $zones = Franchise::query()->withoutGlobalScopes()->whereIn('id', $jobs->pluck('franchise_id')->unique()->filter()->all())->get()
            ->mapWithKeys(fn (Franchise $f) => [$f->getKey() => WorkizClock::zone($f)]);

        return $jobs->map(function (object $j) use ($techs, $photos, $inspected, $zones, $zone) {
            // Workiz's times are its account's wall clock; EzBizi's are UTC.
            $j->workiz_zone = $zones[$j->franchise_id] ?? 'America/New_York';
            $j->zone = $j->workiz_start !== null ? $j->workiz_zone : 'UTC';
            $j->start = $j->workiz_start ?? $j->native_start;
            $j->day = $j->start === null ? null : CarbonImmutable::parse($j->start, $j->zone)->setTimezone($zone)->toDateString();
            $j->techs = $techs[$j->id] ?? [];
            $j->photos = (int) $j->workiz_photos + (int) ($photos[$j->id] ?? 0);
            $j->inspected = isset($inspected[$j->id]);
            $j->total_minor = $j->workiz_start !== null ? (int) round(((float) $j->workiz_total) * 100) : (int) $j->amount_minor;
            $j->phone = strlen($d = substr((string) preg_replace('/\D/', '', (string) $j->phone), -10)) === 10 ? $d : null;
            $j->misses = [];

            return $j;
        })->filter(fn (object $j) => $j->day !== null && $j->day >= $from && $j->day <= $to)->values();
    }

    /**
     * Phone (last ten digits) => when each call or text went out to it (UTC), around the jobs' days:
     * Workiz's calls (its account's wall clock) and EzBizi's texts.
     *
     * @param  Collection<int, object>  $jobs
     * @return array<string, list<string>>
     */
    private function contactsOut(string $org, Collection $jobs): array
    {
        $phones = $jobs->pluck('phone')->filter()->unique()->values();
        if ($phones->isEmpty()) {
            return [];
        }
        [$first, $last] = [(string) $jobs->min('day'), (string) $jobs->max('day')];
        $range = [CarbonImmutable::parse($first)->subDay()->toDateString(), CarbonImmutable::parse($last)->addDays(2)->toDateString()];
        // A Workiz call is on its branch's Workiz clock, whichever job the branch's last one was.
        $zones = $jobs->pluck('workiz_zone', 'franchise_id')->all();
        $out = [];
        foreach (DB::table('workiz_calls')->where('organization_id', $org)->where('direction', 'outgoing')->whereBetween('called_at', $range)
            ->whereIn(DB::raw('right(to_number, 10)'), $phones)->get(['franchise_id', 'to_number', 'called_at']) as $c) {
            $out[substr((string) preg_replace('/\D/', '', (string) $c->to_number), -10)][] = CarbonImmutable::parse($c->called_at, $zones[$c->franchise_id] ?? 'America/New_York')->utc()->toDateTimeString();
        }
        foreach (DB::table('phone_messages')->where('organization_id', $org)->where('direction', 'outbound')->whereBetween('created_at', $range)
            ->whereIn(DB::raw('right(to_e164, 10)'), $phones)->get(['to_e164', 'created_at']) as $m) {
            $out[substr((string) preg_replace('/\D/', '', (string) $m->to_e164), -10)][] = (string) $m->created_at;
        }

        return $out;
    }

    /**
     * Complaints raised about each job: a call complaint or bad rating whose lead is the job's, or a bad review tied to the job.
     *
     * @param  Collection<int, object>  $jobs
     * @return array<string, int>
     */
    private function complaints(string $org, Collection $jobs): array
    {
        $ids = $jobs->pluck('id')->all();

        return DB::table('complaints as c')->where('c.organization_id', $org)
            ->leftJoin('leads as l', fn ($on) => $on->on('l.id', '=', 'c.source_id')->where('c.source_type', 'lead'))
            ->leftJoin('reviews as r', fn ($on) => $on->on('r.id', '=', 'c.source_id')->where('c.source_type', 'review'))
            ->where(fn ($w) => $w->whereIn('l.job_id', $ids)->orWhereIn('r.job_id', $ids))
            ->selectRaw('coalesce(l.job_id, r.job_id) as job_id, count(*) as n')->groupBy(DB::raw('coalesce(l.job_id, r.job_id)'))
            ->pluck('n', 'job_id')->map(fn ($n) => (int) $n)->all();
    }

    private function jobRow(object $j): array
    {
        return ['job_id' => $j->id, 'number' => $j->number, 'workiz_number' => $j->serial_id === null ? null : (string) $j->serial_id,
            'start' => $j->start, 'closed_at' => $j->closed_at, 'client' => $j->client, 'job_type' => $j->job_type, 'photos' => $j->photos,
            'techs' => $j->techs, 'misses' => $j->misses];
    }
}
