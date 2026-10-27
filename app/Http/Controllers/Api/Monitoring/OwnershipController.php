<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringFloorPlan;
use App\Models\User;
use App\Support\Access\ScopeReach;
use App\Support\Monitoring\MonitoringView;
use App\Support\Monitoring\ScheduleSummary;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The ownership chart — Monitoring's General tab (Jose, 2026-09-22).
 *
 *   "an Ownership Chart, where basically the Information Displayed on Map is
 *    presented in a sort of table fashion"
 *
 * The same office the floor plan draws, as rows: who, what they do, which desk,
 * which machine, whether they are on, the hours they are expected, where they
 * report and which office plan they sit on.
 *
 * ── It is a chart of PEOPLE, not of devices ──────────────────────
 *
 * DevicesController answers "what machines are reporting". This answers "who
 * works here and what did we give them", which is a different question with a
 * different subject: somebody with a desk and no computer belongs on it, and a
 * spare machine nobody is signed in on does not. So the query starts from the
 * people this actor may see and hangs everything else off that.
 *
 * ── Three columns are not Monitoring's to give away ──────────────
 *
 * PC name comes from `specs`, which is gated on `monitoring.specs.view` here
 * exactly as it is on the roster — seeing who is online has never included
 * seeing what they are running.
 *
 * SCHEDULE HOURS is HR's. `User::work_schedule` is a §9 ACTIVITY entry — "which
 * weekdays and hours they are expected to work" — and the module that owns that
 * disclosure is Human Resources, whose Work Hours tab is `hr.hours.view`. So
 * this asks for THAT grant rather than inventing a Monitoring key that would
 * let an administrator route around HR's boundary without noticing. A viewer
 * without it gets the column absent, not empty: absent is "we are not telling
 * you", empty would read as "this person has no hours set".
 *
 * DESK and OFFICE come from the floor plans this actor can reach, which is the
 * same §3 constraint the Map tab uses. A person sitting on a plan outside the
 * viewer's reach has no desk here rather than a desk with no name.
 */
class OwnershipController extends Controller
{
    /**
     * Same window the roster and the floor plan use for "on right now".
     *
     * Its own copy rather than an import, for the reason PcSpecsTab's is its
     * own: three surfaces that happen to agree today, each free to be tuned.
     * They are kept in step by MonitoringOwnershipTest asserting this one.
     */
    private const ONLINE_WITHIN_MINUTES = 5;

    public function __construct(
        private readonly ScopeReach $reach,
        private readonly SettingsResolver $settings,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $canSeeSpecs = $actor->hasPermission('monitoring.specs.view');
        $canSeeHours = $actor->hasPermission('work_hours.general.view');

        $people = User::query()
            ->where('is_active', true) // removed/erased people are not listed
            ->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor))
            // Reach says who this person may see; the view says who is in the
            // company they are standing in. See MonitoringView.
            ->tap(fn (Builder $q) => MonitoringView::people($q, $actor))
            ->with('role:id,name')
            ->orderBy('name')
            ->get();

        $devices = $this->newestDeviceFor($people->modelKeys());
        $seats = $this->seatsFor($actor);
        $fallbackHours = $this->settings->get('ops.business_hours', $actor->organization);

        $cutoff = now()->subMinutes(self::ONLINE_WITHIN_MINUTES);

        $rows = $people->map(function (User $person) use (
            $devices, $seats, $canSeeSpecs, $canSeeHours, $fallbackHours, $cutoff
        ) {
            $device = $devices[$person->getKey()] ?? null;
            $seat = $seats[$person->getKey()] ?? null;
            $seen = $device?->last_seen_at;

            $row = [
                'user' => ['id' => $person->getKey(), 'name' => $person->name],
                'role' => $person->role?->name,
                'desk' => $seat['desk'] ?? null,
                'office' => $seat['office'] ?? null,
                // Where that desk name lives, so the chart can rename it in
                // place. Present only when this actor can see the seat at all,
                // which is the same §3 reach that produced it.
                'plan_id' => $seat['plan_id'] ?? null,
                'node_id' => $seat['node_id'] ?? null,
                'online' => $seen !== null && $seen->greaterThanOrEqualTo($cutoff),
                'last_seen_at' => $seen?->toIso8601String(),
                'assignment' => $this->assignmentLabel($person),
            ];

            if ($canSeeSpecs) {
                $specs = $device?->specs;
                $row['computer_name'] = is_array($specs) ? ($specs['computer_name'] ?? null) : null;
            }

            if ($canSeeHours) {
                // The person's own schedule, or the organization's business
                // hours where nobody has typed one. The fallback matters: a
                // column that is blank until HR fills a form reads as "this
                // person has no hours", when what is true is "the office
                // hours apply".
                //
                // TWO COLUMNS, NOT THE RAW MAP. It used to send the structure
                // and the chart printed it, which came out as a paragraph per
                // row in two different shapes — see ScheduleSummary for why
                // there are two. A chart is read across, so each cell gets one
                // short fact.
                $schedule = $person->work_schedule ?: $fallbackHours;

                $row['work_days'] = ScheduleSummary::workDays($schedule);
                $row['shift'] = ScheduleSummary::shift($schedule);
            }

            return $row;
        })
            // Online first, then offline; names stay A-Z inside each (stable sort).
            ->sortByDesc('online')->values()->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /monitoring/ownership/desks — the desks nobody sits at.
     *
     * What the chart offers when somebody taps an empty Desk cell. Only FREE
     * ones: a desk already occupied is a seat somebody would have to be moved
     * out of, and quietly displacing a colleague is not what "set this
     * person's desk" means.
     *
     * Creating a desk is deliberately NOT here — see
     * FloorPlansController::assignNode for why a table cannot invent a
     * position. When this comes back empty the chart says so and points at
     * the Map, which is the honest answer rather than an empty picker.
     */
    public function desks(Request $request): JsonResponse
    {
        $actor = $request->user();

        $plans = MonitoringFloorPlan::query();
        $this->reach->constrain($plans, $actor);
        // A desk offered in the picker is somewhere the person is being FILED,
        // and §3 files on the view. seatsFor() below stays on reach: where a
        // listed person already sits is a fact about them, not a filing.
        MonitoringView::plans($plans, $actor);

        $free = [];

        foreach ($plans->orderBy('name')->get(['id', 'name', 'layout']) as $plan) {
            foreach ($plan->layout['nodes'] ?? [] as $node) {
                $userId = $node['user_id'] ?? null;

                if (is_string($userId) && $userId !== '') {
                    continue;
                }

                $free[] = [
                    'plan_id' => $plan->getKey(),
                    'node_id' => (string) ($node['id'] ?? ''),
                    'label' => (string) ($node['label'] ?? ''),
                    'office' => $plan->name,
                ];
            }
        }

        return response()->json(['data' => $free]);
    }

    /**
     * The newest reporting device per person, keyed by user id.
     *
     * One query for the whole page rather than one per row — the chart lists
     * everybody, so a per-row lookup is an N+1 that grows with the company.
     *
     * @param  array<int, string>  $userIds
     * @return array<string, MonitoredDevice>
     */
    private function newestDeviceFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $newest = [];

        MonitoredDevice::query()
            ->whereIn('user_id', $userIds)
            ->orderByDesc('last_seen_at')
            ->get(['user_id', 'last_seen_at', 'specs'])
            ->each(function (MonitoredDevice $device) use (&$newest) {
                // Ordered newest first, so the FIRST row seen for a person is
                // theirs. A device that never beat sorts last under DESC.
                $newest[$device->user_id] ??= $device;
            });

        return $newest;
    }

    /**
     * Desk and office per person, from the floor plans this actor may reach.
     *
     * A person can appear on more than one plan; the first wins and that is
     * deliberate rather than arbitrary — a chart that showed "Front desk,
     * Spare desk" for one person would be reporting a data problem as a
     * value. The Map tab is where that is visible and fixable.
     *
     * @return array<string, array{desk: string, office: string, plan_id: string, node_id: string}>
     */
    private function seatsFor(User $actor): array
    {
        $seats = [];

        $plans = MonitoringFloorPlan::query();
        $this->reach->constrain($plans, $actor);

        foreach ($plans->get(['id', 'name', 'layout']) as $plan) {
            foreach ($plan->layout['nodes'] ?? [] as $node) {
                $userId = $node['user_id'] ?? null;

                if (! is_string($userId) || $userId === '' || isset($seats[$userId])) {
                    continue;
                }

                $seats[$userId] = [
                    'desk' => (string) ($node['label'] ?? ''),
                    'office' => $plan->name,
                    'plan_id' => $plan->getKey(),
                    'node_id' => (string) ($node['id'] ?? ''),
                ];
            }
        }

        return $seats;
    }

    /**
     * Where this person reports, in the words the rest of the app uses.
     *
     * ── The bug this fixes, reported by Jose 2026-09-23 ──────────────
     *
     * "a lot of users are not showing as empty on assignment for some reason."
     * They were assigned — at ORGANIZATION level. This read the franchise, then
     * the company, and returned null for anything else, so an org-level
     * assignment rendered as the same em dash as no assignment at all.
     *
     * Two different facts under one symbol, and the more misleading direction:
     * the chart exists partly to show who has NOT been placed, so rendering
     * "placed at the top of the business" as "not placed" invites somebody to
     * go and fix a row that is already correct.
     *
     * Narrowest first, because a franchise says more than the company above it
     * and the company says more than the organization above that — the same
     * order HrPeopleController and Invitation use for the same three columns.
     */
    private function assignmentLabel(User $person): ?string
    {
        $assignment = $person->assignments()
            ->with([
                'assignedOrganization:id,name',
                'assignedCompany:id,name',
                'assignedFranchise:id,name',
            ])
            ->first();

        if ($assignment === null) {
            // Genuinely unplaced. This is the em dash the chart is FOR.
            return null;
        }

        return $assignment->assignedFranchise?->name
            ?? $assignment->assignedCompany?->name
            ?? $assignment->assignedOrganization?->name;
    }
}
