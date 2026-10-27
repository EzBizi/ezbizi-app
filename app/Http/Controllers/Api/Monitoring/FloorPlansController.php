<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringFloorPlan;
use App\Models\User;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Monitoring\MonitoringView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The org's floor plans — the Monitoring "General" tab's endpoints
 * (docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md).
 * `index` lists the plans a caller may see; `show` returns one plan's layout
 * plus a resolved per-node online/offline status; store/update/destroy write
 * them back, and `assignablePeople` is the assign picker's roster (Task 7a).
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
     * window the client draws with — `deviceOnlineWithin` (client/lib/screens/
     * monitoring/device_display.dart) is `Duration(minutes: 5)`. Kept as its
     * own copy here for the same reason the client keeps its own: the
     * threshold that colours a dot is an independent knob from the one that
     * FIRES `monitoring.device_offline` (DeviceOfflineWorker's cutoff), and
     * they are free to agree without being wired together. "Seen within the
     * window" mirrors the client's `now - last_seen_at <= deviceOnlineWithin`.
     */
    private const ONLINE_WINDOW_MINUTES = 5;

    /**
     * The most people the assign picker is handed in one answer. A cap, not a
     * page: an org with more than this many people needs a searching endpoint,
     * not a longer list, and the picker searches what it holds. Five hundred is
     * comfortably above every EzBizi org and small enough to send at once.
     */
    private const ASSIGNABLE_PEOPLE_LIMIT = 500;

    /** The scope types a plan may name, mapped to Assignment's constants via ScopeReach::TYPES. */
    private const SCOPE_TYPES = ['organization', 'company', 'franchise'];

    public function __construct(
        private readonly ScopeReach $reach,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/monitoring/floor-plans */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringFloorPlan::class);

        $actor = $request->user();

        $query = MonitoringFloorPlan::query()->with(['company:id,name', 'franchise:id,name']);
        $this->reach->constrain($query, $actor);
        // The LIST follows the view. show() and reachable() below stay on
        // reach — see MonitoringView for why a lookup by id is not narrowed.
        MonitoringView::plans($query, $actor);

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
     * GET /api/v1/monitoring/assignable-people
     *
     * The people an editor may put on a tile — `{id, name}` and nothing else.
     *
     * ── Why this exists rather than reusing `hr/people` ────────────
     *
     * `hr/people` is gated on HR grants, which a Monitoring map editor has no
     * reason to hold; pointing the assign picker at it would either force an
     * unrelated permission onto every editor or hand them a 403 with nothing to
     * choose from. So the picker gets its own list, and the list is defined by
     * the rule the ASSIGNMENT already obeys: the same grant the write needs
     * (`monitoring.map.manage`, via the `create` policy method — only an editor
     * has any use for it), over the same predicate `validatedLayout` refuses a
     * node against (`constrainToVisiblePeople`). One rule, two endpoints: a name
     * offered here always saves, and a name missing here would always have been
     * refused. Nothing beyond id and name is returned — the picker needs a label
     * to show and an id to store, and a roster is not a place to widen what
     * Monitoring discloses about a person.
     *
     * Inactive people are NOT filtered out, deliberately: the write endpoint
     * does not refuse them either, and a picker narrower than the rule it is a
     * picker for is how the two quietly stop agreeing.
     *
     * The predicate is wrapped in a closure group (`where(fn ($q) => ...)`)
     * rather than applied to the query directly. `constrainToVisiblePeople` adds
     * OR arms, so any sibling condition placed beside them at the top level
     * would be escapable by an arm — the same wrapping `status()` and
     * `validatedLayout()` use, and the bug that was fixed there. There is no
     * sibling condition here today; the group is what keeps that true for
     * whichever `where` is added next.
     */
    public function assignablePeople(Request $request): JsonResponse
    {
        $this->authorize('create', MonitoringFloorPlan::class);

        $actor = $request->user();

        $people = User::query()
            ->where('is_active', true) // removed/erased people are not listed
            ->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor))
            ->orderBy('name')
            ->limit(self::ASSIGNABLE_PEOPLE_LIMIT)
            ->get(['id', 'name']);

        return response()->json([
            'data' => $people->map(fn (User $person) => [
                'id' => $person->getKey(),
                'name' => $person->name,
            ])->all(),
        ]);
    }

    /**
     * GET /api/v1/monitoring/floor-plans/scope-options
     *
     * The levels this editor may CREATE a plan at - `{type, id, label}` rows,
     * in org-tree order, and nothing else.
     *
     * ---- Why the client cannot work this out for itself ---------------
     *
     * The create panel used to build its own list off the session: an
     * "Organization" row from `me.organization`, then `me.companies` and
     * `me.franchises`. Two of those three are reach; `me.organization` is not.
     * It is the org the person is IN, which everybody has, so every actor was
     * offered - and preselected on - an Organization row, while `store`'s
     * `ScopeReach::assertCovers` needs `canActOnOrganization` and 422s a
     * company or franchise manager for choosing it. The panel then told them
     * they were "only offered the levels you can act on", which was false.
     *
     * So the list comes from the same object the write is judged by:
     * `ScopeReach::optionsFor`, which NULLS `organization` for precisely this
     * reason ("a company manager who could hand out an organization-wide
     * assignment would be granting reach over companies they cannot see
     * themselves"). One rule, two endpoints, the same shape the assign picker
     * and `validatedLayout` keep: everything offered here saves, and anything
     * missing here would have been refused.
     *
     * Gated on `create` (`monitoring.map.manage`) - the grant the POST needs -
     * rather than `viewAny`: a viewer who cannot create a plan has no use for
     * the levels they could create one at.
     *
     * `label` is the place's plain NAME, the same way `scope()` presents one;
     * the panel words the row ("Company - A-1 Chimney"), including the org's
     * own word for a franchise (§8), which is a client-side fact.
     */
    public function scopeOptions(Request $request): JsonResponse
    {
        $this->authorize('create', MonitoringFloorPlan::class);

        $options = $this->reach->optionsFor($request->user());

        $rows = [];

        if ($options['organization'] !== null) {
            $rows[] = [
                'type' => 'organization',
                'id' => $options['organization']['id'],
                'label' => $options['organization']['name'],
            ];
        }

        foreach ($options['companies'] as $company) {
            $rows[] = ['type' => 'company', 'id' => $company['id'], 'label' => $company['name']];
        }

        foreach ($options['franchises'] as $franchise) {
            $rows[] = ['type' => 'franchise', 'id' => $franchise['id'], 'label' => $franchise['name']];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/v1/monitoring/floor-plans
     *
     * Create a plan at a scope the caller may act on. Two gates, both §4:
     * the policy answers "may this role add plans at all" (`monitoring.map.manage`),
     * and `ScopeReach::assertCovers` answers "may this actor place a plan at THAT
     * level" — a franchise manager naming an org- or sibling-company scope is not
     * forbidden, they chose a field they are not entitled to, so it is a 422 on
     * `scope` (assertCovers' own split). The chosen scope is written to the
     * `assigned_*` trio; `assigned_organization_id` is stamped by the model.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MonitoringFloorPlan::class);

        $actor = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', 'array'],
            'scope.type' => ['required', 'string', Rule::in(self::SCOPE_TYPES)],
            // id is required for company/franchise; for organization it is the
            // tenant org and may be omitted (the model stamps it either way).
            'scope.id' => ['nullable', 'string'],
            'layout' => ['sometimes', 'array'],
        ]);

        $scopeType = $data['scope']['type'];
        $scopeId = $scopeType === 'organization'
            ? ($data['scope']['id'] ?? $actor->organization_id)
            : ($data['scope']['id'] ?? null);

        // A scope above the actor's level is a 422 on `scope`, never a 403.
        $this->reach->assertCovers($actor, $scopeType, $scopeId, 'scope');

        $layout = $this->validatedLayout($data['layout'] ?? $this->emptyLayout(), $actor);

        $plan = MonitoringFloorPlan::create([
            'name' => $data['name'],
            'assigned_company_id' => $scopeType === 'company' ? $scopeId : null,
            'assigned_franchise_id' => $scopeType === 'franchise' ? $scopeId : null,
            'layout' => $layout,
            'created_by' => $actor->getKey(),
        ]);

        // §2.2: map.manage writes records, so who created which plan when is an
        // audited fact. The scope names the level, not the whole layout.
        $this->audit->log('monitoring.map.created', $plan, [
            'name' => $plan->name,
            'scope' => $scopeType,
        ]);

        return response()->json(['data' => $this->present($plan->fresh(), $actor)], Response::HTTP_CREATED);
    }

    /**
     * PUT /api/v1/monitoring/floor-plans/{id}
     *
     * Rename and/or replace the layout of a plan the caller may reach. The plan
     * is resolved §3-scoped (constrain + find + 404) exactly like `show`, so a
     * plan outside reach reads as "does not exist". Scope is NOT changed by an
     * update in v1 — a `scope` key is simply not accepted, so it cannot move a
     * plan to a level the actor could not otherwise choose.
     *
     * The layout's assignees are validated against what is ALREADY STORED, not
     * from scratch: see `storedAssignees` and `validatedLayout`.
     */
    /**
     * PUT /monitoring/floor-plans/{id}/nodes/{node}/label
     *
     * Rename ONE desk, without sending the whole layout back.
     *
     * Jose, 2026-09-23: "I should be able to edit the desk name myself" — from
     * the ownership chart, which is where somebody notices a desk is called
     * the wrong thing. That chart holds a row per person and no geometry, so
     * asking it to PUT the entire plan would mean fetching a document it does
     * not otherwise need, editing one string inside it, and sending it back —
     * a read-modify-write that would quietly overwrite anything a second
     * person moved on the map in between.
     *
     * So this touches the one node and leaves every other key in the layout
     * exactly as stored.
     *
     * Same grant as any other edit to a plan (`update` -> monitoring.map.manage)
     * and the same §3 constraint, because a desk name is part of a plan and
     * renaming one is editing it. Deliberately NOT behind the Map TAB: the
     * person doing this is looking at the chart, and an administrator may
     * reasonably grant "may edit the map" without granting the map's own tab.
     */
    /**
     * PUT /monitoring/floor-plans/{id}/nodes/{node}/user
     *
     * Seat somebody at a desk, or clear it — from the ownership chart.
     *
     * Jose, 2026-09-23: "you forgot that there are empty fields, and thus no
     * edit button for them". Renaming shipped first and only reached people
     * who ALREADY had a desk, which left the chart able to show that twelve
     * people are unplaced and unable to do anything about any of them. The
     * column that exists to surface a gap has to be the column that closes it.
     *
     * ── Why this assigns rather than creating ────────────────────────
     *
     * A desk is a tile at a position on a plan. Creating one from a table
     * means inventing an x and a y, and a desk that appears in a corner
     * because that cell happened to be free is a lie about where somebody
     * sits — visible to everyone who opens the Map, and attributed to
     * whoever last touched the plan. So desks are drawn on the Map, where
     * position is a thing you can see, and the chart matches PEOPLE to the
     * desks that exist.
     *
     * ── The assignee is scope-checked, not merely validated ──────────
     *
     * `constrainToVisiblePeople` is the same predicate `validatedLayout` puts
     * on every node assignee, so a person who can be seated here could have
     * been seated by a full layout save, and one who cannot would have been
     * refused by both. One rule, two endpoints.
     */
    public function assignNode(Request $request, string $id, string $nodeId): JsonResponse
    {
        $this->authorize('update', MonitoringFloorPlan::class);

        $actor = $request->user();
        $plan = $this->reachable($actor, $id);

        $data = $request->validate([
            'user_id' => ['present', 'nullable', 'string'],
        ]);

        $userId = $data['user_id'];

        if ($userId !== null && ! $this->canSee($actor, $userId)) {
            // 422 rather than 404: the plan and the node are both real and
            // reachable, so this is a refusal about the PERSON named, and
            // saying so is what lets the chart explain itself.
            abort(422, 'That person is not one you can assign.');
        }

        $layout = $plan->layout;
        $nodes = $layout['nodes'] ?? [];
        $found = false;

        foreach ($nodes as $i => $node) {
            if (($node['id'] ?? null) !== $nodeId) {
                continue;
            }

            $nodes[$i]['user_id'] = $userId;
            $found = true;
            break;
        }

        if (! $found) {
            abort(404);
        }

        $layout['nodes'] = $nodes;
        $plan->layout = $layout;
        $plan->save();

        $this->audit->log('monitoring.map.updated', $plan, [
            'fields' => ['layout'],
            'node' => $nodeId,
            'assigned' => $userId,
        ]);

        return response()->json(['data' => ['user_id' => $userId]]);
    }

    /** Whether this actor may put that person on a plan at all. */
    private function canSee(User $actor, string $userId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor))
            ->exists();
    }

    public function renameNode(Request $request, string $id, string $nodeId): JsonResponse
    {
        $this->authorize('update', MonitoringFloorPlan::class);

        $actor = $request->user();
        $plan = $this->reachable($actor, $id);

        $data = $request->validate([
            // 60 because this renders in a table cell and on a map tile, and
            // a desk called a paragraph breaks both.
            //
            // NOTE THE ASYMMETRY, measured rather than assumed: the full-layout
            // validator (validatedLayout) only checks `is_string` and puts NO
            // length bound on a node label, so a longer one can still arrive
            // by saving the whole plan from the Map. This path is the stricter
            // of the two. Tightening the other one is not free — a plan
            // already carrying a long label would start failing its next save
            // — so it is named here rather than changed in passing.
            'label' => ['required', 'string', 'max:60'],
        ]);

        $layout = $plan->layout;
        $nodes = $layout['nodes'] ?? [];
        $found = false;

        foreach ($nodes as $i => $node) {
            if (($node['id'] ?? null) !== $nodeId) {
                continue;
            }

            $nodes[$i]['label'] = $data['label'];
            $found = true;
            break;
        }

        if (! $found) {
            // Same word §3 uses for a plan outside reach: a node id that names
            // nothing and one on a plan this actor cannot see must look alike.
            abort(404);
        }

        $layout['nodes'] = $nodes;
        $plan->layout = $layout;
        $plan->save();

        $this->audit->log('monitoring.map.updated', $plan, [
            'fields' => ['layout'],
            'node' => $nodeId,
        ]);

        return response()->json(['data' => ['label' => $data['label']]]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize('update', MonitoringFloorPlan::class);

        $actor = $request->user();

        $plan = $this->reachable($actor, $id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'layout' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('name', $data)) {
            $plan->name = $data['name'];
        }

        if (array_key_exists('layout', $data)) {
            $plan->layout = $this->validatedLayout($data['layout'], $actor, $this->storedAssignees($plan));
        }

        $changed = array_keys($plan->getDirty());
        $plan->save();

        // Records WHICH fields changed, not the layout itself — "who edited this
        // plan and when" is the audited fact; the geometry is on the row already.
        $this->audit->log('monitoring.map.updated', $plan, ['fields' => $changed]);

        return response()->json(['data' => $this->present($plan->fresh(), $actor)]);
    }

    /**
     * DELETE /api/v1/monitoring/floor-plans/{id}
     *
     * Remove a plan the caller may reach (§3-scoped find + 404). The name is
     * captured before deletion so "who removed which plan and when" stays legible
     * on the audit row that outlives it.
     */
    public function destroy(Request $request, string $id): Response
    {
        $this->authorize('delete', MonitoringFloorPlan::class);

        $actor = $request->user();

        $plan = $this->reachable($actor, $id);

        $name = $plan->name;
        $plan->delete();

        $this->audit->log('monitoring.map.deleted', $plan, ['name' => $name]);

        return response()->noContent();
    }

    /**
     * Resolve a plan §3-scoped, or 404 — the write side of `show`'s find.
     *
     * The reach predicate is built onto the query BEFORE the id lookup, so a plan
     * the caller may not reach returns null and 404s exactly as one that never
     * existed does; a row outside reach must never read as "forbidden".
     */
    private function reachable(User $actor, string $id): MonitoringFloorPlan
    {
        $query = MonitoringFloorPlan::query()->with(['company:id,name', 'franchise:id,name']);
        $this->reach->constrain($query, $actor);

        $plan = $query->find($id);

        if ($plan === null) {
            abort(404);
        }

        return $plan;
    }

    /** The default layout a plan created with no `layout` starts from — an empty grid. */
    private function emptyLayout(): array
    {
        return ['grid' => ['cols' => 20, 'rows' => 20], 'walls' => [], 'nodes' => []];
    }

    /**
     * Validate the `layout` document's shape, and refuse any node assignee the
     * actor may not see.
     *
     * The shape is `{grid:{cols:int,rows:int}, walls:[{x1,y1,x2,y2 ints}],
     * nodes:[{id:string, x:int, y:int, label:string, user_id:?string}]}`; a
     * malformed shape is a 422 on `layout`. The load-bearing check is the last:
     * every non-null node `user_id` must be a person `constrainToVisiblePeople`
     * returns — one query for the whole set — so a plan tile cannot be assigned
     * to somebody outside the actor's reach (which would leak that person the
     * moment the plan is read). Any id absent from the visible set is a 422.
     *
     * ---- Stored vs incoming: only a NEW assignee is checked ----------
     *
     * `show` returns `layout` verbatim, an assignee the caller cannot see
     * included (an opaque id and nothing else - no name, no state, so no §3
     * breach), and the editor deep-copies that document and PUTs it back. So
     * validating every non-null `user_id` from scratch would make a plan the
     * caller may REACH permanently unsavable the moment one of its tiles names
     * somebody outside their reach: dragging an unrelated tile, or renaming the
     * plan, would 422 on a node they cannot see, cannot fix and cannot even
     * identify. `$storedAssignees` is `[nodeId => user_id]` read off the plan's
     * current layout, and an incoming `user_id` that EQUALS the stored one FOR
     * THAT SAME NODE ID is exempt: it is being echoed back, not chosen.
     *
     * Everything else still goes through the check. A `store` passes no map at
     * all, so every assignee on a new plan must be visible; and REASSIGNING an
     * existing tile is an incoming id that differs from the stored one for that
     * node, so it is refused exactly as before. Exempting the stored id also
     * means the fix cannot quietly UNASSIGN the person instead - the id is
     * written back to the layout untouched.
     *
     * @param  array<string, ?string>  $storedAssignees  [nodeId => user_id] as currently stored, empty on create
     *
     * @return array{grid: array{cols: int, rows: int}, walls: list<array>, nodes: list<array>}
     *
     * @throws ValidationException
     */
    private function validatedLayout(mixed $layout, User $actor, array $storedAssignees = []): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['layout' => $message]);

        if (! is_array($layout)) {
            $fail('The layout must be an object.');
        }

        $grid = $layout['grid'] ?? null;
        if (! is_array($grid) || ! $this->isInt($grid['cols'] ?? null) || ! $this->isInt($grid['rows'] ?? null)) {
            $fail('The layout grid must have integer cols and rows.');
        }

        $walls = $layout['walls'] ?? [];
        if (! is_array($walls)) {
            $fail('The layout walls must be a list.');
        }

        $cleanWalls = [];
        foreach ($walls as $wall) {
            if (! is_array($wall)
                || ! $this->isInt($wall['x1'] ?? null) || ! $this->isInt($wall['y1'] ?? null)
                || ! $this->isInt($wall['x2'] ?? null) || ! $this->isInt($wall['y2'] ?? null)) {
                $fail('Each wall must have integer x1, y1, x2 and y2.');
            }

            $cleanWalls[] = [
                'x1' => (int) $wall['x1'], 'y1' => (int) $wall['y1'],
                'x2' => (int) $wall['x2'], 'y2' => (int) $wall['y2'],
            ];
        }

        $nodes = $layout['nodes'] ?? [];
        if (! is_array($nodes)) {
            $fail('The layout nodes must be a list.');
        }

        $cleanNodes = [];
        $assigneeIds = [];
        foreach ($nodes as $node) {
            if (! is_array($node)
                || ! $this->isNonEmptyString($node['id'] ?? null)
                || ! $this->isInt($node['x'] ?? null) || ! $this->isInt($node['y'] ?? null)
                || ! is_string($node['label'] ?? null)) {
                $fail('Each node must have a string id, integer x and y, and a string label.');
            }

            $userId = $node['user_id'] ?? null;
            if ($userId !== null && ! is_string($userId)) {
                $fail('A node user_id must be a string or null.');
            }
            if ($userId === '') {
                $userId = null;
            }
            // Only an assignee that is NEW FOR THIS NODE is checked. An id
            // that equals what is already stored under the same node id was
            // put there by somebody with wider reach and is being echoed back
            // untouched, not chosen now - see the block below the loop.
            if ($userId !== null && ($storedAssignees[(string) $node['id']] ?? null) !== $userId) {
                $assigneeIds[$userId] = true;
            }

            $cleanNodes[] = [
                'id' => (string) $node['id'],
                'x' => (int) $node['x'],
                'y' => (int) $node['y'],
                'label' => (string) $node['label'],
                'user_id' => $userId,
            ];
        }

        // CRUCIAL (§3): a tile may only be assigned to somebody the actor can
        // see. Collect the distinct assignee ids and assert each is returned by
        // constrainToVisiblePeople — the same predicate the read side hides an
        // unseeable assignee behind. Any id not in the visible set is refused.
        $assigneeIds = array_keys($assigneeIds);
        if ($assigneeIds !== []) {
            $visible = User::query()
                ->whereIn('id', $assigneeIds)
                ->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor))
                ->pluck('id')
                ->all();

            if (count($visible) !== count($assigneeIds)) {
                $fail('You can only assign people you can see.');
            }
        }

        return [
            'grid' => ['cols' => (int) $grid['cols'], 'rows' => (int) $grid['rows']],
            'walls' => $cleanWalls,
            'nodes' => $cleanNodes,
        ];
    }

    /**
     * The plan's assignees as they stand RIGHT NOW, `[nodeId => user_id]`.
     *
     * The exemption `validatedLayout` reads: a node id whose incoming assignee
     * matches the value here was not chosen by this actor, it was already on
     * the plan. Nodes with no assignee are left out, so a null incoming id can
     * never match anything and unassigning is not a check either way.
     *
     * @return array<string, string>
     */
    private function storedAssignees(MonitoringFloorPlan $plan): array
    {
        $stored = [];

        foreach ($plan->layout['nodes'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $nodeId = $node['id'] ?? null;
            $userId = $node['user_id'] ?? null;

            if (is_string($nodeId) && $nodeId !== '' && is_string($userId) && $userId !== '') {
                $stored[$nodeId] = $userId;
            }
        }

        return $stored;
    }

    /** True only for a genuine integer — a numeric string is not one (a coord must be typed). */
    private function isInt(mixed $value): bool
    {
        return is_int($value);
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /**
     * The show-shape for one plan — the same body `show` returns, reused by the
     * write endpoints so a create/update round-trips exactly what a later read
     * would. Includes the resolved per-node status.
     *
     * @return array<string, mixed>
     */
    private function present(MonitoringFloorPlan $plan, User $actor): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'scope' => $this->scope($plan, $actor),
            'layout' => $plan->layout,
            'status' => $this->status($plan, $actor),
        ];
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
     * one `monitored_devices` query for every device those people own, newest
     * beat first. Everything else — who is online, when each was last seen, and
     * whose machine has reported specs — is decided in memory off that one
     * ordered result set. Adding a person to a plan adds no query; adding a
     * hundred adds none either.
     *
     * A node whose `user_id` is null, or names a person the caller may not see
     * (or a deleted user — absent from the visible set either way), resolves to
     * every field empty: `{assigned:false, online:false, name:null,
     * last_seen_at:null}` (plus `specs:null` when the key is sent at all). An
     * unseeable assignee must never leak so much as a name, and the sidebar
     * that reads this must have nothing to draw a status from.
     *
     * ── Why `specs` is a KEY that can be absent ───────────────────────
     *
     * `specs` is gated on `monitoring.specs.view`, the same grant
     * DevicesController::present gates it on, and it is withheld the same way:
     * the KEY is absent, not present-and-null. Present-and-null would tell a
     * caller who lacks the grant that specs exist to be granted — and here it
     * would be worse than on the devices roster, because null is also what a
     * permitted caller sees for a person whose machine runs no agent. One
     * absent key and one null value keep "you may not see this" and "there is
     * nothing to see" from collapsing into the same answer; the client tells
     * them apart with `containsKey`, exactly as PcSpecsTab's device row does.
     *
     * @return array<string, array{assigned: bool, online: bool, name: ?string, last_seen_at: ?string, specs?: ?array}>
     */
    private function status(MonitoringFloorPlan $plan, User $actor): array
    {
        $nodes = $plan->layout['nodes'] ?? [];

        $canSeeSpecs = $actor->hasPermission('monitoring.specs.view');

        // The distinct, non-null assignee ids named across every node.
        $userIds = collect($nodes)
            ->pluck('user_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->unique()
            ->values();

        $visible = collect();

        /**
         * Per visible person: their newest beat, and the specs of their
         * newest device that has any. Both resolved from the one ordered
         * devices query below, never per node.
         *
         * @var array<string, array{seen: ?\Illuminate\Support\Carbon, specs: ?array}>
         */
        $devices = [];

        if ($userIds->isNotEmpty()) {
            // constrainToVisiblePeople adds OR arms, so it is wrapped in a group
            // to keep the whereIn as an AND rather than letting an OR escape it.
            $visible = User::query()
                ->whereIn('id', $userIds->all())
                ->where('is_active', true)
                ->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor))
                ->get(['id', 'name'])
                ->keyBy('id');

            if ($visible->isNotEmpty()) {
                // Newest beat first, so the FIRST row seen for a person is their
                // most recent one and the first carrying specs is their most
                // recently seen machine that has any. A device that has never
                // beaten sorts last (NULL last under DESC) and is skipped by the
                // null checks either way.
                $rows = MonitoredDevice::query()
                    ->whereIn('user_id', $visible->keys()->all())
                    ->orderByDesc('last_seen_at')
                    ->get(['user_id', 'last_seen_at', 'specs']);

                foreach ($rows as $row) {
                    $userId = $row->user_id;
                    $entry = $devices[$userId] ?? ['seen' => null, 'specs' => null];

                    if ($entry['seen'] === null && $row->last_seen_at !== null) {
                        $entry['seen'] = $row->last_seen_at;
                    }

                    // An empty specs array is "reported nothing", not "reported":
                    // it would render as a row of blanks, so it is skipped and a
                    // later device with real numbers can still answer.
                    if ($entry['specs'] === null && is_array($row->specs) && $row->specs !== []) {
                        $entry['specs'] = $row->specs;
                    }

                    $devices[$userId] = $entry;
                }
            }
        }

        $cutoff = now()->subMinutes(self::ONLINE_WINDOW_MINUTES);

        $status = [];

        foreach ($nodes as $node) {
            $nodeId = $node['id'] ?? null;

            if ($nodeId === null) {
                continue;
            }

            $userId = $node['user_id'] ?? null;
            $person = ($userId === null || $userId === '') ? null : $visible->get($userId);

            if ($person === null) {
                $entry = [
                    'assigned' => false,
                    'online' => false,
                    'name' => null,
                    'last_seen_at' => null,
                ];

                if ($canSeeSpecs) {
                    $entry['specs'] = null;
                }

                $status[$nodeId] = $entry;

                continue;
            }

            $seen = $devices[$userId]['seen'] ?? null;

            $entry = [
                'assigned' => true,
                'online' => $seen !== null && $seen->greaterThanOrEqualTo($cutoff),
                'name' => $person->name,
                'last_seen_at' => $seen?->toIso8601String(),
            ];

            if ($canSeeSpecs) {
                $entry['specs'] = $devices[$userId]['specs'] ?? null;
            }

            $status[$nodeId] = $entry;
        }

        return $status;
    }
}
