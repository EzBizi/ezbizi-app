<?php

namespace App\Http\Controllers\Api;

use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\TimeClock\TimeClock;
use App\Support\TimeClock\TimeClockApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Work Hours › Daily approval (2026-10-22). Behind `work_hours.approve`.
 * An approver adjusts a shift through adjust() below, a reason required;
 * that does not need "Edit hours".
 */
class TimeClockApprovalsController extends Controller
{
    public function __construct(private readonly TimeClockApprovals $approvals) {}

    /** GET /api/v1/hr/time-clock/approvals?date=YYYY-MM-DD (yesterday by default) */
    public function index(Request $request): JsonResponse
    {
        // The tab reads; an approver reads too, with or without the tab tick.
        abort_unless(
            $request->user()->hasPermission('work_hours.approval.view') || $request->user()->hasPermission('work_hours.approve'),
            403,
        );

        $date = $request->query('date');

        return response()->json(['data' => $this->approvals->day($request->user(), is_string($date) ? $date : null)]);
    }

    /** POST /api/v1/hr/time-clock/approvals {user_id, date} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $person = User::query()->find($data['user_id']);
        abort_if($person === null, 404);

        $this->approvals->approve($request->user(), $person, $data['date']);

        return response()->json(['data' => $this->approvals->day($request->user(), $data['date'])]);
    }

    /**
     * POST /api/v1/hr/time-clock/approvals/shifts/{shift}/adjust
     * {clocked_in_at?, clocked_out_at?, reason}: new times with a reason, the
     * original kept in the audit (Daily approval, 2026-10-22).
     */
    public function adjust(Request $request, TimeClockShift $shift, TimeClock $clock): JsonResponse
    {
        $data = $request->validate([
            'clocked_in_at' => ['sometimes', 'date'],
            'clocked_out_at' => ['sometimes', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $tz = $clock->viewTimezone($request->user());

        $clock->correct(
            $request->user(),
            $shift,
            isset($data['clocked_in_at']) ? Carbon::parse($data['clocked_in_at'], $tz)->utc() : null,
            isset($data['clocked_out_at']) ? Carbon::parse($data['clocked_out_at'], $tz)->utc() : null,
            reason: trim($data['reason']),
        );
        $day = Carbon::parse($shift->clocked_in_at)->timezone($tz)->toDateString();

        return response()->json(['data' => $this->approvals->day($request->user(), $day)]);
    }

    /** POST /api/v1/hr/time-clock/approvals/all {date} */
    public function approveAll(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $this->approvals->approveAll($request->user(), $data['date']);

        return response()->json(['data' => $this->approvals->day($request->user(), $data['date'])]);
    }
}
