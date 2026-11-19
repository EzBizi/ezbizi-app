<?php

namespace App\Support\TimeClock;

use App\Models\PayRate;
use App\Models\PayrollWeekLine;
use App\Models\TimeClockDayApproval;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Work Hours › Payroll (Merav via Jose, 2026-11-19): a week of each person's
 * pay — the rate from HR's pay_rates in effect that week, the hours from the
 * shifts, the manager's bonus and deductions — with what is missing said out
 * loud. Managers approve it Sunday evening; Merav gets the company Monday.
 *
 * Overtime (desk, 2026-11-19): hours over 40 are shown and NOT paid extra
 * until Jose or Merav say 1.5x. Weekly pay is rate x hours, or the salary.
 */
class TimeClockPayroll
{
    public const OVERTIME_AFTER_HOURS = 40;

    public const WARNINGS = [
        'no_rate' => 'No pay rate',
        'open_shift' => 'Still clocked in',
        'capped' => 'A shift was closed by the maximum hours',
        'no_show' => 'A scheduled day has no punch',
        'unapproved_days' => 'Days not approved',
    ];

    public function __construct(
        private readonly TimeClock $clock,
        private readonly SettingsResolver $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The week as a manager sees it: everyone in view who worked or is paid.
     *
     * @return array{week_start: string, label: string, rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function week(User $actor, ?string $weekStart): array
    {
        $tz = $this->clock->viewTimezone($actor);
        $start = $this->resolveWeek($actor, $weekStart, $tz);
        $people = $this->clock->narrowToView($this->clock->visiblePeople($actor), $actor)
            ->where('is_active', true)->with('role:id,name')->orderBy('name')->get();

        return $this->sheet($people, $start, $tz);
    }

    /**
     * The same sheet for any set of people — the Monday company copy uses it
     * with everybody in the organization.
     *
     * @param  Collection<int, User>  $people
     */
    public function sheet(Collection $people, Carbon $start, string $tz): array
    {
        $end = $start->copy()->addWeek();
        $shifts = TimeClockShift::query()->whereIn('user_id', $people->modelKeys())
            ->where('clocked_in_at', '>=', $start->copy()->utc())->where('clocked_in_at', '<', $end->copy()->utc())
            ->orderBy('clocked_in_at')->get()->groupBy('user_id');
        $lines = PayrollWeekLine::query()->with('approver:id,name')->whereIn('user_id', $people->modelKeys())
            ->whereDate('week_start', $start->toDateString())->get()->keyBy('user_id');

        $rows = [];
        foreach ($people as $person) {
            $theirs = $shifts->get($person->id, collect());
            $rate = PayRate::rateOn($person->id, $start->copy()->addDays(6)->toDateString());
            if ($theirs->isEmpty() && $rate === null) {
                continue;
            }
            $line = $lines->get($person->id);
            $seconds = $theirs->sum(fn (TimeClockShift $s) => (int) Carbon::parse($s->clocked_in_at)->diffInSeconds($s->clocked_out_at ?? now(), true));
            $hours = round($seconds / 3600, 2);
            $bonus = (float) ($line?->bonus ?? 0);
            $deductions = (float) ($line?->deductions ?? 0);
            $base = self::basePay($rate, $hours);

            $rows[] = [
                'person' => ['id' => $person->id, 'name' => $person->name, 'role' => $person->role?->name,
                    'branch' => $this->clock->placeOf($person)->getAttribute('name')],
                'basis' => $rate?->basis,
                'rate' => $rate === null ? null : (float) $rate->amount,
                'period' => $rate?->period,
                'hours' => $hours,
                'overtime_hours' => max(0, round($hours - self::OVERTIME_AFTER_HOURS, 2)),
                'bonus' => $bonus,
                'deductions' => $deductions,
                'note' => $line?->note,
                'pay' => $base === null ? null : round($base + $bonus - $deductions, 2),
                'warnings' => $this->warningsFor($person, $theirs, $rate, $start, $tz),
                'status' => $line?->approved_at === null ? 'waiting' : 'approved',
                'approved_by' => $line?->approver === null ? null : ['id' => $line->approver->id, 'name' => $line->approver->name],
                'approved_at' => $line?->approved_at?->toIso8601String(),
            ];
        }

        $all = collect($rows);

        return [
            'week_start' => $start->toDateString(),
            'label' => $start->format('M j').' – '.$start->copy()->addDays(6)->format('M j, Y'),
            'rows' => $rows,
            'totals' => [
                'people' => $all->count(),
                'hours' => round($all->sum('hours'), 2),
                'pay' => round($all->sum(fn ($r) => $r['pay'] ?? 0), 2),
                'waiting' => $all->where('status', 'waiting')->count(),
                'with_warnings' => $all->filter(fn ($r) => $r['warnings'] !== [])->count(),
            ],
        ];
    }

    /** Bonus, deductions and a note for one person's week. Changing it un-approves it. */
    public function adjust(User $actor, User $person, ?string $weekStart, array $values): PayrollWeekLine
    {
        $this->clock->assertReaches($actor, $person);
        $line = $this->lineFor($person, $this->resolveWeek($actor, $weekStart, $this->clock->viewTimezone($actor)));
        $before = ['bonus' => $line->bonus, 'deductions' => $line->deductions, 'note' => $line->note];
        $line->fill(array_intersect_key($values, $before))->forceFill(['approved_by' => null, 'approved_at' => null])->save();
        $this->audit->log('time_clock.payroll_adjusted', $line, metadata: ['user_id' => $person->id, 'week_start' => $line->week_start->toDateString()]);

        return $line;
    }

    public function approve(User $actor, User $person, ?string $weekStart): PayrollWeekLine
    {
        $this->clock->assertReaches($actor, $person);
        $line = $this->lineFor($person, $this->resolveWeek($actor, $weekStart, $this->clock->viewTimezone($actor)));
        if ($line->approved_at === null) {
            $line->forceFill(['approved_by' => $actor->id, 'approved_at' => now()])->save();
            $this->audit->log('time_clock.payroll_approved', $line, metadata: ['user_id' => $person->id, 'week_start' => $line->week_start->toDateString()]);
        }

        return $line;
    }

    /** Approve every waiting row in the approver's view. */
    public function approveAll(User $actor, ?string $weekStart): int
    {
        $week = $this->week($actor, $weekStart);
        $count = 0;
        foreach ($week['rows'] as $row) {
            if ($row['status'] === 'waiting') {
                $this->approve($actor, User::query()->findOrFail($row['person']['id']), $week['week_start']);
                $count++;
            }
        }

        return $count;
    }

    /** The week a payroll is about: the one yesterday fell in (Sunday evening: this week; Monday: last week). */
    public function resolveWeek(User $actor, ?string $weekStart, string $tz): Carbon
    {
        if ($weekStart !== null && $weekStart !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            throw ValidationException::withMessages(['week' => 'Choose a week as YYYY-MM-DD.']);
        }
        $startsOn = (string) $this->settings->get('time_clock.week_starts_on', $actor->organization);
        $day = $weekStart ?: Carbon::now($tz)->subDay()->toDateString();

        return $this->clock->weekStartFor($day, $tz, $startsOn);
    }

    /** rate x hours, or the salary as a week; null without a rate. */
    public static function basePay(?PayRate $rate, float $hours): ?float
    {
        if ($rate === null) {
            return null;
        }
        $amount = (float) $rate->amount;

        return round(match ($rate->basis === 'hourly' ? 'hour' : $rate->period) {
            'hour' => $amount * $hours,
            'week' => $amount,
            'month' => $amount * 12 / 52,
            default => $amount / 52,
        }, 2);
    }

    /** @return list<string> warning keys, see WARNINGS */
    private function warningsFor(User $person, Collection $shifts, ?PayRate $rate, Carbon $start, string $tz): array
    {
        $cap = (int) $this->settings->get('time_clock.max_hours', $person->organization) * 3600;
        $worked = $shifts->map(fn (TimeClockShift $s) => Carbon::parse($s->clocked_in_at)->timezone($tz)->toDateString())->unique();
        $schedule = $this->clock->scheduleFor($person);
        $noShow = false;
        for ($d = $start->copy(); $d->lt($start->copy()->addWeek()) && $d->lt(Carbon::now($tz)->startOfDay()); $d->addDay()) {
            if (WorkSchedule::windowFor($schedule, $d->copy()->setTime(12, 0)) !== null && ! $worked->contains($d->toDateString())) {
                $noShow = true;
                break;
            }
        }
        $approval = (bool) $this->settings->get('time_clock.approval_enabled', $this->clock->placeOf($person));
        $approved = $approval ? TimeClockDayApproval::query()->where('user_id', $person->id)
            ->whereIn('date', $worked->all())->where('status', TimeClockDayApproval::APPROVED)->count() : 0;

        return array_values(array_keys(array_filter([
            'no_rate' => $rate === null,
            'open_shift' => $shifts->contains(fn (TimeClockShift $s) => $s->clocked_out_at === null),
            'capped' => $shifts->contains(fn (TimeClockShift $s) => $s->clocked_out_at !== null && $s->ended_by === TimeClockShift::ENDED_BY_SCHEDULE
                && Carbon::parse($s->clocked_in_at)->diffInSeconds($s->clocked_out_at, true) >= $cap - 60),
            'no_show' => $noShow,
            'unapproved_days' => $approval && $approved < $worked->count(),
        ])));
    }

    private function lineFor(User $person, Carbon $start): PayrollWeekLine
    {
        return PayrollWeekLine::query()->firstOrNew(['user_id' => $person->id, 'week_start' => $start->toDateString()], [
            'organization_id' => $person->organization_id,
        ]);
    }
}
