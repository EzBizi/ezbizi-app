<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Services\Ads\CampaignPerformance;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
                'is_manager' => in_array((string) $c->remote_id, $managerIds, true),
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

        foreach ($children as $child) {
            $row = $connected->get($child['id']);

            if ($row === null) {
                $unreadable++;

                continue;
            }

            try {
                $report = $this->performance->forConnection($row, $from, $to, withCampaigns: false);
                $accounts[] = [
                    'connection_id' => $row->getKey(),
                    ...$report['account'],
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
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
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
                $accounts[] = [
                    'connection_id' => $connection->getKey(),
                    ...$this->performance->forConnection($connection, $from, $to),
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
