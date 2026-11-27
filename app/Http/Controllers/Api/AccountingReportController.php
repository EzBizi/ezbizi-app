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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/accounting?from&to[&issue[]&q&offset&limit]
 *
 * Shoval, 2026-11-18: "Accounting issues weekly report". The money that does
 * not add up, one row per problem, for the dates (a week by default on the
 * screen):
 *  - done_unpaid: a job closed Done in the dates that still owes money;
 *  - done_zero: a job closed Done in the dates for $0 (Workiz's $0 copy of a
 *    finished job is "done pending approval", so it is not one);
 *  - invoice_overdue: an invoice still owing more than N days after it was
 *    made, as of the last day (Settings › Lists › Accounting rules, 30);
 *  - paid_cancelled: a job that is cancelled, paid in the dates, whose
 *    payments less refunds still come to more than nothing;
 *  - payment_deleted: a payment deleted in Workiz, dated in the dates.
 * Workiz's jobs, invoices and payments, and EzBizi's own invoices.
 */
class AccountingReportController extends Controller
{
    public const LIST = 'reports.accounting_rules';

    public const ISSUES = ['done_unpaid', 'done_zero', 'invoice_overdue', 'paid_cancelled', 'payment_deleted'];

    public function show(Request $request, BusinessLists $lists): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'issue' => ['nullable', 'array'], 'issue.*' => ['string', 'in:'.implode(',', self::ISSUES)],
            'q' => ['nullable', 'string', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        abort_if(CarbonImmutable::parse($q['from'])->diffInDays(CarbonImmutable::parse($q['to'])) > 92, 422, 'Choose a range of three months or less.');
        $org = app(TenantContext::class)->idOrFail();
        $view = ReportView::franchiseIds();
        // The last row for the rule wins: a business that adds its own number overrides the default.
        $days = (int) (collect($lists->rows(self::LIST))->filter(fn (array $r) => ($r['value']['rule'] ?? $r['key']) === 'invoice_days')->last()['value']['value'] ?? 30);
        [$from, $to] = [$q['from'].' 00:00:00', $q['to'].' 23:59:59'];
        $overdueBefore = CarbonImmutable::parse($q['to'])->subDays($days)->toDateString();
        $num = fn (string $json, string $key) => "cast(coalesce(nullif(json_unquote(json_extract({$json}, '$.{$key}')), ''), 0) as decimal(12,2))";
        $client = "trim(concat_ws(' ', json_unquote(json_extract(j.payload, '$.FirstName')), json_unquote(json_extract(j.payload, '$.LastName'))))";
        $jobs = fn () => DB::table('workiz_jobs as j')->where('j.organization_id', $org)->when($view, fn ($w, $ids) => $w->whereIn('j.franchise_id', $ids));

        $rows = collect();
        $add = fn (string $issue, Collection $found) => $rows->push(...$found->map(fn (object $r) => [
            'issue' => $issue, 'date' => (string) $r->at, 'amount_minor' => (int) round(((float) $r->amount) * 100),
            'job_number' => $r->job_number === null ? null : (string) $r->job_number, 'job_id' => $r->job_id, 'client' => $r->client ?: null,
            'franchise_id' => $r->franchise_id, 'detail' => $r->detail ?? null,
        ])->all());

        $done = $jobs()->where('j.status', 'Done')->whereBetween('j.closed_at', [$from, $to]);
        $add('done_unpaid', (clone $done)->whereRaw($num('j.payload', 'JobAmountDue').' > 0')
            ->get(['j.closed_at as at', DB::raw($num('j.payload', 'JobAmountDue').' as amount'), 'j.serial_id as job_number', 'j.job_id', DB::raw("{$client} as client"), 'j.franchise_id']));
        $add('done_zero', (clone $done)->whereRaw($num('j.payload', 'JobTotalPrice').' = 0')
            ->get(['j.closed_at as at', DB::raw('0 as amount'), 'j.serial_id as job_number', 'j.job_id', DB::raw("{$client} as client"), 'j.franchise_id']));

        $add('invoice_overdue', DB::table('workiz_records as r')->where('r.organization_id', $org)->where('r.type', 'invoice')
            ->when($view, fn ($w, $ids) => $w->whereIn('r.franchise_id', $ids))->where('r.amount_due', '>', 0)->where('r.starts_at', '<', $overdueBefore)
            ->leftJoin('workiz_jobs as j', 'j.v2_id', '=', 'r.job_remote_id')
            ->get(['r.starts_at as at', 'r.amount_due as amount', 'j.serial_id as job_number', 'r.job_id', DB::raw("{$client} as client"), 'r.franchise_id',
                DB::raw("concat('Invoice ', coalesce(json_unquote(json_extract(r.payload, '$.serialId')), r.remote_id), coalesce(concat(' · job ', j.status), '')) as detail")]));
        // EzBizi's own invoices: past due on the last day, not paid or voided.
        $add('invoice_overdue', DB::table('invoices as i')->where('i.organization_id', $org)->when($view, fn ($w, $ids) => $w->whereIn('i.franchise_id', $ids))
            ->whereNull('i.paid_at')->whereNull('i.voided_at')->whereNotNull('i.issued_on')->where('i.issued_on', '<', $overdueBefore)
            ->get(['i.issued_on as at', DB::raw('i.total_cents / 100 as amount'), DB::raw('null as job_number'), DB::raw('null as job_id'),
                'i.bill_to_name as client', 'i.franchise_id', DB::raw("concat('Invoice ', i.number) as detail")]));

        $payments = DB::table('workiz_records as p')->where('p.organization_id', $org)->where('p.type', 'payment')
            ->when($view, fn ($w, $ids) => $w->whereIn('p.franchise_id', $ids))->whereBetween('p.starts_at', [$from, $to])
            ->leftJoin('workiz_jobs as j', 'j.v2_id', '=', 'p.job_remote_id');
        $cols = ['p.starts_at as at', 'p.amount', 'j.serial_id as job_number', 'p.job_id', DB::raw("{$client} as client"), 'p.franchise_id',
            DB::raw("json_unquote(json_extract(p.payload, '$.type')) as detail")];
        // A cancelled job still holding money: its payments, refunds taken off, come to more than nothing.
        $kept = DB::table('workiz_records')->where('organization_id', $org)->where('type', 'payment')->where('status', '!=', 'deleted')
            ->groupBy('job_remote_id')->selectRaw('job_remote_id, sum(amount) as net');
        $add('paid_cancelled', (clone $payments)->where('p.status', '!=', 'deleted')->whereIn('j.status', ['Canceled', 'Cancelled'])
            ->joinSub($kept, 'k', 'k.job_remote_id', '=', 'p.job_remote_id')->where('k.net', '>', 0)
            ->get(['p.starts_at as at', 'k.net as amount', 'j.serial_id as job_number', 'p.job_id', DB::raw("{$client} as client"), 'p.franchise_id',
                DB::raw("'kept after cancelling' as detail")])->unique('job_number')->values());
        $add('payment_deleted', (clone $payments)->where('p.status', 'deleted')->get($cols));

        $branches = Franchise::query()->withoutGlobalScopes()->whereIn('id', $rows->pluck('franchise_id')->unique()->filter()->all())->get()
            ->mapWithKeys(fn (Franchise $f) => [$f->getKey() => BranchName::of($f)]);
        $rows = $rows->map(fn (array $r) => $r + ['branch' => $branches[$r['franchise_id']] ?? null])->sortByDesc('date')->values();

        $want = $q['issue'] ?? [];
        $needle = mb_strtolower(trim((string) ($q['q'] ?? '')));
        $shown = $rows->filter(fn (array $r) => $want === [] || in_array($r['issue'], $want, true))
            ->filter(fn (array $r) => $needle === '' || str_contains(mb_strtolower(implode(' ', [$r['job_number'], $r['client'], $r['branch'], $r['detail']])), $needle))
            ->values();

        return response()->json(['data' => [
            'rows' => $shown->slice((int) ($q['offset'] ?? 0), (int) ($q['limit'] ?? 200))->values(),
            'count' => $shown->count(),
            'invoice_days' => $days,
            // Every issue, whatever is filtered: [count, amount].
            'figures' => collect(self::ISSUES)->mapWithKeys(fn (string $i) => [$i => [
                'count' => $rows->where('issue', $i)->count(), 'amount_minor' => $rows->where('issue', $i)->sum('amount_minor')]]),
        ]]);
    }
}
