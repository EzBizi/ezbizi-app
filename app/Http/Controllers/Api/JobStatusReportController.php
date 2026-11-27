<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Support\Reports\ReportView;
use App\Support\Reports\Texts\BranchName;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/cancelled?from&to[&reason[]&source[]&tech[]&q&offset&limit]
 * GET /api/v1/reports/pending[?reason[]&source[]&tech[]&q&offset&limit]
 *
 * Shoval, 2026-11-18: "Cancelled jobs report", "Pending jobs report (need to
 * build a list)". EzBizi's own jobs, so every tenant has them, Workiz or not;
 * which statuses are cancelled or pending is the business's list (Settings ›
 * Lists). Cancelled: the jobs created in the dates that are cancelled now,
 * against every job created in them. Pending: every job pending now, oldest
 * first; dates do not apply.
 *
 * Created is Workiz's booked time for a job that came from Workiz (an imported
 * row's created_at is the import), on Workiz's wall clock; a job made in
 * EzBizi is read on the organization's clock. Since is its last status change.
 * Filters, counts and value cover the whole set; the rows come a page at a time.
 */
class JobStatusReportController extends Controller
{
    public const LISTS = ['cancelled' => 'reports.cancelled_statuses', 'pending' => 'reports.pending_statuses'];

    public function show(Request $request, BusinessLists $lists, string $kind): JsonResponse
    {
        $cancelled = $kind === 'cancelled';
        $q = $request->validate([
            'from' => [$cancelled ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'to' => [$cancelled ? 'required' : 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reason' => ['nullable', 'array'], 'reason.*' => ['string', 'max:191'],
            'source' => ['nullable', 'array'], 'source.*' => ['string', 'max:191'],
            'tech' => ['nullable', 'array'], 'tech.*' => ['string', 'max:191'],
            'q' => ['nullable', 'string', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        abort_if($cancelled && CarbonImmutable::parse($q['from'])->diffInDays(CarbonImmutable::parse($q['to'])) > 92, 422,
            'Choose a range of three months or less.');

        $statuses = array_values(array_filter(array_map(fn (array $r) => mb_strtolower(trim((string) ($r['value']['status'] ?? $r['key']))),
            $lists->rows(self::LISTS[$kind]))));
        $zone = ReportView::zone();
        $base = DB::table('jobs as j')->where('j.organization_id', app(TenantContext::class)->idOrFail())->whereNull('j.deleted_at')
            ->when(ReportView::franchiseIds(), fn ($w, $ids) => $w->whereIn('j.franchise_id', $ids))
            ->leftJoin('workiz_jobs as w', 'w.job_id', '=', 'j.id');
        if ($cancelled) {
            // A day either side in SQL, the exact day in PHP: the two clocks differ by hours.
            $base->whereBetween(DB::raw('coalesce(w.booked_at, j.created_at)'),
                [CarbonImmutable::parse($q['from'])->subDay()->toDateString(), CarbonImmutable::parse($q['to'])->addDays(2)->toDateString()]);
        }
        $created = fn (object $j) => $j->booked_at ?? CarbonImmutable::parse($j->created_at, 'UTC')->setTimezone($zone)->toDateTimeString();
        $inDates = fn (object $j) => ! $cancelled || (substr($created($j), 0, 10) >= $q['from'] && substr($created($j), 0, 10) <= $q['to']);

        $jobs = (clone $base)->leftJoin('customers as cu', 'cu.id', '=', 'j.customer_id')
            ->whereIn(DB::raw('lower(trim(j.status))'), $statuses === [] ? [''] : $statuses)
            ->get(['j.id', 'j.number', 'j.franchise_id', 'j.status', 'j.sub_status', 'j.job_source', 'j.job_type', 'j.amount_minor',
                'j.created_by_label', 'j.created_at', 'j.updated_at', 'w.booked_at', 'w.last_status_update', 'w.serial_id', 'cu.name as client', 'cu.phone'])
            ->filter($inDates)->values();
        $all = $cancelled ? (clone $base)->get(['w.booked_at', 'j.created_at'])->filter($inDates)->count() : null;

        $techs = DB::table('job_technicians as jt')->join('scheduling_technicians as t', 't.id', '=', 'jt.technician_id')
            ->whereIn('jt.job_id', $jobs->pluck('id')->all())->orderBy('t.name')->get(['jt.job_id', 't.name'])
            ->groupBy('job_id')->map(fn ($r) => $r->pluck('name')->unique()->values()->all());
        $branches = Franchise::query()->withoutGlobalScopes()->whereIn('id', $jobs->pluck('franchise_id')->unique()->filter()->all())->get()
            ->mapWithKeys(fn (Franchise $f) => [$f->getKey() => BranchName::of($f)]);
        $now = CarbonImmutable::now($zone);
        $rows = $jobs->map(fn (object $j) => [
            'job_id' => $j->id,
            'number' => $j->number,
            'workiz_number' => $j->serial_id === null ? null : (string) $j->serial_id,
            'created_at' => $at = $created($j),
            'since' => $j->last_status_update ?? CarbonImmutable::parse($j->updated_at, 'UTC')->setTimezone($zone)->toDateTimeString(),
            'age_days' => (int) CarbonImmutable::parse($at, $zone)->diffInDays($now),
            'client' => $j->client ?: null,
            'phone' => $j->phone ?: null,
            'branch' => $branches[$j->franchise_id] ?? null,
            'source' => trim((string) $j->job_source) ?: null,
            'job_type' => trim((string) $j->job_type) ?: null,
            'status' => $j->status,
            'reason' => trim((string) $j->sub_status) ?: null,
            'techs' => $techs[$j->id] ?? [],
            'value_minor' => (int) $j->amount_minor,
            'created_by' => $j->created_by_label,
        ])->sortBy('created_at', SORT_REGULAR, $cancelled)->values();

        $pick = fn (array $want, string $key) => fn (array $r) => $want === []
            || ($key === 'techs' ? array_intersect($want, $r['techs'] ?: ['none']) !== [] : in_array($r[$key] ?? 'none', $want, true));
        $needle = mb_strtolower(trim((string) ($q['q'] ?? '')));
        $found = fn (array $r) => $needle === '' || str_contains(mb_strtolower(implode(' ', [$r['number'], $r['workiz_number'], $r['client'], $r['phone'],
            $r['branch'], $r['source'], $r['job_type'], $r['status'], $r['reason'], implode(' ', $r['techs']), $r['created_by']])), $needle);
        $shown = $rows->filter($pick($q['reason'] ?? [], 'reason'))->filter($pick($q['source'] ?? [], 'source'))
            ->filter($pick($q['tech'] ?? [], 'techs'))->filter($found)->values();
        $counted = fn (string $key) => $rows->flatMap(fn (array $r) => $key === 'techs' ? ($r['techs'] ?: ['none']) : [$r[$key] ?? 'none'])
            ->countBy()->sortDesc()->map(fn (int $n, string $value) => ['value' => $value, 'count' => $n])->values();

        return response()->json(['data' => [
            'rows' => $shown->slice((int) ($q['offset'] ?? 0), (int) ($q['limit'] ?? 200))->values(),
            'count' => $shown->count(),
            'value_minor' => $shown->sum('value_minor'),
            // Cancelled: every job in these statuses and every job created, the dates' rate.
            'all' => $rows->count(),
            'created' => $all,
            'statuses' => $statuses,
            'reasons' => $counted('reason'),
            'sources' => $counted('source'),
            'techs' => $counted('techs'),
        ]]);
    }
}
