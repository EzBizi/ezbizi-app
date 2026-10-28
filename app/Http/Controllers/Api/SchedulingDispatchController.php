<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\SchedulingTechnician;
use App\Support\Audit\AuditLogger;
use App\Support\Scheduling\JobMove;
use App\Support\Scheduling\JobTechnicians;
use App\Support\Scoping\ViewScope;
use App\Support\Workiz\WorkizMirror;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dispatching from the Schedule board: assign, unassign, move.
 *
 * EzBizi is the master (Jose, 2026-10-27, replacing 2026-09-25's "Workiz
 * stays the truth"): every write lands in `jobs` / `job_technicians` first and
 * answers from there. Workiz is then told through WorkizMirror's queue, which
 * skips a job with no Workiz copy and audits a refusal; it never undoes
 * EzBizi's change. A tenant with no Workiz at all dispatches
 * the same way.
 *
 * Gated on `scheduling.edit` on top of the Schedule tab: seeing the board is
 * not permission to change the calendar.
 */
class SchedulingDispatchController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** POST /api/v1/scheduling/board/jobs/{job}/assign {member_id} */
    public function assign(Request $request, string $job): JsonResponse
    {
        $row = $this->job($request, $job);
        $tech = $this->technician($request, $row);
        JobTechnicians::assign($row, $tech);
        $this->audit->log('scheduling.job_assigned', $row, metadata: ['technician' => $tech->name]);
        WorkizMirror::queue($row, 'assign', ['workiz_member_id' => $tech->workiz_member_id]);

        return response()->json(['data' => ['id' => $row->id]]);
    }

    /** POST /api/v1/scheduling/board/jobs/{job}/unassign {member_id} */
    public function unassign(Request $request, string $job): JsonResponse
    {
        $row = $this->job($request, $job);
        $tech = $this->technician($request, $row);
        JobTechnicians::unassign($row, $tech);
        $this->audit->log('scheduling.job_unassigned', $row, metadata: ['technician' => $tech->name]);
        WorkizMirror::queue($row, 'unassign', ['workiz_member_id' => $tech->workiz_member_id]);

        return response()->json(['data' => ['id' => $row->id]]);
    }

    /**
     * POST /api/v1/scheduling/board/jobs/{job}/reschedule {date, start_time, end_time}
     *
     * The job starts at the window's start and keeps its own length; a job
     * with no length on record takes the window's.
     */
    public function reschedule(Request $request, string $job): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);
        $row = $this->job($request, $job);
        JobMove::to($row, $data['date'], $data['start_time'], $data['end_time']);

        return response()->json(['data' => ['id' => $row->id]]);
    }

    private function job(Request $request, string $id): Job
    {
        return Job::query()->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())->findOrFail($id);
    }

    /** A technician on the job's own branch; anyone else is not found. */
    private function technician(Request $request, Job $job): SchedulingTechnician
    {
        $id = $request->validate(['member_id' => ['required', 'uuid']])['member_id'];

        return SchedulingTechnician::query()->where('franchise_id', $job->franchise_id)->findOrFail($id);
    }
}
