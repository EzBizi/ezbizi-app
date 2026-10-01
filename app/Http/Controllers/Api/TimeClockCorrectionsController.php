<?php

namespace App\Http\Controllers\Api;

use App\Models\TimeClockCorrectionRequest;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\TimeClock\TimeClock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asking for your own hours to be corrected, and answering that ask
 * (Trey Brannan's suggestion, 2026-09-30).
 *
 * Asking sits beside the punch clock, outside the Work Hours tab gate, for the
 * same reason clock in does: everybody with an account punches, so everybody
 * can say a punch is wrong. Answering is `work_hours.manage`, the grant that
 * edits a shift by hand, and approving IS that edit — TimeClock::correct, with
 * its reach check, overlap refusal and audit entry — so a request cannot do
 * anything the approver could not have done themselves.
 */
class TimeClockCorrectionsController extends Controller
{
    public function __construct(
        private readonly TimeClock $timeClock,
        private readonly NotificationDispatcher $notify,
    ) {}

    /** GET /api/v1/time-clock/corrections — your own requests, newest first. */
    public function mine(Request $request): JsonResponse
    {
        $rows = TimeClockCorrectionRequest::query()
            ->where('user_id', $request->user()->getKey())
            ->with('user:id,name')
            ->latest('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->row($r, $request->user()))->all()]);
    }

    /** POST /api/v1/time-clock/corrections */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'shift_id' => ['required', 'string'],
            'clocked_in_at' => ['required', 'date'],
            'clocked_out_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        // Yours or nothing: 404 for a colleague's shift, as for a missing one.
        $shift = TimeClockShift::query()->where('user_id', $user->getKey())->find($data['shift_id']);

        if ($shift === null) {
            return response()->json(['message' => 'That shift no longer exists.'], 404);
        }

        $tz = $this->timeClock->viewTimezone($user);
        $in = Carbon::parse($data['clocked_in_at'], $tz)->utc();
        // A running shift is asked about for its start only (Jose,
        // 2026-10-01); its end is the person's own Clock Out. Null is
        // "unchanged" to correct(), so approving never closes it.
        $out = isset($data['clocked_out_at']) && $shift->clocked_out_at !== null
            ? Carbon::parse($data['clocked_out_at'], $tz)->utc()
            : null;

        if ($in->gt(now()) || ($out !== null && $out->gt(now()))) {
            throw ValidationException::withMessages(['clocked_in_at' => 'A shift cannot be in the future.']);
        }

        if ($out !== null && $out->lte($in)) {
            throw ValidationException::withMessages(['clocked_out_at' => 'Clock out must be after clock in.']);
        }

        $reason = trim($data['reason']);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say what happened, in a sentence.']);
        }

        $row = DB::transaction(function () use ($user, $shift, $in, $out, $reason) {
            $waiting = TimeClockCorrectionRequest::query()
                ->where('shift_id', $shift->getKey())
                ->where('status', TimeClockCorrectionRequest::PENDING)
                ->lockForUpdate()
                ->exists();

            if ($waiting) {
                throw ValidationException::withMessages([
                    'shift_id' => 'A correction for this shift is already waiting for approval.',
                ]);
            }

            return TimeClockCorrectionRequest::create([
                'user_id' => $user->getKey(),
                'shift_id' => $shift->getKey(),
                'was_in_at' => $shift->clocked_in_at,
                'was_out_at' => $shift->clocked_out_at,
                'clocked_in_at' => $in,
                'clocked_out_at' => $out,
                'reason' => $reason,
                'status' => TimeClockCorrectionRequest::PENDING,
            ]);
        });

        foreach ($this->approversOf($user) as $approver) {
            $this->notify->dispatch(
                title: "{$user->name} asked for a Work Hours correction",
                body: $reason,
                subject: $row,
                onlyTo: $approver,
            );
        }

        return response()->json(['data' => $this->row($row->load('user:id,name'), $user)], 201);
    }

    /** GET /api/v1/hr/time-clock/corrections — waiting, for the people you manage. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('correct', TimeClockShift::class);

        return response()->json(['data' => $this->pendingFor($request->user())]);
    }

    /**
     * Waiting requests from the people $actor may correct. Also carried on the
     * Work Hours week (TimeClockController::week) so the pane that shows them
     * needs no second request.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(User $actor): array
    {
        return TimeClockCorrectionRequest::query()
            ->where('status', TimeClockCorrectionRequest::PENDING)
            ->whereIn('user_id', $this->timeClock->visiblePeople($actor)->select('users.id'))
            ->with('user:id,name')
            ->oldest('created_at')
            ->get()
            ->map(fn ($r) => $this->row($r, $actor))
            ->all();
    }

    /** POST /api/v1/hr/time-clock/corrections/{correction}/approve */
    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, TimeClockCorrectionRequest::APPROVED);
    }

    /** POST /api/v1/hr/time-clock/corrections/{correction}/deny */
    public function deny(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, TimeClockCorrectionRequest::DENIED);
    }

    private function decide(Request $request, string $id, string $status): JsonResponse
    {
        $this->authorize('correct', TimeClockShift::class);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
        $actor = $request->user();

        $row = DB::transaction(function () use ($id, $status, $note, $actor) {
            $row = TimeClockCorrectionRequest::query()->lockForUpdate()->find($id);

            if ($row === null || $row->status !== TimeClockCorrectionRequest::PENDING) {
                throw (new ModelNotFoundException)->setModel(TimeClockCorrectionRequest::class);
            }

            // Somebody outside your reach is a 404, as everywhere in Work Hours.
            $this->timeClock->assertReaches($actor, $row->user);

            if ($status === TimeClockCorrectionRequest::APPROVED) {
                $this->timeClock->correct($actor, $row->shift, $row->clocked_in_at, $row->clocked_out_at);
            }

            $row->forceFill([
                'status' => $status,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            return $row;
        });

        $this->notify->dispatch(
            title: $status === TimeClockCorrectionRequest::APPROVED
                ? 'Your Work Hours correction was approved'
                : 'Your Work Hours correction was declined',
            body: $note,
            subject: $row,
            onlyTo: $row->user,
        );

        return response()->json(['data' => $this->row($row->load('user:id,name'), $actor)]);
    }

    /** @return iterable<User> active people who hold `work_hours.manage` and reach $person */
    private function approversOf(User $person): iterable
    {
        foreach (User::query()->where('is_active', true)->whereKeyNot($person->getKey())->get() as $candidate) {
            if (! $candidate->hasPermission('work_hours.manage')) {
                continue;
            }

            try {
                $this->timeClock->assertReaches($candidate, $person);
            } catch (ModelNotFoundException) {
                continue;
            }

            yield $candidate;
        }
    }

    /** @return array<string, mixed> times as "Y-m-d H:i" in the reader's Work Hours zone */
    private function row(TimeClockCorrectionRequest $row, User $reader): array
    {
        $tz = $this->timeClock->viewTimezone($reader);
        $local = fn (?Carbon $at) => $at?->copy()->setTimezone($tz)->format('Y-m-d H:i');

        return [
            'id' => $row->id,
            'shift_id' => $row->shift_id,
            'user' => ['id' => $row->user_id, 'name' => $row->user?->name],
            'was_in' => $local($row->was_in_at),
            'was_out' => $local($row->was_out_at),
            'clocked_in' => $local($row->clocked_in_at),
            'clocked_out' => $local($row->clocked_out_at),
            'reason' => $row->reason,
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
