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

        return response()->json([
            'data' => $rows->map(fn (SocialConnection $c) => [
                'connection_id' => $c->getKey(),
                'customer_id' => $c->remote_id,
                'name' => $c->display_name,
            ])->all(),
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

        // Default to the last 30 days, ending yesterday. Not today: Google
        // restates the current day as conversions are attributed, so a figure
        // for today is the only one on the screen guaranteed to change after
        // somebody has read it.
        $to = isset($input['to']) ? Carbon::parse($input['to']) : Carbon::yesterday();
        $from = isset($input['from']) ? Carbon::parse($input['from']) : $to->copy()->subDays(29);

        if ($from->greaterThan($to)) {
            return response()->json([
                'message' => 'The start of the range is after its end.',
            ], Status::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            return response()->json([
                'message' => 'That range is longer than '.self::MAX_DAYS.' days. Ask for a shorter one.',
            ], Status::HTTP_UNPROCESSABLE_ENTITY);
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
