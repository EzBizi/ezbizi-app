<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Services\Ads\CampaignPerformance;
use App\Support\Audit\AuditLogger;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Google Ads spend and performance, at the two levels we display.
 *
 * ── Gated on ads_performance.view, its own grant ─────────────────
 *
 * Deliberately a higher bar than the enquiry list next door, and deliberately
 * NOT `reports.view` either. A dispatcher needs to read an enquiry and reply to
 * it; what the business spends to get those enquiries is a different kind of
 * fact, and what it measures internally is a third. Dispatcher holds
 * `leads.view` and not this, which is the shape that keeps them apart.
 *
 * (This comment said `reports.view` until 2026-09-15 and was wrong from the
 * moment the module declared its own permission.)
 *
 * ── The view, not just the organization ──────────────────────────
 *
 * Same rule as every other connection read: the tenant scope narrows to the
 * organization and only `inView` narrows to the company being looked at. Left
 * out, one company's spend appears in a sibling's report - which for figures
 * like these is worse than the lead leak that motivated the rule.
 */
class AdsPerformanceController extends Controller
{
    /** A ceiling on the window, so one request cannot ask Google for years. */
    private const MAX_DAYS = 400;

    public function __construct(
        private readonly CampaignPerformance $performance,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/ads/accounts
     *
     * The connected accounts, read from OUR database and never from Google.
     *
     * This exists because the screen used to load every account at once. A-1
     * has fifty-three, each costing two API calls, so opening the tab spent
     * 106 operations of a 15,000/day ceiling and took as long as the slowest
     * of fifty-three round trips - Jose's words were "takes a lot to load".
     *
     * So: this answers instantly from rows we already hold, the screen lists
     * them, and nothing is asked of Google until somebody picks one.
     */
    public function accounts(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->hasPermission('ads_performance.view'),
            Status::HTTP_FORBIDDEN,
            'Reading advertising spend needs the Ads Performance permission.',
        );

        $rows = SocialConnection::query()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->orderBy('display_name')
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();

        /*
         * Which of these are managers, worked out from the rows themselves.
         *
         * An account is a manager if another connected account was reached
         * THROUGH it. That is a hint rather than the truth - an account
         * connected directly leaves login_customer_id null even when it does
         * sit under a manager - so it is only used to decide whether the screen
         * offers a roll-up. The roll-up itself asks Google.
         */
        $managerIds = $rows->pluck('login_customer_id')->filter()->unique()->all();

        return response()->json([
            'data' => $rows->map(fn (SocialConnection $c) => [
                'connection_id' => $c->getKey(),
                'customer_id' => $c->remote_id,
                'name' => $c->display_name,
                'is_manager' => $c->is_manager || in_array((string) $c->remote_id, $managerIds, true),
            ])->all(),
        ]);
    }

    /**
     * The date range, or the refusal that explains why there is not one.
     *
     * Shared by the single-account report and the manager roll-up, because two
     * copies of a date rule is two chances to end on a different day - and a
     * roll-up whose window differs from the account report it sits beside is
     * the kind of wrong that reads as a Google discrepancy.
     *
     * Ends YESTERDAY by default, never today: Google restates the current day
     * as conversions are attributed late, so today's figure is the only one on
     * the screen guaranteed to change after somebody has read it.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: Carbon, 1: Carbon, 2: JsonResponse|null}
     */
    private function window(array $input): array
    {
        $to = isset($input['to']) ? Carbon::parse($input['to']) : Carbon::yesterday();
        $from = isset($input['from']) ? Carbon::parse($input['from']) : $to->copy()->subDays(29);

        if ($from->greaterThan($to)) {
            return [$from, $to, response()->json([
                'message' => 'The start of the range is after its end.',
            ], Status::HTTP_UNPROCESSABLE_ENTITY)];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            return [$from, $to, response()->json([
                'message' => 'That range is longer than '.self::MAX_DAYS.' days. Ask for a shorter one.',
            ], Status::HTTP_UNPROCESSABLE_ENTITY)];
        }

        return [$from, $to, null];
    }

    /**
     * GET /api/v1/ads/rollup?connection_id=<a manager>&from=&to=
     *
     * What every account beneath a manager spent, one row each.
     *
     * ── This is the one place a fan-out is still correct ─────────
     *
     * Everywhere else it was removed: opening the report used to load all 53
     * accounts and cost 106 Google operations before anybody asked for a
     * figure. Here the fan-out IS the answer - "what did the whole group
     * spend" cannot be answered from one account - and it costs nothing until
     * somebody deliberately picks a manager.
     *
     * It is still bounded. One query per child, account level only, no
     * campaigns: a manager with forty accounts is forty operations rather than
     * eighty. The response says how many children Google knows about and how
     * many we could actually read, because a total that quietly covers fewer
     * accounts than it appears to is worse than no total.
     */
    public function rollup(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->hasPermission('ads_performance.view'),
            Status::HTTP_FORBIDDEN,
            'Reading advertising spend needs the Ads Performance permission.',
        );

        $input = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'connection_id' => ['required', 'uuid'],
        ]);

        [$from, $to, $bad] = $this->window($input);

        if ($bad !== null) {
            return $bad;
        }

        $manager = SocialConnection::query()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->whereKey($input['connection_id'])
            ->get()
            ->first(fn (SocialConnection $c) => $this->access->inView($user, $c));

        if ($manager === null) {
            return response()->json([
                'message' => 'That Google Ads account is not connected here.',
            ], Status::HTTP_CONFLICT);
        }

        try {
            $children = $this->performance->childrenOf($manager);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_CONFLICT);
        }

        // Only accounts we hold a token for can be read. Everything else in the
        // manager's tree is counted and reported as unreadable rather than
        // quietly left out of the total.
        $connected = SocialConnection::query()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->keyBy(fn (SocialConnection $c) => preg_replace('/\D/', '', (string) $c->remote_id));

        $accounts = [];
        $refused = [];
        $unreadable = 0;

        // A canceled or closed account cannot spend and cannot be connected:
        // counted apart, not as "not connected" (Jose, 2026-10-12: 27 of the
        // Ads Manager's 63 were exactly these).
        $closed = array_filter($children, fn ($c) => in_array($c['status'], ['CANCELED', 'CLOSED'], true));
        $children = array_values(array_diff_key($children, $closed));
        $managerToken = null;

        foreach ($children as $child) {
            $row = $connected->get($child['id']);
            $token = null;

            if ($row === null) {
                // Not connected here yet: read through the manager, which
                // reaches every client (leads:adopt-manager-clients connects
                // it within a day).
                try {
                    $managerToken ??= GoogleToken::forConnection($manager, 'google_ads');
                } catch (RuntimeException) {
                    $unreadable++;

                    continue;
                }
                $token = $managerToken;
                $row = (new SocialConnection)->forceFill([
                    'id' => (string) Str::uuid(),   // matches no lead
                    'provider' => SocialProvider::GoogleAds->value,
                    'remote_id' => $child['id'],
                    'login_customer_id' => preg_replace('/\D/', '', (string) $manager->remote_id),
                    'display_name' => $child['name'],
                ]);
            }

            try {
                $report = $this->performance->forConnection($row, $from, $to, withCampaigns: false, token: $token);
                $accounts[] = [
                    'connection_id' => $token === null ? $row->getKey() : null,
                    ...self::withLeads($row, $report['account'], $from, $to),
                ];
            } catch (RuntimeException $e) {
                $refused[] = [
                    'connection_id' => $row->getKey(),
                    'account' => $row->display_name,
                    'because' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => $accounts,
            'refused' => $refused,
            'children_total' => count($children),
            'not_connected' => $unreadable,
            'canceled' => count($closed),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }

    /**
     * POST /api/v1/ads/campaigns/status  {connection_id, campaign_ids[], status}
     *
     * Pause or enable the campaigns somebody selected. Jose, 2026-09-24.
     *
     * ── Its own grant, never implied by reading ─────────────────────
     *
     * `ads_performance.manage`, separate from `.view`. Reading what an account
     * spent and switching its advertising off are different acts with
     * different consequences - one press here stops the phone ringing for a
     * whole branch - and an organization must be able to give the first
     * without the second.
     *
     * ── One answer per campaign ──────────────────────────────────────
     *
     * Each campaign is its own request and its own result. Google refuses some
     * campaign types and some account states, and a batch that failed as a
     * whole because one of eight could not be paused would leave the person
     * guessing which seven did. The response names every one.
     *
     * Every change that Google accepts is audited, with who, which account,
     * which campaign and which way - this changes what a business spends, and
     * "who paused Atlanta on Tuesday" has to have an answer.
     */
    public function setStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->hasPermission('ads_performance.manage'),
            Status::HTTP_FORBIDDEN,
            'Pausing or enabling campaigns needs the Manage Google Ads permission.',
        );

        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'campaign_ids' => ['required', 'array', 'min:1', 'max:50'],
            'campaign_ids.*' => ['required', 'string', 'regex:/^\d+$/'],
            'status' => ['required', 'in:ENABLED,PAUSED'],
        ]);

        $connection = SocialConnection::query()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->whereKey($input['connection_id'])
            ->get()
            ->first(fn (SocialConnection $c) => $this->access->inView($user, $c));

        if ($connection === null) {
            return response()->json([
                'message' => 'That Google Ads account is not connected here.',
            ], Status::HTTP_CONFLICT);
        }

        $results = [];

        foreach (array_unique($input['campaign_ids']) as $campaignId) {
            try {
                $this->performance->setStatus($connection, $campaignId, $input['status']);
            } catch (RuntimeException $e) {
                $results[] = ['campaign_id' => $campaignId, 'ok' => false, 'because' => $e->getMessage()];

                continue;
            }

            // One action name, written out: the status it moved to is in the
            // change, so "every pause last week" is a filter on one event
            // type rather than a join across two.
            $this->audit->log(
                'ads_performance.campaign_status_changed',
                $connection,
                ['status' => $input['status']],
                ['campaign_id' => $campaignId, 'customer_id' => $connection->remote_id],
            );

            $results[] = ['campaign_id' => $campaignId, 'ok' => true, 'because' => null];
        }

        return response()->json(['data' => $results]);
    }

    /**
     * GET /api/v1/ads/performance?connection_id=...&from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * ONE account. `connection_id` is required, deliberately.
     *
     * It used to be optional and meant "all of them", which is how opening the
     * screen came to cost 106 Google operations. Making it required is not a
     * convenience: an optional fan-out is the kind of default that quietly
     * spends a tenant's whole daily quota because somebody left a tab open.
     */
    /**
     * The account's leads over the same days, and what each one cost - the
     * figure A1 Leads reports per account and Google does not. Counted from
     * EzBizi's own copy of the leads (every minute's sync), on the account's
     * own calendar, since that is the calendar Google's cost is summed on.
     *
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    private static function withLeads(SocialConnection $connection, array $account, Carbon $from, Carbon $to): array
    {
        $zone = $account['time_zone'] ?? config('app.timezone');
        $window = [
            Carbon::parse($from->format('Y-m-d'), $zone)->startOfDay()->utc(),
            Carbon::parse($to->format('Y-m-d'), $zone)->endOfDay()->utc(),
        ];
        $leads = Lead::query()
            ->where('connection_id', $connection->getKey())
            ->whereBetween('received_at', $window)
            ->selectRaw("COUNT(*) AS total, SUM(charge_status = 'CHARGED') AS charged, SUM(charge_status = 'CREDITED') AS credited")
            ->first();

        $total = (int) $leads->total;

        /*
         * Calls, and the ones nobody picked up. Google records a duration on
         * every call that connected, so a customer's call without one rang
         * out - measured 2026-09-28: 16,070 of 47,483 calls had none, and none
         * of those has a recording.
         */
        $calls = LeadConversation::query()
            ->whereIn('lead_id', Lead::query()->select('id')->where('connection_id', $connection->getKey()))
            ->where('channel', 'PHONE_CALL')
            ->where('participant', 'CONSUMER')
            ->whereBetween('occurred_at', $window)
            ->selectRaw('COUNT(*) AS total, SUM(duration_ms IS NULL) AS missed')
            ->first();

        /*
         * How long a written enquiry (a message or a booking) waited for the
         * business's first answer, as the median - one lead left for a week
         * must not read as everyone waiting a day. Leads still unanswered are
         * counted apart rather than left out, which would flatter the median.
         */
        $waits = [];
        $unanswered = 0;
        Lead::query()
            ->where('connection_id', $connection->getKey())
            ->whereIn('lead_type', ['MESSAGE', 'BOOKING'])
            ->whereBetween('received_at', $window)
            ->select('id', 'received_at')
            ->addSelect(['first_reply_at' => LeadConversation::query()
                ->selectRaw('MIN(occurred_at)')
                ->whereColumn('lead_id', 'leads.id')
                ->where('participant', 'ADVERTISER')])
            ->get()
            ->each(function (Lead $lead) use (&$waits, &$unanswered) {
                if ($lead->first_reply_at === null) {
                    $unanswered++;
                } else {
                    $waits[] = max(0, Carbon::parse($lead->first_reply_at, 'UTC')->getTimestamp() - $lead->received_at->getTimestamp());
                }
            });
        sort($waits);
        $median = $waits === [] ? null : $waits[intdiv(count($waits), 2)];

        /*
         * What those leads became: the jobs they were booked as or matched to
         * by phone (`ezbizi:match-leads-to-jobs`), each job once however many
         * times its customer called. A cancelled job is not revenue.
         */
        $jobs = Job::query()
            ->whereIn('id', Lead::query()->select('job_id')
                ->where('connection_id', $connection->getKey())
                ->whereBetween('received_at', $window)
                ->whereNotNull('job_id'))
            ->whereRaw("LOWER(status) NOT IN ('canceled', 'cancelled')")
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(amount_minor), 0) AS revenue')
            ->first();
        $revenue = round(((int) $jobs->revenue) / 100, 2);

        return [
            ...$account,
            'leads' => $total,
            'charged_leads' => (int) $leads->charged,
            'credited_leads' => (int) $leads->credited,
            // Null, not 0, with no leads: nothing was bought, so nothing had a price.
            'cost_per_lead' => $total > 0 ? round(((float) $account['cost']) / $total, 2) : null,
            'calls' => (int) $calls->total,
            'missed_calls' => (int) $calls->missed,
            'median_first_reply_minutes' => $median === null ? null : (int) round($median / 60),
            'unanswered_messages' => $unanswered,
            'jobs' => (int) $jobs->total,
            'revenue' => $revenue,
            'return_on_spend' => ((float) $account['cost']) > 0 ? round($revenue / (float) $account['cost'], 2) : null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->hasPermission('ads_performance.view'),
            Status::HTTP_FORBIDDEN,
            'Reading advertising spend needs the Ads Performance permission.',
        );

        $input = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'connection_id' => ['required', 'uuid'],
        ]);

        [$from, $to, $bad] = $this->window($input);

        if ($bad !== null) {
            return $bad;
        }

        $connections = SocialConnection::query()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->whereKey($input['connection_id'])
            ->orderBy('display_name')
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();

        if ($connections->isEmpty()) {
            // Not 404: the id may be perfectly real and simply outside this
            // person's view, and saying which would leak that it exists.
            return response()->json([
                'message' => 'That Google Ads account is not connected here.',
            ], Status::HTTP_CONFLICT);
        }

        $accounts = [];
        $refused = [];

        foreach ($connections as $connection) {
            try {
                $report = $this->performance->forConnection($connection, $from, $to);
                $report['account'] = self::withLeads($connection, $report['account'], $from, $to);
                $accounts[] = [
                    'connection_id' => $connection->getKey(),
                    ...$report,
                ];
            } catch (RuntimeException $e) {
                // One dead account must not blank the whole report. A-1 holds
                // accounts Google itself calls deactivated; reporting nothing
                // because one of fifty-three refuses would hide the other
                // fifty-two behind somebody else's billing problem.
                $refused[] = [
                    'connection_id' => $connection->getKey(),
                    'account' => $connection->display_name,
                    'because' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => $accounts,
            'refused' => $refused,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }
}
