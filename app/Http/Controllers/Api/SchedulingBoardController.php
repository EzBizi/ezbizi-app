<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\JobTechnician;
use App\Models\SchedulingTechnician;
use App\Models\SchedulingTimeSlot;
use App\Models\ServiceArea;
use App\Support\Scheduling\Openings;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Schedule board: one day, technicians down the side, time slots across,
 * each job in the cell of the technician doing it and the window it is in.
 * Jobs nobody has been assigned to yet sit in a list of their own.
 *
 * Jose, 2026-09-25, with a screenshot of A1 Tools' board: "start building the
 * schedule screen as well ... as usual with our theme, design". The shape is
 * A1's — `getSchedulingBoard()` in api/crm/scheduling.php — and so are its
 * definitions: a job on the board is one with a technician on it that day; an
 * unassigned job is one that day with nobody on it, cancelled and done left
 * out.
 *
 * ── Where the facts come from ───────────────────────────────────
 *
 * EzBizi's own `jobs`, `job_technicians` and `scheduling_technicians`, scoped
 * to the franchises in view (Jose, 2026-10-27: EzBizi is the master, Workiz a
 * mirror). Nothing here reads Workiz, so a tenant without it gets the same
 * board. Assign, unassign and move are SchedulingDispatchController, which
 * writes EzBizi first and then mirrors to Workiz where a twin exists.
 */
class SchedulingBoardController extends Controller
{
    /** GET /api/v1/scheduling/board?date=YYYY-MM-DD[&franchise_id=] */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'franchise_id' => ['nullable', 'uuid'],
        ]);

        // A switched-off branch is not on the board (Jose, 2026-11-10).
        $inView = ViewScope::forUser($request->user())->switchedOnFranchiseIds();
        $franchiseIds = ($data['franchise_id'] ?? null) !== null
            ? array_values(array_intersect($inView, [$data['franchise_id']]))
            : $inView;

        $franchises = Franchise::whereIn('id', $franchiseIds)->pluck('name', 'id');

        // The windows of the companies on the board, each its own or the one
        // it inherits (per company since 2026-10-28).
        $owners = Franchise::whereIn('id', $franchiseIds)->pluck('company_id')->unique()
            ->map(fn ($c) => SchedulingTimeSlot::ownerFor($c))->unique()->values();
        $windows = SchedulingTimeSlot::query()
            ->where(fn ($q) => $q->whereIn('company_id', $owners->filter()->all())
                ->when($owners->contains(null), fn ($q) => $q->orWhereNull('company_id')))
            ->orderBy('start_time')->get()
            ->map(fn (SchedulingTimeSlot $w) => [
                'start_time' => substr((string) $w->start_time, 0, 5),
                'end_time' => substr((string) $w->end_time, 0, 5),
            ])->unique(fn ($w) => $w['start_time'].$w['end_time'])->values()->all();

        // EzBizi's own jobs and roster (Jose, 2026-10-27: EzBizi is the
        // master). Workiz is a mirror; nothing here reads it.
        $jobs = Job::query()
            ->with(['customer:id,name,address_line1,city', 'serviceArea:id,name'])
            ->whereIn('franchise_id', $franchiseIds)
            ->where('scheduled_for', '>=', $data['date'].' 00:00:00')
            ->where('scheduled_for', '<=', $data['date'].' 23:59:59')
            ->orderBy('scheduled_for')
            ->get();
        $teams = JobTechnician::query()->whereIn('job_id', $jobs->pluck('id'))->get()->groupBy('job_id');

        $technicians = [];
        $scheduled = [];
        $unassigned = [];

        $roster = SchedulingTechnician::query()->with('areas:id,name')
            ->whereIn('franchise_id', $franchiseIds)
            ->where(fn ($q) => $q->where('is_active', true)->where('is_field_tech', true)
                ->orWhereIn('id', $teams->flatten()->pluck('technician_id')))
            ->orderBy('name')->get();
        foreach ($roster as $tech) {
            $technicians[$tech->id] = [
                'id' => $tech->id,
                // What dispatching sends.
                'member_id' => $tech->id,
                'name' => trim((string) $tech->name),
                'franchise_id' => $tech->franchise_id,
                'franchise_name' => $franchises[$tech->franchise_id] ?? null,
                // The assign picker offers only technicians covering the area.
                'service_areas' => $tech->areas->pluck('name')->values()->all(),
                'jobs' => 0,
            ];
        }

        foreach ($jobs as $job) {
            if (in_array(mb_strtolower(trim((string) $job->status)), Openings::freeStatuses(), true)) {
                continue;
            }

            $time = substr((string) $job->getRawOriginal('scheduled_for'), 11, 5);
            $team = ($teams[$job->id] ?? collect())->pluck('technician_id')->filter(fn ($id) => isset($technicians[$id]))->values();
            $card = [
                'id' => $job->id,
                'job_id' => $job->id,
                'franchise_id' => $job->franchise_id,
                'number' => (string) $job->number,
                'customer' => $job->customer?->name,
                'job_type' => $job->job_type,
                'status' => $job->status,
                'time' => $time === '00:00' ? null : $time,
                // The window the time falls in, half-open; null is no time set
                // or a time outside every window.
                'window' => $this->windowOf($time, $windows),
                'address' => $job->customer?->address_line1,
                'city' => $job->customer?->city,
                'service_area' => $job->serviceArea?->name,
                'franchise_name' => $franchises[$job->franchise_id] ?? null,
                'team' => $team->map(fn ($id) => ['member_id' => $id, 'name' => $technicians[$id]['name']])->all(),
            ];

            if ($team->isEmpty()) {
                $unassigned[] = $card;

                continue;
            }
            foreach ($team as $id) {
                $technicians[$id]['jobs']++;
                $scheduled[] = $card + ['technician_id' => $id];
            }
        }

        // Busy technicians first, then by name, so the day reads from the top.
        $technicians = collect($technicians)
            ->sortBy([['jobs', 'desc'], ['name', 'asc']])
            ->values()->all();

        return response()->json([
            'data' => [
                'date' => $data['date'],
                // The location filter: franchises in view that have
                // technicians. From here, not the session, because A-1 carries
                // empty duplicate franchises that would each draw a choice.
                'franchises' => Franchise::whereIn('id', SchedulingTechnician::query()
                    ->whereIn('franchise_id', $inView)
                    ->distinct()->pluck('franchise_id'))
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Franchise $f) => ['id' => $f->id, 'name' => $f->name])->all(),
                'windows' => $windows,
                'technicians' => $technicians,
                'scheduled' => $scheduled,
                'unassigned' => $unassigned,
                // Whether this person may dispatch; the board draws no button
                // that would only be refused.
                'can_dispatch' => $request->user()->hasPermission('scheduling.edit'),
                'counts' => [
                    'scheduled' => count(array_unique(array_column($scheduled, 'id'))),
                    'unassigned' => count($unassigned),
                ],
            ],
        ]);
    }

    /** @param  array<int, array{start_time: string, end_time: string}>  $windows */
    private function windowOf(string $time, array $windows): ?int
    {
        if ($time === '00:00') {
            return null;
        }

        foreach ($windows as $i => $w) {
            if ($time >= $w['start_time'] && $time < $w['end_time']) {
                return $i;
            }
        }

        return null;
    }
}
