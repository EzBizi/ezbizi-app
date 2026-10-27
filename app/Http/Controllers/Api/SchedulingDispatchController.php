<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Scoping\ViewScope;
use App\Support\Workiz\WorkizJobWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dispatching from the Schedule board: assign, unassign, move. Every write
 * goes TO Workiz too — Jose, 2026-09-25: "it should be possible on both
 * with the sync". Since 2026-10-27 EzBizi is the master and Workiz a mirror
 * until it is disconnected (WorkizMirror).
 *
 * All three are WorkizJobWriter's (the Workiz session's), which re-reads the
 * job from Workiz and lands it in the mirror before returning, so the board
 * reloads Workiz's own answer rather than guessing one. A refusal comes back
 * as Workiz's sentence, verbatim, with a 422 — "User is not assigned to select
 * service area" is Workiz's rule that a technician only takes jobs in an area
 * they cover, and the dispatcher needs those words, not ours.
 *
 * Gated on `scheduling.edit` on top of the Schedule tab: seeing the board is
 * not permission to change A-1's calendar. No role held it on 2026-09-25, so
 * nobody but an owner dispatches until Jose ticks it.
 */
class SchedulingDispatchController extends Controller
{
    /** POST /api/v1/scheduling/board/jobs/{job}/assign {member_id} */
    public function assign(Request $request, string $job): JsonResponse
    {
        $row = $this->job($request, $job);

        return $this->answer(app(WorkizJobWriter::class)->assign($row, $this->member($request, $row)));
    }

    /** POST /api/v1/scheduling/board/jobs/{job}/unassign {member_id} */
    public function unassign(Request $request, string $job): JsonResponse
    {
        $row = $this->job($request, $job);

        return $this->answer(app(WorkizJobWriter::class)->unassign($row, $this->member($request, $row)));
    }

    /**
     * POST /api/v1/scheduling/board/jobs/{job}/reschedule {date, start_time, end_time}
     *
     * The job starts at the window's start and keeps its own length. Workiz's
     * update moves only the start when given only the start (measured), so a
     * job's length would change silently unless the end is sent too. A job
     * with no length on record ends with the window.
     */
    public function reschedule(Request $request, string $job): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        $row = $this->job($request, $job);

        // Wall clock in, wall clock out: parsed and formatted in one zone, so
        // nothing converts. See WorkizJobWriter on why a real instant is refused.
        $at = fn (?string $raw) => $raw === null ? null : CarbonImmutable::createFromFormat('Y-m-d H:i:s', $raw, 'UTC');
        $start = $at("{$data['date']} {$data['start_time']}:00");
        $was = $at($row->getRawOriginal('scheduled_for'));
        $wasEnd = $at($row->getRawOriginal('scheduled_end'));
        $minutes = ($was !== null && $wasEnd !== null) ? (int) $was->diffInMinutes($wasEnd) : 0;
        $end = $minutes > 0 ? $start->addMinutes($minutes) : $at("{$data['date']} {$data['end_time']}:00");

        return $this->answer(app(WorkizJobWriter::class)->reschedule(
            $row,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ));
    }

    private function job(Request $request, string $id): WorkizJob
    {
        return WorkizJob::query()
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->findOrFail($id);
    }

    /** A technician on the job's own branch account; anyone else is not found. */
    private function member(Request $request, WorkizJob $job): WorkizTeamMember
    {
        $id = $request->validate(['member_id' => ['required', 'uuid']])['member_id'];

        return WorkizTeamMember::query()->where('franchise_id', $job->franchise_id)->findOrFail($id);
    }

    /** @param  array{job: ?WorkizJob, refused: ?string}  $result */
    private function answer(array $result): JsonResponse
    {
        if ($result['refused'] !== null) {
            return response()->json(['message' => $result['refused']], 422);
        }

        return response()->json(['data' => ['id' => $result['job']?->id]]);
    }
}
