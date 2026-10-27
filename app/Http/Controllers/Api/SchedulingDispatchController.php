<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\SchedulingTechnician;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Audit\AuditLogger;
use App\Support\Scheduling\JobTechnicians;
use App\Support\Scoping\ViewScope;
use App\Support\Workiz\WorkizJobWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Dispatching from the Schedule board: assign, unassign, move.
 *
 * EzBizi is the master (Jose, 2026-10-27, replacing 2026-09-25's "Workiz
 * stays the truth"): every write lands in `jobs` / `job_technicians` first and
 * answers from there. Workiz is then told, when the job and the technician
 * have a Workiz twin; a refusal or an outage there is reported
 * and never undoes EzBizi's change. A tenant with no Workiz at all dispatches
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
        $this->mirror($row, fn (WorkizJobWriter $w, WorkizJob $wj) => ($m = $this->workizMember($tech)) ? $w->assign($wj, $m) : null);

        return response()->json(['data' => ['id' => $row->id]]);
    }

    /** POST /api/v1/scheduling/board/jobs/{job}/unassign {member_id} */
    public function unassign(Request $request, string $job): JsonResponse
    {
        $row = $this->job($request, $job);
        $tech = $this->technician($request, $row);
        JobTechnicians::unassign($row, $tech);
        $this->audit->log('scheduling.job_unassigned', $row, metadata: ['technician' => $tech->name]);
        $this->mirror($row, fn (WorkizJobWriter $w, WorkizJob $wj) => ($m = $this->workizMember($tech)) ? $w->unassign($wj, $m) : null);

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

        // Wall clock in, wall clock out: parsed and formatted in one zone, so nothing converts.
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['start_time']}", 'UTC');
        $minutes = (int) ($row->duration_minutes ?: $start->diffInMinutes(CarbonImmutable::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['end_time']}", 'UTC')));
        $was = $row->getRawOriginal('scheduled_for');
        $row->forceFill(['scheduled_for' => $start->format('Y-m-d H:i:s'), 'duration_minutes' => $minutes])->save();
        $this->audit->log('scheduling.job_rescheduled', $row, metadata: ['from' => $was, 'to' => $start->format('Y-m-d H:i')]);
        $end = $start->addMinutes($minutes)->format('Y-m-d H:i:s');
        $this->mirror($row, fn (WorkizJobWriter $w, WorkizJob $wj) => $w->reschedule($wj, $start->format('Y-m-d H:i:s'), $end));

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

    private function workizMember(SchedulingTechnician $tech): ?WorkizTeamMember
    {
        return $tech->workiz_member_id === null ? null : WorkizTeamMember::find($tech->workiz_member_id);
    }

    /**
     * Tells Workiz after EzBizi has saved, when the job has a Workiz twin; a
     * refusal or outage is reported, never returned.
     * ponytail: inline until WorkizMirror::queue (Workiz session) lands.
     */
    private function mirror(Job $job, \Closure $write): void
    {
        $twin = WorkizJob::query()->where('job_id', $job->id)->first();
        if ($twin === null) {
            return;
        }
        try {
            $result = $write(app(WorkizJobWriter::class), $twin);
            if (($result['refused'] ?? null) !== null) {
                report(new \RuntimeException("Workiz mirror refused job {$twin->remote_id}: {$result['refused']}"));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
