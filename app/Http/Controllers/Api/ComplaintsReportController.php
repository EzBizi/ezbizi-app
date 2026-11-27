<?php

namespace App\Http\Controllers\Api;

use App\Models\Complaint;
use App\Models\Franchise;
use App\Support\Reports\ReportView;
use App\Support\Reports\Texts\BranchName;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/complaints?from&to[&status[]&kind[]&q&offset&limit]
 *
 * Shoval, 2026-11-18: "Complaint report and KPI - up to 48 resulting, up to 24
 * hours to call". The complaints raised in the dates, each with when the
 * customer was first contacted and when it was closed (solved or not
 * solvable, both set solved_at). The hours are the business's (Settings ›
 * Lists › Complaint targets, defaults 24 and 48).
 *
 * First contact is DERIVED (Customer Experience, 11-20: nothing records it):
 * the first call or text out to the customer after the complaint was raised
 * (Workiz's calls, EzBizi's texts, and Leads' calls and texts for the lead it
 * came from); with none, the first note on it. The customer is the lead's for
 * a call complaint or bad rating, the review's customer for a bad review.
 *
 * A complaint younger than a target that has not met it yet is not counted
 * against it: it is not late yet.
 */
class ComplaintsReportController extends Controller
{
    public const LIST = 'reports.complaint_targets';

    public function show(Request $request, BusinessLists $lists): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'array'], 'status.*' => ['string', 'in:'.implode(',', Complaint::STATUSES)],
            'kind' => ['nullable', 'array'], 'kind.*' => ['string', 'in:'.implode(',', Complaint::KINDS)],
            'q' => ['nullable', 'string', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        abort_if(CarbonImmutable::parse($q['from'])->diffInDays(CarbonImmutable::parse($q['to'])) > 92, 422, 'Choose a range of three months or less.');
        $org = app(TenantContext::class)->idOrFail();
        $zone = ReportView::zone();
        $targets = collect($lists->rows(self::LIST))->mapWithKeys(fn (array $r) => [(string) ($r['value']['target'] ?? $r['key']) => (float) ($r['value']['hours'] ?? 0)]);
        [$contactHours, $solveHours] = [$targets['contact'] ?? 24.0, $targets['solve'] ?? 48.0];

        $complaints = DB::table('complaints as c')->where('c.organization_id', $org)
            // A complaint with no branch is the organization's: only its view shows it.
            ->when(ReportView::franchiseIds(), fn ($w, $ids) => $w->whereIn('c.franchise_id', $ids))
            ->whereBetween('c.raised_at', [CarbonImmutable::parse($q['from'], $zone)->utc(), CarbonImmutable::parse($q['to'], $zone)->endOfDay()->utc()])
            ->leftJoin('leads as l', fn ($on) => $on->on('l.id', '=', 'c.source_id')->where('c.source_type', 'lead'))
            ->leftJoin('reviews as r', fn ($on) => $on->on('r.id', '=', 'c.source_id')->where('c.source_type', 'review'))
            ->leftJoin('customers as cu', 'cu.id', '=', 'r.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.owner_user_id')
            ->orderByDesc('c.raised_at')
            ->get(['c.id', 'c.kind', 'c.status', 'c.franchise_id', 'c.source_type', 'c.source_id', 'c.rating', 'c.raised_at', 'c.solved_at',
                'u.name as owner', DB::raw('coalesce(l.contact_name, r.reviewer_name, cu.name) as customer'), DB::raw('coalesce(l.phone, cu.phone) as phone')]);

        $contacts = $this->contacts($org, $complaints);
        $branches = Franchise::query()->withoutGlobalScopes()->whereIn('id', $complaints->pluck('franchise_id')->unique()->filter()->all())->get()
            ->mapWithKeys(fn (Franchise $f) => [$f->getKey() => BranchName::of($f)]);
        $now = CarbonImmutable::now('UTC');
        $hours = fn (?string $from, ?string $to) => $from === null || $to === null ? null
            : round(CarbonImmutable::parse($from, 'UTC')->diffInMinutes(CarbonImmutable::parse($to, 'UTC')) / 60, 1);
        // Met, missed, or null while there is still time.
        $kept = fn (?float $took, string $raised, float $target) => $took !== null ? $took <= $target
            : (CarbonImmutable::parse($raised, 'UTC')->addMinutes((int) round($target * 60))->lessThan($now) ? false : null);

        $rows = $complaints->map(function (object $c) use ($contacts, $branches, $hours, $kept, $contactHours, $solveHours, $zone) {
            [$at, $how] = $contacts[$c->id] ?? [null, null];
            $toContact = $hours($c->raised_at, $at);
            $toSolve = $hours($c->raised_at, $c->solved_at);
            $local = fn (?string $utc) => $utc === null ? null : CarbonImmutable::parse($utc, 'UTC')->setTimezone($zone)->toDateTimeString();

            return [
                'id' => $c->id, 'kind' => $c->kind, 'status' => $c->status, 'open' => in_array($c->status, Complaint::OPEN, true),
                'raised_at' => $local($c->raised_at), 'contacted_at' => $local($at), 'contacted_by' => $how, 'hours_to_contact' => $toContact,
                'solved_at' => $local($c->solved_at), 'hours_to_solve' => $toSolve,
                'contact_kept' => $kept($toContact, $c->raised_at, $contactHours), 'solve_kept' => $kept($toSolve, $c->raised_at, $solveHours),
                'customer' => $c->customer ?: null, 'phone' => $c->phone ?: null, 'branch' => $branches[$c->franchise_id] ?? null,
                'owner' => $c->owner, 'rating' => $c->rating === null ? null : (int) $c->rating, 'source_type' => $c->source_type, 'source_id' => $c->source_id,
            ];
        });

        $want = fn (array $list, string $key) => fn (array $r) => $list === [] || in_array($r[$key], $list, true);
        $needle = mb_strtolower(trim((string) ($q['q'] ?? '')));
        $shown = $rows->filter($want($q['status'] ?? [], 'status'))->filter($want($q['kind'] ?? [], 'kind'))
            ->filter(fn (array $r) => $needle === '' || str_contains(mb_strtolower(implode(' ', [$r['customer'], $r['phone'], $r['branch'],
                $r['owner'], $r['kind'], $r['status']])), $needle))->values();
        $rate = fn (Collection $c, string $key) => ($judged = $c->whereNotNull($key))->isEmpty() ? null
            : round($judged->where($key, true)->count() * 100 / $judged->count());

        return response()->json(['data' => [
            'rows' => $shown->slice((int) ($q['offset'] ?? 0), (int) ($q['limit'] ?? 200))->values(),
            'count' => $shown->count(),
            'targets' => ['contact_hours' => $contactHours, 'solve_hours' => $solveHours],
            // The whole range's; the filters narrow only the rows.
            'figures' => [
                'total' => $rows->count(),
                'open' => $rows->where('open', true)->count(),
                'closed' => $rows->where('open', false)->count(),
                'contact_kept_percent' => $rate($rows, 'contact_kept'),
                'solve_kept_percent' => $rate($rows, 'solve_kept'),
                'avg_hours_to_contact' => ($h = $rows->whereNotNull('hours_to_contact'))->isEmpty() ? null : round($h->avg('hours_to_contact'), 1),
                'avg_hours_to_solve' => ($h = $rows->whereNotNull('hours_to_solve'))->isEmpty() ? null : round($h->avg('hours_to_solve'), 1),
                'not_contacted' => $rows->whereNull('contacted_at')->count(),
            ],
            'kinds' => $rows->countBy('kind')->map(fn (int $n, string $k) => ['value' => $k, 'count' => $n])->values(),
            'statuses' => $rows->countBy('status')->map(fn (int $n, string $k) => ['value' => $k, 'count' => $n])->values(),
        ]]);
    }

    /**
     * Complaint id => [first contact after it was raised (UTC), 'call'|'text'|'note'].
     *
     * @param  Collection<int, object>  $complaints
     * @return array<string, array{0: string, 1: string}>
     */
    private function contacts(string $org, Collection $complaints): array
    {
        if ($complaints->isEmpty()) {
            return [];
        }
        $digits = fn (?string $p) => substr((string) preg_replace('/\D/', '', (string) $p), -10);
        $phones = $complaints->map(fn (object $c) => $digits($c->phone))->filter(fn (string $d) => strlen($d) === 10)->unique()->values();
        $since = (string) $complaints->min('raised_at');
        $found = collect();

        if ($phones->isNotEmpty()) {
            // Workiz's calls are on each branch's Workiz clock.
            $zones = [];
            $found = $found->concat(DB::table('workiz_calls')->where('organization_id', $org)->where('direction', 'outgoing')
                ->where('called_at', '>=', CarbonImmutable::parse($since)->subDay()->toDateTimeString())
                ->whereIn(DB::raw('right(to_number, 10)'), $phones)->get(['franchise_id', 'to_number', 'called_at'])
                ->map(function (object $c) use (&$zones, $digits) {
                    $zones[$c->franchise_id] ??= WorkizClock::zone(Franchise::query()->withoutGlobalScopes()->find($c->franchise_id));

                    return ['phone' => $digits($c->to_number), 'at' => CarbonImmutable::parse($c->called_at, $zones[$c->franchise_id])->utc()->toDateTimeString(), 'how' => 'call'];
                }))
                ->concat(DB::table('phone_messages')->where('organization_id', $org)->where('direction', 'outbound')->where('created_at', '>=', $since)
                    ->whereIn(DB::raw('right(to_e164, 10)'), $phones)->get(['to_e164', 'created_at'])
                    ->map(fn (object $m) => ['phone' => $digits($m->to_e164), 'at' => (string) $m->created_at, 'how' => 'text']));
        }
        // Leads' own calls and texts out, by the lead the complaint came from.
        $leads = DB::table('lead_conversations')->where('organization_id', $org)->where('participant', 'ADVERTISER')->where('is_automatic', false)
            ->whereIn('channel', ['PHONE_CALL', 'SMS', 'MESSAGE'])->where('occurred_at', '>=', $since)
            ->whereIn('lead_id', $complaints->where('source_type', 'lead')->pluck('source_id')->filter()->unique()->values())
            ->get(['lead_id', 'channel', 'occurred_at'])->groupBy('lead_id');
        $notes = DB::table('complaint_notes')->whereIn('complaint_id', $complaints->pluck('id'))->get(['complaint_id', 'created_at'])->groupBy('complaint_id');
        $byPhone = $found->groupBy('phone');

        $out = [];
        foreach ($complaints as $c) {
            $first = collect($byPhone[$digits($c->phone)] ?? [])
                ->concat(collect($c->source_type === 'lead' ? ($leads[$c->source_id] ?? []) : [])
                    ->map(fn (object $l) => ['at' => substr((string) $l->occurred_at, 0, 19), 'how' => $l->channel === 'PHONE_CALL' ? 'call' : 'text']))
                ->filter(fn (array $e) => $e['at'] >= (string) $c->raised_at)->sortBy('at')->first();
            $first ??= ($note = collect($notes[$c->id] ?? [])->sortBy('created_at')->first()) === null ? null : ['at' => (string) $note->created_at, 'how' => 'note'];
            if ($first !== null) {
                $out[$c->id] = [$first['at'], $first['how']];
            }
        }

        return $out;
    }
}
