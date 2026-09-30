<?php

namespace App\Http\Controllers\Api;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Leads\LeadReplies;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leads > Manage > Dashboard: how fast and how well enquiries are answered,
 * and by whom (Jose, 2026-09-30, layouts 7 + 10). Read-only, over exactly the
 * leads the list shows this reader (LeadsController::scopeToReader).
 *
 * Definitions (UI/UX's contract):
 *  - answerable: the customer wrote at least once, somewhere a reply can go
 *    - a call-only lead and a website form are not counted against anybody;
 *  - first reply: the first ADVERTISER message a PERSON sent after the
 *    customer's first message - automatic replies are counted apart;
 *  - people: credited through the `lead.replied` audit; a first reply with no
 *    audit row went out from Google, Thumbtack or A1 Tools ("outside").
 */
class LeadDashboardController extends Controller
{
    private const BUCKETS = [
        'under_5m' => 300, 'm5_10' => 600, 'm10_30' => 1800,
        'm30_60' => 3600, 'h1_4' => 14400, 'over_4h' => PHP_INT_MAX,
    ];

    public function __construct(private readonly LeadReplies $replies) {}

    /** GET /api/v1/leads/dashboard */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('leads.view'), Response::HTTP_FORBIDDEN, Refusal::permission('see the Leads dashboard')->message());

        $input = $request->validate([
            'period' => ['sometimes', 'in:today,7d,30d,month,custom'],
            'from' => ['required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'company_id' => ['sometimes', 'nullable', 'uuid'],
            'source' => ['sometimes', 'nullable', 'string', 'max:40'],
            'lead_type' => ['sometimes', 'nullable', 'in:PHONE_CALL,MESSAGE,BOOKING'],
        ]);

        $zone = (string) app(SettingsResolver::class)->get('timezone', $user->organization);
        $today = now($zone)->startOfDay();
        [$from, $to] = match ($input['period'] ?? '7d') {
            'today' => [$today, $today],
            '30d' => [$today->copy()->subDays(29), $today],
            'month' => [$today->copy()->startOfMonth(), $today],
            'custom' => [Carbon::parse($input['from'], $zone), Carbon::parse($input['to'], $zone)],
            default => [$today->copy()->subDays(6), $today],
        };
        $days = (int) $from->diffInDays($to) + 1;
        abort_if($days > 366, Response::HTTP_UNPROCESSABLE_ENTITY, 'Pick a range of a year or less.');

        $scoped = function () use ($user, $input) {
            $q = LeadsController::scopeToReader(Lead::query(), $user)->where('leads.is_test', false);
            if (! empty($input['company_id'])) {
                $q->where('leads.company_id', $input['company_id']);
            }
            if (! empty($input['source'])) {
                $q->where('leads.source', $input['source']);
            }
            if (! empty($input['lead_type'])) {
                $q->where('leads.lead_type', $input['lead_type']);
            }

            return $q;
        };

        $current = self::measure($scoped, $from, $to, $zone);
        $previous = self::measure($scoped, $from->copy()->subDays($days), $from->copy()->subDay(), $zone);

        // Waiting now: the list's overdue mark, over the last day - an
        // enquiry left since last week is a backlog, not somebody waiting.
        $waiting = $scoped()->whereIn('leads.source', Lead::INBOX_SOURCES)
            ->where('leads.status', Lead::STATUS_NEW)
            ->where('leads.last_contact_from', LeadConversation::FROM_CONSUMER)
            ->whereBetween('leads.last_contact_at', [now()->subDay(), now()->subMinutes(Lead::REPLY_OVERDUE_AFTER_MINUTES)])
            ->get()
            ->filter(fn (Lead $l) => $l->replyOverdue($this->replies->canReply($l)))
            ->count();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $current['totals'] + ['waiting_now' => $waiting],
            'previous' => $previous['totals'],
            'per_day' => $current['per_day'],
            'first_reply_buckets' => $current['buckets'],
            'people' => $current['people'],
            'automatic' => $current['automatic'],
            'outside' => $current['outside'],
        ]]);
    }

    /**
     * Also the daily digest's arithmetic (ezbizi:leads-daily-digest), so the
     * morning message and the dashboard cannot disagree.
     *
     * @return array{totals: array, per_day: array, buckets: array, people: array, automatic: array, outside: array}
     */
    public static function measure(callable $scoped, Carbon $from, Carbon $to, string $zone): array
    {
        $start = $from->copy()->startOfDay()->utc();
        $end = $to->copy()->endOfDay()->utc();

        $leads = $scoped()->whereBetween('leads.received_at', [$start, $end])
            ->get(['leads.id', 'leads.source', 'leads.lead_type', 'leads.status', 'leads.job_id', 'leads.received_at'])
            ->keyBy('id');
        $ids = $leads->keys()->all();

        /** @var Collection<string, Collection<int, object>> $threads */
        $threads = collect();
        $audits = collect();
        foreach (array_chunk($ids, 1000) as $chunk) {
            $threads = $threads->merge(DB::table('lead_conversations')->whereIn('lead_id', $chunk)
                ->whereIn('participant', [LeadConversation::FROM_CONSUMER, LeadConversation::FROM_ADVERTISER])
                ->orderBy('occurred_at')->get(['id', 'lead_id', 'participant', 'channel', 'occurred_at', 'is_automatic']));
            $audits = $audits->merge(DB::table('audit_events')->whereIn('target_id', $chunk)
                ->whereIn('action', ['lead.replied', 'lead.converted'])
                ->get(['action', 'target_id', 'actor_id', 'changes', 'occurred_at']));
        }
        $threads = $threads->groupBy('lead_id');

        // Who sent each reply, and who booked each lead.
        $senderOf = [];
        $bookedBy = [];
        foreach ($audits as $a) {
            if ($a->action === 'lead.replied') {
                $senderOf[(string) (json_decode((string) $a->changes, true)['conversation_id'] ?? '')] = $a->actor_id;
            } elseif ($a->actor_id !== null) {
                $bookedBy[$a->target_id] = $a->actor_id;
            }
        }

        $totals = ['leads' => $leads->count(), 'answerable' => 0, 'answered' => 0, 'first_reply_median_seconds' => null, 'within_10_min' => 0, 'booked' => 0];
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);
        $people = [];
        $blank = ['replies' => 0, 'leads_answered' => 0, 'waits' => [], 'within_10_min' => 0, 'booked' => 0];
        $automatic = ['replies' => 0, 'leads' => 0];
        $outside = ['replies' => 0, 'leads_answered' => 0, 'waits' => []];
        $waits = [];

        foreach ($leads as $id => $lead) {
            $booked = $lead->status === Lead::STATUS_BOOKED || $lead->job_id !== null;
            $totals['booked'] += $booked ? 1 : 0;
            $rows = $threads->get($id, collect());
            // Something the customer WROTE: a call is over by the time it is
            // listed, and nobody is measured on answering it by message.
            $firstCustomer = $rows->first(fn ($r) => $r->participant === LeadConversation::FROM_CONSUMER
                && $r->channel !== LeadConversation::CHANNEL_PHONE_CALL);
            $ours = $rows->where('participant', LeadConversation::FROM_ADVERTISER);

            $auto = $ours->filter(fn ($r) => (bool) $r->is_automatic);
            $automatic['replies'] += $auto->count();
            $automatic['leads'] += $auto->isEmpty() ? 0 : 1;

            foreach ($ours->reject(fn ($r) => (bool) $r->is_automatic) as $r) {
                if (($sender = $senderOf[$r->id] ?? null) !== null) {
                    $people[$sender] ??= $blank;
                    $people[$sender]['replies']++;
                } else {
                    $outside['replies']++;
                }
            }

            // A website form is answered by calling, never in the thread, so
            // it is not counted against anybody (136 forms, 0 "answered", in
            // the first week measured - 2026-09-30).
            if ($firstCustomer === null || $lead->source === Lead::SOURCE_WEBSITE_FORM) {
                continue;
            }
            $totals['answerable']++;

            $reply = $ours->first(fn ($r) => ! $r->is_automatic && $r->occurred_at >= $firstCustomer->occurred_at);
            if ($reply === null) {
                continue;
            }
            $wait = max(0, Carbon::parse($reply->occurred_at)->getTimestamp() - Carbon::parse($firstCustomer->occurred_at)->getTimestamp());
            $totals['answered']++;
            $totals['within_10_min'] += $wait <= 600 ? 1 : 0;
            $waits[] = $wait;
            foreach (self::BUCKETS as $key => $limit) {
                if ($wait < $limit) {
                    $buckets[$key]++;
                    break;
                }
            }

            $sender = $senderOf[$reply->id] ?? null;
            if ($sender === null) {
                $outside['leads_answered']++;
                $outside['waits'][] = $wait;
            } else {
                $people[$sender] ??= $blank;
                $people[$sender]['leads_answered']++;
                $people[$sender]['waits'][] = $wait;
                $people[$sender]['within_10_min'] += $wait <= 600 ? 1 : 0;
                if ($booked && ! isset($bookedBy[$id])) {
                    $people[$sender]['booked']++;
                }
            }
        }
        foreach ($bookedBy as $leadId => $actor) {
            if ($leads->has($leadId)) {
                $people[$actor] ??= $blank;
                $people[$actor]['booked']++;
            }
        }

        $totals['first_reply_median_seconds'] = self::median($waits);

        $names = User::query()->withoutGlobalScopes()->whereIn('id', array_keys($people))->pluck('name', 'id');
        $peopleOut = collect($people)->map(fn ($p, $id) => [
            'user' => ['id' => $id, 'name' => (string) ($names[$id] ?? 'Someone no longer here')],
            'replies' => $p['replies'],
            'leads_answered' => $p['leads_answered'],
            'first_reply_median_seconds' => self::median($p['waits']),
            'within_10_min' => $p['within_10_min'],
            'booked' => $p['booked'],
        ])->sortByDesc('leads_answered')->values()->all();

        // One entry per day, zeros included, counted in the organization's day.
        $perDay = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $perDay[$d->toDateString()] = [];
        }
        foreach ($leads as $lead) {
            $day = $lead->received_at->copy()->tz($zone)->toDateString();
            $key = $lead->source.'|'.($lead->source === Lead::SOURCE_LOCAL_SERVICES ? $lead->lead_type : '');
            if (isset($perDay[$day])) {
                $perDay[$day][$key] = ($perDay[$day][$key] ?? 0) + 1;
            }
        }

        return [
            'totals' => $totals,
            'per_day' => collect($perDay)->map(fn ($counts, $date) => [
                'date' => $date,
                'sources' => collect($counts)->map(function ($n, $key) {
                    [$source, $type] = explode('|', $key);

                    return ['source' => $source, 'lead_type' => $type === '' ? null : $type, 'count' => $n];
                })->values()->all(),
            ])->values()->all(),
            'buckets' => $buckets,
            'people' => $peopleOut,
            'automatic' => $automatic,
            'outside' => [
                'replies' => $outside['replies'],
                'leads_answered' => $outside['leads_answered'],
                'first_reply_median_seconds' => self::median($outside['waits']),
            ],
        ];
    }

    /** @param  int[]  $values */
    private static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return (int) ($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }
}
