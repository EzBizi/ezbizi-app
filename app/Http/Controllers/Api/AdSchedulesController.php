<?php

namespace App\Http\Controllers\Api;

use App\Models\AdSchedule;
use App\Models\SocialConnection;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\OrgTimezone;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Campaign on/off schedules, Meta and Google (Jose, 2026-11-05: "can we build
 * a switch so it can turn it on/off at designated times?"). Same grants as
 * the switch: ads_performance.view or ads_manager.view reads them, either's
 * .manage sets them. ezbizi:ad-schedules applies them.
 */
class AdSchedulesController extends Controller
{
    private const PROVIDERS = [SocialProvider::FacebookAds->value, SocialProvider::GoogleAds->value];

    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/ad-schedules?connection_id= */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeTo($request, 'ads_performance.view');
        $connection = $this->connection($request, (string) $request->query('connection_id'));

        return response()->json(['data' => AdSchedule::query()->where('connection_id', $connection->getKey())
            ->orderBy('object_name')->get()->map(fn (AdSchedule $s) => $this->row($s))->values()]);
    }

    /** PUT /api/v1/ad-schedules {connection_id, object_id, object_name, days, on_at, off_at, timezone} */
    public function save(Request $request): JsonResponse
    {
        $this->authorizeTo($request, 'ads_performance.manage');
        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'object_id' => ['required', 'string', 'regex:/^\d+$/', 'max:32'],
            'object_name' => ['nullable', 'string', 'max:255'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'on_at' => ['required', 'date_format:H:i'],
            'off_at' => ['required', 'date_format:H:i', 'different:on_at'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);
        $connection = $this->connection($request, $input['connection_id']);

        $schedule = AdSchedule::query()->firstOrNew(['connection_id' => $connection->getKey(), 'object_id' => $input['object_id']]);
        $before = $schedule->exists ? $schedule->only(['days', 'on_at', 'off_at', 'timezone']) : null;
        $schedule->fill([
            'organization_id' => $connection->organization_id,
            'object_name' => $input['object_name'] ?? $schedule->object_name,
            'days' => array_values(array_map('intval', $input['days'])),
            'on_at' => $input['on_at'],
            'off_at' => $input['off_at'],
            'timezone' => $input['timezone'] ?? OrgTimezone::of(),
            // A new or changed schedule takes effect on the next run.
            'last_state' => null,
            'last_error' => null,
            'created_by' => $schedule->created_by ?? $request->user()->getKey(),
        ])->save();

        $this->audit->log('ad_schedule.saved', $connection,
            ['schedule' => ['from' => $before, 'to' => $schedule->only(['days', 'on_at', 'off_at', 'timezone'])]],
            ['object_id' => $schedule->object_id, 'object_name' => $schedule->object_name]);

        return response()->json(['data' => $this->row($schedule)]);
    }

    /** DELETE /api/v1/ad-schedules/{schedule} - the campaign stays as it is. */
    public function destroy(Request $request, string $schedule): JsonResponse
    {
        $this->authorizeTo($request, 'ads_performance.manage');
        $row = AdSchedule::query()->findOrFail($schedule);
        $connection = $this->connection($request, $row->connection_id);

        $row->delete();
        $this->audit->log('ad_schedule.removed', $connection, [], ['object_id' => $row->object_id, 'object_name' => $row->object_name]);

        return response()->json(['data' => ['id' => $schedule]]);
    }

    /** @return array<string, mixed> */
    private function row(AdSchedule $s): array
    {
        $next = $s->nextChange(Carbon::now());

        return $s->only(['id', 'connection_id', 'object_id', 'object_name', 'days', 'on_at', 'off_at', 'timezone', 'last_state', 'last_error']) + [
            'last_applied_at' => $s->last_applied_at?->toIso8601String(),
            'next_change_at' => $next === null ? null : $next['at']->toIso8601String(),
            'next_state' => $next === null ? null : ($next['on'] ? AdSchedule::ON : AdSchedule::OFF),
            // Worded here, in the schedule's own timezone, which the screen cannot convert to.
            'summary' => $s->summary(),
            'next_change' => $next === null ? null : ($next['on'] ? 'on at ' : 'off at ').$s->when($next['at']),
            'last_change' => $s->last_applied_at === null ? null
                : 'Turned '.$s->last_state.' at '.$s->when($s->last_applied_at),
            // The edge that failed, when one did: the state it wants now.
            'wants' => $s->wantsOn(Carbon::now()) ? AdSchedule::ON : AdSchedule::OFF,
        ];
    }

    private function authorizeTo(Request $request, string $permission): void
    {
        // Google's report (Reports) and Marketing › Ads both draw schedules.
        $user = $request->user();
        abort_unless(($user?->hasPermission($permission) || $user?->hasPermission(str_replace('ads_performance.', 'ads_manager.', $permission))) ?? false, Status::HTTP_FORBIDDEN,
            $permission === 'ads_performance.manage'
                ? 'Scheduling campaigns needs the Manage Ads permission.'
                : 'Reading advertising needs the Ads Performance permission.');
    }

    private function connection(Request $request, string $id): SocialConnection
    {
        return SocialConnection::query()->whereIn('provider', self::PROVIDERS)
            ->where('status', SocialConnection::STATUS_CONNECTED)->whereKey($id)->get()
            ->first(fn (SocialConnection $c) => $this->access->inView($request->user(), $c))
            ?? abort(Status::HTTP_CONFLICT, 'That ad account is not connected here.');
    }
}
