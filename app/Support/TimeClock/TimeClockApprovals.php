<?php

namespace App\Support\TimeClock;

use App\Models\AuditEvent;
use App\Models\TimeClockDayApproval;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Work Hours › Daily approval (Shoval via Jose, 2026-10-22): a manager looks
 * at one day of their people's clock-ins and approves each, or adjusts a
 * shift with a reason first. Ohio's GM is the first user.
 *
 * Who approves is whoever holds `work_hours.approve`, over the people they
 * reach. Nothing names a person or a branch.
 */
class TimeClockApprovals
{
    public function __construct(
        private readonly TimeClock $clock,
        private readonly SettingsResolver $settings,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /**
     * One day as the approver sees it: everyone in view who clocked in.
     *
     * @return array{date: string, label: string, rows: list<array<string, mixed>>}
     */
    public function day(User $actor, ?string $date): array
    {
        $tz = $this->clock->viewTimezone($actor);
        $day = $this->resolveDay($date, $tz);
        $scope = ViewScope::forUser($actor)->settingsScope();
        $timeFormat = (string) $this->settings->get('time_format', $scope);
        $locale = (string) $this->settings->get('locale', $scope);

        $people = $this->clock->narrowToView($this->clock->visiblePeople($actor), $actor)
            ->where('is_active', true)->with('role:id,name')->orderBy('name')->get();
        $shifts = $this->shiftsOn($people->modelKeys(), $day);
        $approvals = TimeClockDayApproval::query()->with('approver:id,name')
            ->whereIn('user_id', $shifts->keys())->whereDate('date', $day->toDateString())->get()->keyBy('user_id');
        $adjustments = $this->adjustmentsOf($shifts->flatten()->pluck('id')->all(), $tz, $timeFormat);

        $rows = [];
        foreach ($people as $person) {
            $theirs = $shifts->get($person->id, collect());
            $window = WorkSchedule::windowFor($this->clock->scheduleFor($person), $day->copy()->setTime(12, 0));
            // A scheduled day with no punch is a no-show row; anyone else with
            // no shifts and no schedule that day is not on the list.
            if ($theirs->isEmpty() && $window === null) {
                continue;
            }
            $approval = $approvals->get($person->id);
            $worked = $theirs->sum(fn (TimeClockShift $s) => (int) Carbon::parse($s->clocked_in_at)->diffInSeconds($s->clocked_out_at ?? now(), true));
            $first = $theirs->first();
            $last = $theirs->last();

            $rows[] = [
                'person' => ['id' => $person->id, 'name' => $person->name, 'role' => $person->role?->name],
                'shifts' => $theirs->map(fn (TimeClockShift $s) => $this->clock->presentShift($s, $tz, $timeFormat))->values()->all(),
                'worked_seconds' => $worked,
                'scheduled' => $window === null ? null : [
                    'start' => $this->clock->wallClock($window['start_at'], $tz, $timeFormat),
                    'end' => $this->clock->wallClock($window['end_at'], $tz, $timeFormat),
                ],
                // Seconds after the scheduled start / before the scheduled end;
                // negative is early in / late out. Null without both ends.
                'late_seconds' => $window === null || $first === null ? null
                    : (int) Carbon::parse($window['start_at'])->diffInSeconds(Carbon::parse($first->clocked_in_at), false),
                'early_seconds' => $window === null || $last?->clocked_out_at === null ? null
                    : (int) Carbon::parse($last->clocked_out_at)->diffInSeconds(Carbon::parse($window['end_at']), false),
                'no_show' => $theirs->isEmpty(),
                'status' => $approval?->status === TimeClockDayApproval::APPROVED ? 'approved' : 'waiting',
                'approved_by' => $approval?->approver === null ? null : ['id' => $approval->approver->id, 'name' => $approval->approver->name],
                'approved_at' => $approval?->approved_at?->toIso8601String(),
                'adjustments' => $theirs->flatMap(fn (TimeClockShift $s) => $adjustments[$s->id] ?? [])->values()->all(),
            ];
        }

        $rowsC = collect($rows);

        return [
            'date' => $day->toDateString(),
            'label' => $day->copy()->locale($locale)->isoFormat('dddd, MMM D'),
            'counts' => [
                'waiting' => $rowsC->where('status', 'waiting')->where('no_show', false)->count(),
                'approved' => $rowsC->where('status', 'approved')->count(),
                'no_shows' => $rowsC->where('no_show', true)->count(),
            ],
            'rows' => $rows,
        ];
    }

    /** Approve one person's day. Approving twice is harmless; it keeps the first approver. */
    public function approve(User $actor, User $person, string $date): TimeClockDayApproval
    {
        $this->clock->assertReaches($actor, $person);
        $day = $this->resolveDay($date, $this->clock->viewTimezone($actor))->toDateString();

        $row = TimeClockDayApproval::query()->firstOrNew(['user_id' => $person->id, 'date' => $day], [
            'organization_id' => $person->organization_id,
        ]);
        if ($row->status === TimeClockDayApproval::APPROVED) {
            return $row;
        }
        $row->forceFill(['status' => TimeClockDayApproval::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now()])->save();

        $this->audit->log('time_clock.day_approved', $row, metadata: ['user_id' => $person->id, 'date' => $day]);
        $this->events->emit('time_clock.day_approved', $person, ['date' => $day, 'approved_by' => $actor->id], actor: $actor);

        return $row;
    }

    /** Approve every row of the day still waiting, in the approver's view. */
    public function approveAll(User $actor, ?string $date): int
    {
        $data = $this->day($actor, $date);
        $count = 0;
        foreach ($data['rows'] as $row) {
            if ($row['status'] === 'waiting' && ! $row['no_show']) {
                $this->approve($actor, User::query()->findOrFail($row['person']['id']), $data['date']);
                $count++;
            }
        }

        return $count;
    }

    /**
     * The days `$approver` has waiting for `$date` across their whole reach
     * (a reminder has no top-bar view). Creates the waiting rows, raising
     * `time_clock.day_awaiting_approval` once per person-day.
     *
     * @return Collection<int, TimeClockDayApproval>
     */
    public function openWaiting(User $approver, Carbon $date): Collection
    {
        $people = $this->clock->visiblePeople($approver)->where('is_active', true)->get();
        $shifts = $this->shiftsOn($people->modelKeys(), $date);
        $waiting = collect();

        foreach ($shifts->keys() as $userId) {
            $row = TimeClockDayApproval::query()->firstOrCreate(
                ['user_id' => $userId, 'date' => $date->toDateString()],
                ['organization_id' => $approver->organization_id, 'status' => TimeClockDayApproval::WAITING],
            );
            if ($row->wasRecentlyCreated) {
                $this->events->emit('time_clock.day_awaiting_approval', $people->find($userId), ['date' => $date->toDateString()]);
            }
            if ($row->status === TimeClockDayApproval::WAITING) {
                $waiting->push($row);
            }
        }

        return $waiting;
    }

    /** @return Collection<string, Collection<int, TimeClockShift>> shifts that STARTED on the day, by user */
    private function shiftsOn(array $userIds, Carbon $day): Collection
    {
        return TimeClockShift::query()
            ->whereIn('user_id', $userIds)
            ->where('clocked_in_at', '>=', $day->copy()->startOfDay()->utc())
            ->where('clocked_in_at', '<', $day->copy()->addDay()->startOfDay()->utc())
            ->orderBy('clocked_in_at')
            ->get()
            ->groupBy('user_id');
    }

    /** Adjustments (corrections with a reason) per shift, from the audit: the original is the before. */
    private function adjustmentsOf(array $shiftIds, string $tz, string $timeFormat): array
    {
        $out = [];
        $at = fn (?string $v) => $v === null ? null : $this->clock->wallClock($v, $tz, $timeFormat);
        foreach (AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.corrected')
            ->whereIn('target_id', $shiftIds)->orderBy('occurred_at')->get() as $e) {
            $reason = $e->metadata['reason'] ?? null;
            if ($reason === null) {
                continue;
            }
            $out[$e->target_id][] = [
                'shift_id' => $e->target_id,
                'was_in' => $at($e->changes['before']['clocked_in_at'] ?? null),
                'was_out' => $at($e->changes['before']['clocked_out_at'] ?? null),
                'was_ended_by' => $e->changes['before']['ended_by'] ?? null,
                'now_in' => $at($e->changes['after']['clocked_in_at'] ?? null),
                'now_out' => $at($e->changes['after']['clocked_out_at'] ?? null),
                'reason' => $reason,
                'by' => $e->actor_label,
                'at' => $e->occurred_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    private function resolveDay(?string $date, string $tz): Carbon
    {
        if ($date === null || $date === '') {
            return Carbon::now($tz)->subDay()->startOfDay();
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages(['date' => 'Choose a day as YYYY-MM-DD.']);
        }

        return Carbon::parse($date, $tz)->startOfDay();
    }
}
