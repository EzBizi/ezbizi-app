<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Services\Ads\CampaignPerformance;
use App\Support\Ads\AllAccounts;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Marketing › Ads › Google (Jose, 2026-11-09): run Google campaigns beside
 * Meta's - pause and enable, daily budget, bid target - read live and changed
 * at Google, campaign level only. ads_manager.view reads, ads_manager.manage
 * changes; the screen confirms before it posts. Every change Google accepts
 * is audited with what it was and what it became.
 */
class GoogleAdsManagerController extends Controller
{
    public function __construct(
        private readonly CampaignPerformance $google,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** GET ads-manager/google/campaigns?connection_id=&from=&to=, or all=1 for every account (AllAccounts). */
    public function campaigns(Request $request): JsonResponse
    {
        $this->authorizeTo($request, 'ads_manager.view');
        $in = $request->validate([
            'connection_id' => ['required_without:all', 'uuid'],
            'all' => ['sometimes', 'boolean'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        if ($request->boolean('all')) {
            $rows = SocialConnection::query()->where('provider', SocialProvider::GoogleAds->value)
                ->where('status', SocialConnection::STATUS_CONNECTED)->orderBy('display_name')->get()
                ->filter(fn (SocialConnection $c) => $this->access->inView($request->user(), $c));
            // A manager holds no campaigns of its own; the screen leaves them out the same way.
            $managers = $rows->pluck('login_customer_id')->filter()->all();
            $accounts = $rows->reject(fn (SocialConnection $c) => $c->is_manager || in_array((string) $c->remote_id, $managers, true));
            [$from, $to] = [Carbon::parse($in['from']), Carbon::parse($in['to'])];

            return response()->json(['data' => AllAccounts::read($accounts, 'google', $from, $to,
                fn (SocialConnection $c) => $this->google->manageable($c, $from, $to)['campaigns'])]);
        }
        $connection = $this->connection($request, $in['connection_id']);

        try {
            $data = $this->google->manageable($connection, Carbon::parse($in['from']), Carbon::parse($in['to']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => $data]);
    }

    /** POST ads-manager/google/status {connection_id, campaign_id, status: ENABLED|PAUSED} */
    public function status(Request $request): JsonResponse
    {
        return $this->change($request, ['status' => ['required', 'in:ENABLED,PAUSED']], function (SocialConnection $c, array $in) {
            $this->google->setStatus($c, $in['campaign_id'], $in['status']);

            return ['google_ads.campaign_status_changed', ['status' => $in['status']]];
        });
    }

    /** POST ads-manager/google/budget {connection_id, campaign_id, daily_budget} */
    public function budget(Request $request): JsonResponse
    {
        return $this->change($request, ['daily_budget' => ['required', 'numeric', 'min:1', 'max:100000']], function (SocialConnection $c, array $in) {
            $old = $this->google->setDailyBudget($c, $in['campaign_id'], (float) $in['daily_budget']);

            return ['google_ads.budget_changed', ['daily_budget' => ['from' => $old, 'to' => (float) $in['daily_budget']]]];
        });
    }

    /** POST ads-manager/google/target {connection_id, campaign_id, target}: CPA in money, ROAS as a ratio */
    public function target(Request $request): JsonResponse
    {
        return $this->change($request, ['target' => ['required', 'numeric', 'gt:0']], function (SocialConnection $c, array $in) {
            $done = $this->google->setTarget($c, $in['campaign_id'], (float) $in['target']);

            return ['google_ads.target_changed', ['target_'.$done['kind'] => ['from' => $done['before'], 'to' => (float) $in['target']]]];
        });
    }

    /**
     * @param  array<string, list<string>>  $rules
     * @param  callable(SocialConnection, array<string, mixed>): array{0: string, 1: array<string, mixed>}  $apply
     */
    private function change(Request $request, array $rules, callable $apply): JsonResponse
    {
        $this->authorizeTo($request, 'ads_manager.manage');
        $in = $request->validate($rules + [
            'connection_id' => ['required', 'uuid'],
            'campaign_id' => ['required', 'string', 'regex:/^\d+$/'],
        ]);
        $connection = $this->connection($request, $in['connection_id']);

        try {
            [$action, $changes] = $apply($connection, $in);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_UNPROCESSABLE_ENTITY);
        }
        AllAccounts::forget($connection);
        $this->audit->log($action, $connection, $changes, ['campaign_id' => $in['campaign_id'], 'customer_id' => $connection->remote_id]);

        return response()->json(['data' => ['campaign_id' => $in['campaign_id']] + $changes]);
    }

    private function authorizeTo(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission) ?? false, Status::HTTP_FORBIDDEN,
            $permission === 'ads_manager.manage'
                ? 'Changing campaigns needs the Manage Ads permission.'
                : 'Reading advertising needs the Ads permission.');
    }

    private function connection(Request $request, string $id): SocialConnection
    {
        return SocialConnection::query()->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)->whereKey($id)->get()
            ->first(fn (SocialConnection $c) => $this->access->inView($request->user(), $c))
            ?? abort(Status::HTTP_CONFLICT, 'That Google Ads account is not connected here.');
    }
}
