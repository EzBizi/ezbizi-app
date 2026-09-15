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
 * ── Gated on reports.view, not leads.view ────────────────────────
 *
 * Deliberately a higher bar than the enquiry list next door. A dispatcher needs
 * to read an enquiry and reply to it; what the business spends to get those
 * enquiries is a different kind of fact, and the Dispatcher role holding
 * `leads.view` without `reports.view` is the shape that keeps them apart.
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
     * GET /api/v1/ads/performance?from=YYYY-MM-DD&to=YYYY-MM-DD
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
            'connection_id' => ['nullable', 'uuid'],
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
            ->when(
                isset($input['connection_id']),
                fn ($q) => $q->whereKey($input['connection_id']),
            )
            ->orderBy('display_name')
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();

        if ($connections->isEmpty()) {
            return response()->json([
                'message' => 'No Google Ads account is connected here, so there is no spend to report on.',
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
