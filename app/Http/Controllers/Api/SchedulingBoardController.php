<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\SchedulingTimeSlot;
use App\Models\ServiceArea;
use App\Support\Scheduling\Openings;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
 * ── Reads here; writes through Workiz ───────────────────────────
 *
 * This reads. Assign, unassign and move are SchedulingDispatchController,
 * which writes TO Workiz through the Workiz module's WorkizJobWriter — Jose,
 * 2026-09-25: "it should be possible on both with the sync". So a card carries
 * its mirror row (`job_id`) and who is on it (`team`), and a technician carries
 * the areas Workiz lets them take (`service_areas`).
 *
 * ── Where the facts come from ───────────────────────────────────
 *
 * ponytail: jobs from `workiz_jobs`, technicians from `workiz_team_members`
 * (the Workiz session's mirror), both scoped to the franchises in view. Every
 * A-1 job arrives from Workiz; when EzBizi's own `jobs` carry assignments this
 * reads those instead. A job's technicians are its payload `Team` — Workiz's
 * own record of who is on it.
 *
 * ── Why this board and A1's disagree, and why this one is right ──
 *
 * Measured 2026-09-25 for that day: A1's board said 17 scheduled and 56
 * unscheduled; this one says 58 assigned and 8 unassigned. A1 counts only
 * assignments made INSIDE A1 Tools (`crm_job_assignments`: exactly 17). Every
 * job it listed as unscheduled that was checked — Tonya Yates, Sheila Content,
 * Lindsay Esposito, Michael Monreal — has a technician in Workiz, where A-1
 * actually assigns. So "unassigned" here means nobody is on it in Workiz, not
 * that nobody used this screen.
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

        $inView = ViewScope::forUser($request->user())->franchiseIds();
        $franchiseIds = ($data['franchise_id'] ?? null) !== null
            ? array_values(array_intersect($inView, [$data['franchise_id']]))
            : $inView;

        $organization = $request->user()->organization_id;
        $franchises = Franchise::whereIn('id', $franchiseIds)->pluck('name', 'id');

        $windows = SchedulingTimeSlot::query()->orderBy('start_time')->get()
            ->map(fn (SchedulingTimeSlot $w) => [
                'start_time' => substr((string) $w->start_time, 0, 5),
                'end_time' => substr((string) $w->end_time, 0, 5),
            ])->values()->all();

        $jobs = DB::table('workiz_jobs')
            ->where('organization_id', $organization)
            ->whereIn('franchise_id', $franchiseIds)
            ->where('scheduled_for', '>=', $data['date'].' 00:00:00')
            ->where('scheduled_for', '<=', $data['date'].' 23:59:59')
            ->orderBy('scheduled_for')
            ->get(['id', 'remote_id', 'franchise_id', 'service_area', 'status', 'scheduled_for', 'city', 'address', 'payload']);

        $technicians = [];
        $scheduled = [];
        $unassigned = [];

        foreach (DB::table('workiz_team_members')
            ->where('organization_id', $organization)
            ->whereIn('franchise_id', $franchiseIds)
            ->where('is_active', true)
            ->where('is_field_tech', true)
            ->orderBy('name')
            ->get(['id', 'franchise_id', 'remote_id', 'name', 'service_areas']) as $tech) {
            $technicians[$tech->franchise_id.'|'.$tech->remote_id] = [
                'id' => $tech->franchise_id.'|'.$tech->remote_id,
                // The mirror row, which is what dispatching sends.
                'member_id' => $tech->id,
                'name' => trim((string) $tech->name),
                'franchise_id' => $tech->franchise_id,
                'franchise_name' => $franchises[$tech->franchise_id] ?? null,
                // Workiz refuses a technician on a job outside these (measured
                // 2026-09-25), so the assign picker offers only covering ones.
                'service_areas' => json_decode((string) $tech->service_areas, true) ?: [],
                'jobs' => 0,
            ];
        }

        foreach ($jobs as $job) {
            if (in_array(mb_strtolower(trim((string) $job->status)), Openings::FREE_STATUSES, true)) {
                continue;
            }

            $payload = is_string($job->payload) ? (json_decode($job->payload, true) ?: []) : (array) $job->payload;
            $time = substr((string) $job->scheduled_for, 11, 5);
            $card = [
                'id' => $job->remote_id,
                // The mirror row, which is what dispatching sends.
                'job_id' => $job->id,
                'franchise_id' => $job->franchise_id,
                'number' => (string) ($payload['SerialId'] ?? ''),
                'customer' => trim(($payload['FirstName'] ?? '').' '.($payload['LastName'] ?? '')) ?: null,
                'job_type' => $payload['JobType'] ?? null,
                'status' => $job->status,
                'time' => $time === '00:00' ? null : $time,
                // The window the time falls in, half-open; null is no time set
                // or a time outside every window.
                'window' => $this->windowOf($time, $windows),
                'address' => $job->address,
                'city' => $job->city,
                'service_area' => $job->service_area,
                'franchise_name' => $franchises[$job->franchise_id] ?? null,
            ];

            $team = array_values(array_filter(
                is_array($payload['Team'] ?? null) ? $payload['Team'] : [],
                fn ($t) => is_array($t) && isset($t['id']),
            ));

            // Who is on it, by mirror row where the mirror has them — the key
            // an Unassign needs.
            $card['team'] = array_map(fn ($t) => [
                'member_id' => $technicians[$job->franchise_id.'|'.$t['id']]['member_id'] ?? null,
                'name' => trim((string) ($t['Name'] ?? 'Unknown')),
            ], $team);

            if ($team === []) {
                $unassigned[] = $card;

                continue;
            }

            foreach ($team as $member) {
                $key = $job->franchise_id.'|'.$member['id'];

                // A technician on a job but missing from the mirror still gets
                // a row: hiding their work because a sync lagged would show a
                // quiet day that is not one.
                $technicians[$key] ??= [
                    'id' => $key,
                    'member_id' => null,
                    'name' => trim((string) ($member['Name'] ?? 'Unknown')),
                    'franchise_id' => $job->franchise_id,
                    'franchise_name' => $franchises[$job->franchise_id] ?? null,
                    'service_areas' => [],
                    'jobs' => 0,
                ];
                $technicians[$key]['jobs']++;

                $scheduled[] = $card + ['technician_id' => $key];
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
                'franchises' => Franchise::whereIn('id', DB::table('workiz_team_members')
                    ->where('organization_id', $organization)
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
