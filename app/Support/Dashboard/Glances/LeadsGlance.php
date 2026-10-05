<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Leads\LeadReplies;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Leads box: enquiries arriving as a line, and how many are still untouched.
 *
 * ── The number that leads with is the unanswered one ─────────────
 *
 * Every other glance leads with a total, because a total is what those modules
 * are about. This one leads with how many are still `new`, because that is the
 * only number on the dashboard that represents a member of the public waiting
 * for a call back. Forty leads this week is a good week; forty leads nobody has
 * opened is a problem, and a box that showed only the first would report the
 * good week on the morning of the bad one.
 *
 * Bucketed on `received_at` — when the enquiry was MADE — rather than on
 * `created_at`, which is when a sync happened to write it down. A fetch that
 * runs once at nine in the morning would otherwise draw every one of the
 * previous day's enquiries as a spike at nine.
 */
final class LeadsGlance extends DescribedGlance
{
    protected const KEY = 'leads';
    protected const LABEL = 'Leads';
    protected const PERMISSION = 'leads.view';
    protected const PRESENTATIONS = ['line', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'line';
    protected const SNAPSHOT = false;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $reachable = ViewScope::forUser($user)->franchiseIds();

            // Period bounds are wall times in the view timezone; received_at is
            // stored UTC. Bind UTC instants so "now" in New York is not compared
            // as an earlier naive clock string than the row.
            $from = $period->startsOn->copy()->utc();
            $to   = $period->endsOn->copy()->utc();

            // An organization-wide lead has no franchise, and it still belongs
            // to whoever can see this box. Filtering on the franchise column
            // alone would hide every lead that arrived through a connection
            // scoped at the top — which, for a single-company tenant, is all
            // of them.
            /*
             * Scope, and one thing beside it: `is_test` is excluded HERE
             * rather than at each of the three call sites, so a fourth count
             * added later cannot forget it.
             *
             * A specimen from `ezbizi:thumbtack-test-lead` is a real row with
             * a real Thumbtack id, and the number this box leads with is the
             * one that means a member of the public is waiting for a call
             * back. A test enquiry raising that alarm is the same failure as
             * missing a real one, in the other direction.
             */
            $inReach = fn ($query) => $query
                ->where('is_test', false)
                /*
                 * And enquiries whose account has been disconnected, for the
                 * same reason and in the same place.
                 *
                 * Jose asked for those hidden from the Leads list on
                 * 2026-09-16. Hiding them there and counting them here would
                 * put "38 enquiries" on the dashboard above a list showing two,
                 * and the person reading it has no way to tell which number is
                 * lying. A figure nobody can reconcile with the screen beside
                 * it is worse than either number alone.
                 */
                ->sourceStillConnected()
                /*
                 * And only the sources this person was granted, by exactly the
                 * argument written two paragraphs up. A dispatcher who may see
                 * website chats and not Thumbtack would otherwise read "38
                 * enquiries" here above a list showing four, and the number
                 * they cannot reconcile is the one they will believe.
                 */
                ->sourceVisibleTo($user)
                ->where(
                    fn ($q) => $q->whereNull('franchise_id')->orWhereIn('franchise_id', $reachable),
                );

            $total = (int) $inReach(Lead::query())->count();

            if ($total === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: [],
                    data: [
                        'empty' => true,
                        /*
                         * "In this view", because this box is scoped and the
                         * sentence it used to carry was not.
                         *
                         * It said "No enquiries yet.", which is a claim about
                         * the ORGANIZATION delivered to a reader who can only
                         * see part of it. On 2026-09-09 that reader could have
                         * been a franchise manager with nothing placed in
                         * their branch while the organization held 40,469
                         * enquiries — the sentence was not clumsy, it was
                         * false, and false in the direction that matters most
                         * here: it tells somebody nothing is arriving when the
                         * truth is that it is arriving somewhere they cannot
                         * see. Board-wide ruling, UI/UX 30ff2369.
                         */
                        'sentence' => 'No enquiries in this view.',
                    ],
                );
            }

            $waiting = (int) $inReach(Lead::query())
                ->where('status', Lead::STATUS_NEW)
                ->count();

            $arrived = $inReach(Lead::query())
                ->where('received_at', '>=', $from)
                ->where('received_at', '<=', $to)
                ->get(['id', 'received_at']);

            $counts = $this->emptyBuckets($period);

            foreach ($arrived as $lead) {
                $at = $lead->received_at;

                if (! $at instanceof Carbon) {
                    continue;
                }

                $bucket = $period->bucket($at);
                $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
            }

            $periodWord = $period->phrase();
            $inPeriod   = array_sum($counts);

            // Customers who spoke last in the past day and can be answered:
            // the list's red mark (overdue) and those about to get it. A day,
            // because an enquiry left New for a week is not "waiting" any more.
            $replies = app(LeadReplies::class);
            $owed = $inReach(Lead::query())
                ->where('status', Lead::STATUS_NEW)
                ->where('last_contact_from', LeadConversation::FROM_CONSUMER)
                ->where('last_contact_at', '>=', now()->subDay())
                ->get()
                ->filter(fn (Lead $lead) => $replies->canReply($lead));
            $overdue = $owed->filter(fn (Lead $lead) => $lead->replyOverdue(true))->count();
            $soon = $owed->count() - $overdue;
            $attention = array_values(array_filter([
                $overdue === 0 ? null : GlancePayload::attention('bad', $overdue === 1
                    ? '1 customer waiting over '.Lead::REPLY_OVERDUE_AFTER_MINUTES.' minutes for a reply'
                    : "{$overdue} customers waiting over ".Lead::REPLY_OVERDUE_AFTER_MINUTES.' minutes for a reply'),
                $soon === 0 ? null : GlancePayload::attention('caution', $soon === 1
                    ? '1 customer waiting for a reply'
                    : "{$soon} customers waiting for a reply"),
            ]));

            /*
             * The "yet" in "not yet dealt with" STAYS, and a sweep that
             * removes the word everywhere would be wrong to take it.
             *
             * The ruling is not against a word. It is against a claim the box
             * cannot support: "Nothing posted yet." says the organization has
             * nothing, from a box that saw part of it. This "yet" qualifies
             * the LEADS the box is holding and counting — these ones have not
             * been dealt with — which is the box describing itself. Same shape
             * as the two Automations strings that keep "in reach" because
             * there it names a mechanism.
             */
            $sentences = [
                $waiting === 1
                    ? '1 lead not yet dealt with.'
                    : "{$waiting} leads not yet dealt with.",
                $inPeriod === 1
                    ? "1 arrived {$periodWord}."
                    : "{$inPeriod} arrived {$periodWord}.",
            ];

            $points = [];
            $slices = [];

            foreach ($counts as $at => $value) {
                $points[] = ['at' => $at, 'value' => $value];
                $slices[] = ['label' => $at, 'total' => $value];
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: [
                    'empty'  => false,
                    'series' => [
                        [
                            'label'  => 'Arrived',
                            'points' => $points,
                        ],
                    ],
                    'slices'    => $slices,
                    'sentences' => $sentences,
                    'figures'   => [
                        GlancePayload::figure('arrived', 'arrived '.$periodWord, $inPeriod, $this->arrivedIn($period->previous(), $inReach)),
                        GlancePayload::figure('waiting', 'not yet dealt with', $waiting),
                    ],
                    ...($attention === [] ? [] : ['attention' => $attention]),
                    // `organization_total` was here and is gone. GlancePayload's
                    // rule: a glance sends what its box draws, and this one was
                    // read by nothing in client/lib - a count on every dashboard
                    // load for a figure nobody sees, and organization-wide, so a
                    // franchise-level reader received the whole organization's
                    // number in the response. It also had no honest answer once
                    // disconnected enquiries are hidden: whichever rule it took,
                    // it would disagree with the box above it.
                ],
            );
        });
    }

    /**
     * How many enquiries were made in a window, on the same `received_at` and
     * the same reach the line reads. Only a count: the figure's `previous`
     * needs no buckets.
     */
    private function arrivedIn(DashboardPeriod $period, callable $inReach): int
    {
        return (int) $inReach(Lead::query())
            ->where('received_at', '>=', $period->startsOn->copy()->utc())
            ->where('received_at', '<=', $period->endsOn->copy()->utc())
            ->count();
    }

    /**
     * Every axis key from startsOn through endsOn, zeros included so the line
     * does not skip quiet days / weeks / months.
     *
     * @return array<string, int>
     */
    private function emptyBuckets(DashboardPeriod $period): array
    {
        return $period->emptyBuckets();
    }
}
