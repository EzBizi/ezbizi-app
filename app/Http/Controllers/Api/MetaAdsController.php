<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Support\Ads\AllAccounts;
use App\Support\Audit\AuditLogger;
use App\Support\Social\MetaAdsManager;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Facebook and Instagram campaigns: read, pause, resume, re-budget (Jose,
 * 2026-11-04). Marketing › Ads grants (2026-11-09) - ads_manager.view
 * to read what the business spends, ads_manager.manage to change it -
 * and the same rule that a connection is only reachable inside the view.
 * Every change Meta accepts is audited: it changes what a business spends.
 */
class MetaAdsController extends Controller
{
    private const MAX_DAYS = 400;

    public function __construct(
        private readonly MetaAdsManager $ads,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/meta-ads/accounts: the connected ad accounts, from our rows. */
    public function accounts(Request $request): JsonResponse
    {
        $this->authorizeTo($request, 'ads_manager.view');

        return response()->json(['data' => $this->connections($request)->map(fn (SocialConnection $c) => [
            'connection_id' => $c->getKey(),
            'account_id' => $c->remote_id,
            'name' => $c->display_name,
        ])->values()->all()]);
    }

    /** GET /api/v1/meta-ads/tree?connection_id&from&to: campaigns › ad sets › ads, with results. */
    public function tree(Request $request): JsonResponse
    {
        $this->authorizeTo($request, 'ads_manager.view');
        $input = $request->validate([
            'connection_id' => ['required_without:all', 'uuid'],
            'all' => ['sometimes', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = isset($input['to']) ? Carbon::parse($input['to']) : Carbon::today();
        $from = isset($input['from']) ? Carbon::parse($input['from']) : $to->copy()->subDays(29);
        abort_if($from->diffInDays($to) > self::MAX_DAYS, Status::HTTP_UNPROCESSABLE_ENTITY, 'That range is longer than '.self::MAX_DAYS.' days. Ask for a shorter one.');

        if ($request->boolean('all')) {
            return response()->json(['data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                ...AllAccounts::read($this->connections($request), 'meta', $from, $to, fn (SocialConnection $c) => $this->ads->tree($c, $from, $to)),
            ]]);
        }

        $connection = $this->connection($request, $input['connection_id']);

        try {
            $campaigns = $this->ads->tree($connection, $from, $to);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'campaigns' => $campaigns,
        ]]);
    }

    /** POST /api/v1/meta-ads/status {connection_id, object_id, status: ACTIVE|PAUSED} */
    public function setStatus(Request $request): JsonResponse
    {
        $this->authorizeTo($request, 'ads_manager.manage');
        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'object_id' => ['required', 'string', 'regex:/^\d+$/'],
            'status' => ['required', 'in:'.implode(',', MetaAdsManager::STATUSES)],
        ]);
        $connection = $this->connection($request, $input['connection_id']);

        try {
            $this->ads->setStatus($connection, $input['object_id'], $input['status']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_UNPROCESSABLE_ENTITY);
        }

        AllAccounts::forget($connection);
        $this->audit->log('meta_ads.status_changed', $connection, ['status' => $input['status']],
            ['object_id' => $input['object_id'], 'account_id' => $connection->remote_id]);

        return response()->json(['data' => ['object_id' => $input['object_id'], 'status' => $input['status']]]);
    }

    /** POST /api/v1/meta-ads/budget {connection_id, object_id, daily_budget} in the account's currency. */
    public function setBudget(Request $request): JsonResponse
    {
        $this->authorizeTo($request, 'ads_manager.manage');
        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'object_id' => ['required', 'string', 'regex:/^\d+$/'],
            'daily_budget' => ['required', 'numeric', 'min:1', 'max:100000'],
        ]);
        $connection = $this->connection($request, $input['connection_id']);

        try {
            $old = $this->ads->setDailyBudget($connection, $input['object_id'], (float) $input['daily_budget']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_UNPROCESSABLE_ENTITY);
        }

        AllAccounts::forget($connection);
        $this->audit->log('meta_ads.budget_changed', $connection,
            ['daily_budget' => ['from' => $old / 100, 'to' => (float) $input['daily_budget']]],
            ['object_id' => $input['object_id'], 'account_id' => $connection->remote_id]);

        return response()->json(['data' => ['object_id' => $input['object_id'], 'daily_budget' => (float) $input['daily_budget']]]);
    }

    private function authorizeTo(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission) ?? false, Status::HTTP_FORBIDDEN,
            $permission === 'ads_manager.manage'
                ? 'Changing campaigns needs the Manage Ads permission.'
                : 'Reading advertising spend needs the Ads Performance permission.');
    }

    private function connections(Request $request)
    {
        return SocialConnection::query()
            ->where('provider', SocialProvider::FacebookAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->orderBy('display_name')
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($request->user(), $c));
    }

    private function connection(Request $request, string $id): SocialConnection
    {
        return $this->connections($request)->firstWhere('id', $id)
            ?? abort(Status::HTTP_CONFLICT, 'That Facebook ad account is not connected here.');
    }
}
