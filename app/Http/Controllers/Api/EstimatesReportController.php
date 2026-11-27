<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Support\Reports\ReportView;
use App\Support\Reports\Texts\BranchName;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/estimates?from&to[&state[]&q&offset&limit]
 *
 * Shoval, 2026-11-18: "Estimate report (open Estimate amount, total value,
 * client reposted to texts, no answer amount, approved, declined, amount of
 * cancellation for no answer and answered)". The estimates created in the
 * dates from both stores (Jobs, 11-20): EzBizi's own `estimates` and Workiz's
 * (workiz_records type estimate), a native row standing for the Workiz one its
 * external_ref names. Each status is read as one of five states.
 *
 * Texted and answered are DERIVED (nothing ties a message to an estimate):
 * texted is a text from us to the client's phone after the estimate was sent;
 * answered is a text or call in from that phone after the first such text
 * (EzBizi's texts, Workiz's calls, and the texts and calls Leads holds).
 * Closed with no answer is a declined or archived estimate that was texted and
 * never answered. A native row with no issue date that came in by import has
 * no date of its own and is left out (A-1 Tools' archive).
 */
class EstimatesReportController extends Controller
{
    /** Each store's status, as one state. */
    private const STATES = [
        'draft' => 'draft', 'unsent' => 'draft',
        'sent' => 'open', 'pending' => 'open',
        'accepted' => 'approved', 'won' => 'approved', 'approved' => 'approved',
        'declined' => 'declined',
        'archived' => 'archived',
    ];

    public function show(Request $request): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'state' => ['nullable', 'array'], 'state.*' => ['string', 'in:draft,open,approved,declined,archived'],
            'q' => ['nullable', 'string', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        abort_if(CarbonImmutable::parse($q['from'])->diffInDays(CarbonImmutable::parse($q['to'])) > 92, 422, 'Choose a range of three months or less.');
        $org = app(TenantContext::class)->idOrFail();
        $view = ReportView::franchiseIds();
        $zone = ReportView::zone();
        [$from, $to] = [$q['from'].' 00:00:00', $q['to'].' 23:59:59'];

        $workiz = DB::table('workiz_records as r')->where('r.organization_id', $org)->where('r.type', 'estimate')
            ->when($view, fn ($w, $ids) => $w->whereIn('r.franchise_id', $ids))
            ->whereRaw("json_unquote(json_extract(r.payload, '$.created')) between ? and ?", [$from, $to])
            ->leftJoin('workiz_jobs as j', 'j.v2_id', '=', DB::raw("json_unquote(json_extract(r.payload, '$.jobId'))"))
            ->get(['r.remote_id', 'r.franchise_id', 'r.payload', 'j.serial_id as job_number', 'j.job_id',
                DB::raw("json_unquote(json_extract(j.payload, '$.Phone')) as phone"),
                DB::raw("trim(concat_ws(' ', json_unquote(json_extract(j.payload, '$.FirstName')), json_unquote(json_extract(j.payload, '$.LastName')))) as client")])
            ->map(function (object $r) {
                $p = json_decode((string) $r->payload, true) ?: [];

                return [
                    'id' => (string) $r->remote_id, 'number' => (string) ($p['serialId'] ?? $r->remote_id), 'store' => 'workiz',
                    'franchise_id' => $r->franchise_id, 'created_at' => $p['created'] ?? null, 'sent_at' => $p['sentDate'] ?? null,
                    'viewed_at' => $p['lastViewedDate'] ?? null, 'status' => (string) ($p['status'] ?? ''),
                    'reason' => trim((string) ($p['declineReason'] ?? '')) ?: null, 'value_minor' => (int) round(((float) ($p['total'] ?? 0)) * 100),
                    'client' => $r->client ?: null, 'phone' => $r->phone ?: null, 'job_number' => $r->job_number === null ? null : (string) $r->job_number, 'job_id' => $r->job_id,
                ];
            });

        // EzBizi's own, by its issue date; one already counted from Workiz is that one.
        $native = DB::table('estimates as e')->where('e.organization_id', $org)
            ->when($view, fn ($w, $ids) => $w->whereIn('e.franchise_id', $ids))
            ->where(fn ($w) => $w->whereBetween('e.issued_on', [$q['from'], $q['to']])
                ->orWhere(fn ($n) => $n->whereNull('e.issued_on')->whereNull('e.import_batch')
                    ->whereBetween('e.created_at', [CarbonImmutable::parse($from, $zone)->utc(), CarbonImmutable::parse($to, $zone)->utc()])))
            ->leftJoin('customers as c', 'c.id', '=', 'e.customer_id')->leftJoin('jobs as jb', 'jb.id', '=', 'e.job_id')
            ->get(['e.id', 'e.number', 'e.franchise_id', 'e.status', 'e.issued_on', 'e.created_at', 'e.total_minor', 'e.external_ref', 'e.job_id',
                'c.name as client', 'c.phone', 'jb.number as job_number'])
            ->reject(fn (object $e) => $e->external_ref !== null && $workiz->contains('id', $e->external_ref))
            ->map(fn (object $e) => [
                'id' => (string) $e->id, 'number' => (string) $e->number, 'store' => 'ezbizi', 'franchise_id' => $e->franchise_id,
                'created_at' => $e->issued_on !== null ? $e->issued_on.' 00:00:00'
                    : CarbonImmutable::parse($e->created_at, 'UTC')->setTimezone($zone)->toDateTimeString(),
                'sent_at' => $e->status === 'draft' ? null : $e->issued_on, 'viewed_at' => null, 'status' => (string) $e->status, 'reason' => null,
                'value_minor' => (int) $e->total_minor, 'client' => $e->client, 'phone' => $e->phone, 'job_number' => $e->job_number, 'job_id' => $e->job_id,
            ]);

        $all = $workiz->concat($native)->values();
        $answers = $this->answers($org, $all, $zone);
        $branches = Franchise::query()->withoutGlobalScopes()->whereIn('id', $all->pluck('franchise_id')->unique()->filter()->all())->get()
            ->mapWithKeys(fn (Franchise $f) => [$f->getKey() => BranchName::of($f)]);
        $rows = $all->map(function (array $e) use ($answers, $branches) {
            $state = self::STATES[mb_strtolower($e['status'])] ?? 'open';
            [$texted, $answered] = $answers[$e['id']] ?? [null, null];

            return $e + [
                'state' => $state, 'branch' => $branches[$e['franchise_id']] ?? null, 'texted_at' => $texted, 'answered_at' => $answered,
                'no_answer' => $texted !== null && $answered === null,
            ];
        })->sortByDesc('created_at')->values();

        $want = $q['state'] ?? [];
        $needle = mb_strtolower(trim((string) ($q['q'] ?? '')));
        $shown = $rows->filter(fn (array $r) => $want === [] || in_array($r['state'], $want, true))
            ->filter(fn (array $r) => $needle === '' || str_contains(mb_strtolower(implode(' ', [$r['number'], $r['client'], $r['phone'],
                $r['branch'], $r['status'], $r['reason'], $r['job_number']])), $needle))->values();
        $sum = fn (Collection $c) => ['count' => $c->count(), 'value_minor' => $c->sum('value_minor')];
        $closed = $rows->whereIn('state', ['declined', 'archived'])->whereNotNull('texted_at');

        return response()->json(['data' => [
            'rows' => $shown->slice((int) ($q['offset'] ?? 0), (int) ($q['limit'] ?? 200))->values(),
            'count' => $shown->count(),
            'value_minor' => $shown->sum('value_minor'),
            // The figures are the whole range's; the filters narrow only the rows.
            'figures' => [
                'total' => $sum($rows),
                'open' => $sum($rows->where('state', 'open')),
                'approved' => $sum($rows->where('state', 'approved')),
                'declined' => $sum($rows->where('state', 'declined')),
                'texted_no_answer' => $sum($rows->where('state', 'open')->where('no_answer', true)),
                'closed_no_answer' => $sum($closed->where('no_answer', true)),
                'closed_answered' => $sum($closed->where('no_answer', false)),
            ],
            'states' => $rows->countBy('state')->map(fn (int $n, string $s) => ['value' => $s, 'count' => $n])->values(),
            'reasons' => $rows->whereNotNull('reason')->countBy('reason')->sortDesc()->map(fn (int $n, string $s) => ['value' => $s, 'count' => $n])->values(),
        ]]);
    }

    /**
     * Estimate id => [first text to the client after it was sent, first text or call in after that].
     * Phones compared by their last ten digits, as each store writes them differently.
     *
     * @param  Collection<int, array<string, mixed>>  $estimates
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    private function answers(string $org, Collection $estimates, string $zone): array
    {
        $digits = fn (?string $p) => substr((string) preg_replace('/\D/', '', (string) $p), -10);
        $phones = $estimates->map(fn (array $e) => $digits($e['phone']))->filter(fn (string $d) => strlen($d) === 10)->unique()->values();
        if ($phones->isEmpty()) {
            return [];
        }
        $since = CarbonImmutable::parse((string) $estimates->pluck('created_at')->filter()->min())->subDay();
        // Texts are stored in UTC; the estimates and Workiz's calls are wall clock.
        $texts = DB::table('phone_messages')->where('organization_id', $org)->where('created_at', '>=', $since->toDateTimeString())
            ->where(fn ($w) => $w->whereIn(DB::raw('right(to_e164, 10)'), $phones)->orWhereIn(DB::raw('right(from_e164, 10)'), $phones))
            ->orderBy('created_at')->get(['direction', 'from_e164', 'to_e164', 'created_at'])
            ->each(fn (object $m) => $m->created_at = CarbonImmutable::parse($m->created_at, 'UTC')->setTimezone($zone)->toDateTimeString());
        $out = $texts->where('direction', 'outbound')->groupBy(fn (object $m) => $digits($m->to_e164));
        $in = $texts->where('direction', 'inbound')->map(fn (object $m) => [$digits($m->from_e164), (string) $m->created_at])
            ->concat(DB::table('workiz_calls')->where('organization_id', $org)->where('direction', 'inbound')->where('called_at', '>=', $since->toDateTimeString())
                ->whereIn(DB::raw('right(from_number, 10)'), $phones)->get(['from_number', 'called_at'])
                ->map(fn (object $c) => [$digits($c->from_number), (string) $c->called_at]))
            // A text or call the client sent through Leads (A1 Leads' SMS, calls), stored in UTC.
            ->concat(DB::table('lead_conversations as lc')->join('leads as l', 'l.id', '=', 'lc.lead_id')->where('lc.organization_id', $org)
                ->where('lc.participant', 'CONSUMER')->whereIn('lc.channel', ['SMS', 'PHONE_CALL', 'MESSAGE'])
                ->where('lc.occurred_at', '>=', $since->utc()->toDateTimeString())->whereIn(DB::raw('right(l.phone_digits, 10)'), $phones)
                ->get(['l.phone_digits', 'lc.occurred_at'])
                ->map(fn (object $c) => [$digits($c->phone_digits), CarbonImmutable::parse($c->occurred_at, 'UTC')->setTimezone($zone)->toDateTimeString()]))
            ->groupBy(0);

        $result = [];
        foreach ($estimates as $e) {
            $phone = $digits($e['phone']);
            $after = (string) ($e['sent_at'] ?? $e['created_at']);
            $texted = collect($out[$phone] ?? [])->first(fn (object $m) => (string) $m->created_at >= $after)?->created_at;
            $answered = $texted === null ? null : collect($in[$phone] ?? [])->sortBy(1)->first(fn (array $i) => $i[1] > (string) $texted)[1] ?? null;
            $result[$e['id']] = [$texted === null ? null : (string) $texted, $answered];
        }

        return $result;
    }
}
