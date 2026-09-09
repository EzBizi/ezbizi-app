<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringFloorPlan;
use App\Models\User;
use App\Support\Access\ScopeReach;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The org's floor plans — the Monitoring "General" tab's read side
 * (docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md, Task 3).
 * `index` lists the plans a caller may see; `show` returns one plan's layout
 * plus a resolved per-node online/offline status. Writes (store/update/destroy)
 * arrive in Task 4.
 *
 * ── §3/§4 reach: two different scopes, on purpose ─────────────────
 *
 * A plan carries the `assigned_*` scope trio, so WHICH plans a caller may see
 * is `ScopeReach::constrain($query, $actor)` applied directly to the table —
 * the same predicate assignments and invitations use. That gives Org > Company
 * > Franchise visibility for free (see MonitoringFloorPlan's docblock).
 *
 * A plan's NODES are a different question. A node points at a person, and
 * whether the caller may learn that person's name and online state is "may this
 * caller see THAT PERSON", not "may they see this plan" — exactly what
 * `ScopeReach::constrainToVisiblePeople` answers, the same predicate
 * DevicesController scopes the roster by. So a caller who can see a plan (its
 * scope is within reach) can still have a node on it whose assignee they may
 * not see — that node resolves to unassigned rather than leaking a name. The
 * two scopes are applied independently, one to the plan, one to the assignees.
 */
class FloorPlansController extends Controller
{
    /**
     * A device silent longer than this is offline. Five minutes, the same
     * window the client draws with — PcSpecsTab.onlineWithin and
     * DesktopMonitoringTab.onlineWithin are each `Duration(minutes: 5)`. Kept
     * as its own copy here for the same reason those two are separate: the
     * threshold that colours a dot is an independent knob from the one that
     * FIRES `monitoring.device_offline` (DeviceOfflineWorker's cutoff), and
     * they are free to agree without being wired together. "Seen within the
     * window" mirrors the client's `now - last_seen_at <= onlineWithin`.
     */
    private const ONLINE_WINDOW_MINUTES = 5;

    public function __construct(private readonly ScopeReach $reach) {}

    /** GET /api/v1/monitoring/floor-plans */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringFloorPlan::class);

        $actor = $request->user();

        $query = MonitoringFloorPlan::query()->with(['company:id,name', 'franchise:id,name']);
        $this->reach->constrain($query, $actor);

        $plans = $query->orderByDesc('updated_at')->get();

        return response()->json([
            'data' => $plans->map(fn (MonitoringFloorPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'scope' => $this->scope($plan, $actor),
                'updated_at' => $plan->updated_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * GET /api/v1/monitoring/floor-plans/{id}
     *
     * The plan is loaded §3-scoped — the reach predicate is built onto the
     * query BEFORE the id lookup, so `find($id)` returns null for a plan the
     * caller may not reach exactly as it does for one that never existed, and
     * both 404. That is the ScreenshotsController pattern: a row outside reach
     * must read as "does not exist", never "forbidden", or the id space becomes
     * an existence oracle.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('viewAny', MonitoringFloorPlan::class);

        $actor = $request->user();

        $query = MonitoringFloorPlan::query()->with(['company:id,name', 'franchise:id,name']);
        $this->reach->constrain($query, $actor);

        $plan = $query->find($id);

        if ($plan === null) {
            abort(404);
        }

        return response()->json([
            'data' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'scope' => $this->scope($plan, $actor),
                'layout' => $plan->layout,
                'status' => $this->status($plan, $actor),
            ],
        ]);
    }

    /**
     * The scope descriptor a plan presents — the narrowest place it names.
     *
     * A franchise plan reads as its franchise, a company plan as its company,
     * and everything else as the organization (`assigned_organization_id` is
     * always the tenant org, stamped on create). Names come from the eager
     * loaded relations so a list of plans is not N+1; the org name is the
     * caller's own organization, which every plan in the tenant shares.
     *
     * @return array{type: string, id: ?string, label: ?string}
     */
    private function scope(MonitoringFloorPlan $plan, User $actor): array
    {
        if ($plan->assigned_franchise_id !== null) {
            return ['type' => 'franchise', 'id' => $plan->assigned_franchise_id, 'label' => $plan->franchise?->name];
        }

        if ($plan->assigned_company_id !== null) {
            return ['type' => 'company', 'id' => $plan->assigned_company_id, 'label' => $plan->company?->name];
        }

        return ['type' => 'organization', 'id' => $plan->assigned_organization_id, 'label' => $actor->organization->name];
    }

    /**
     * The per-node status map, keyed by node id.
     *
     * Resolved with two queries, never N+1: one `users` query for the nodes'
     * assignees narrowed to who the caller may see (`constrainToVisiblePeople`),
     * one `monitored_devices` query for which of those have been seen inside the
     * online window. Everything else is decided in memory.
     *
     * A node whose `user_id` is null, or names a person the caller may not see
     * (or a deleted user — absent from the visible set either way), resolves to
     * `{assigned:false, online:false, name:null}`: an unseeable assignee must
     * never leak so much as a name. A visible assignee resolves to
     * `{assigned:true, online:<seen recently>, name:<their name>}`.
     *
     * @return array<string, array{assigned: bool, online: bool, name: ?string}>
     */
    private function status(MonitoringFloorPlan $plan, User $actor): array
    {
        $nodes = $plan->layout['nodes'] ?? [];

        // The distinct, non-null assignee ids named across every node.
        $userIds = collect($nodes)
            ->pluck('user_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->unique()
            ->values();

        $visible = collect();
        $onlineIds = collect();

        if ($userIds->isNotEmpty()) {
            // constrainToVisiblePeople adds OR arms, so it is wrapped in a group
            // to keep the whereIn as an AND rather than letting an OR escape it.
            $visible = User::query()
                ->whereIn('id', $userIds->all())
                ->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor))
                ->get(['id', 'name'])
                ->keyBy('id');

            if ($visible->isNotEmpty()) {
                $onlineIds = MonitoredDevice::query()
                    ->whereIn('user_id', $visible->keys()->all())
                    ->where('last_seen_at', '>=', now()->subMinutes(self::ONLINE_WINDOW_MINUTES))
                    ->distinct()
                    ->pluck('user_id');
            }
        }

        $onlineSet = $onlineIds->flip();

        $status = [];

        foreach ($nodes as $node) {
            $nodeId = $node['id'] ?? null;

            if ($nodeId === null) {
                continue;
            }

            $userId = $node['user_id'] ?? null;
            $person = ($userId === null || $userId === '') ? null : $visible->get($userId);

            $status[$nodeId] = $person === null
                ? ['assigned' => false, 'online' => false, 'name' => null]
                : [
                    'assigned' => true,
                    'online' => $onlineSet->has($userId),
                    'name' => $person->name,
                ];
        }

        return $status;
    }
}
