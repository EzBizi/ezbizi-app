<?php

namespace App\Support\TimeClock;

use App\Models\TimeClockDay;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Work Hours › Dashboard (Jose, 2026-10-01): "details, averages, for each
 * employee, how many clock in on time, or outside schedule, etc".
 *
 * A day is judged against the person's own schedule for that day, read on
 * their place's clock (TimeClock::scheduleFor), and only its FIRST clock-in
 * and LAST clock-out: a lunch break in the middle is neither late nor early.
 * Days still to come are not counted, and today cannot be missed yet.
 */
class TimeClockStats
{
    /** Longest period one request may cover. */
    public const MAX_DAYS = 92;

    public function __construct(
        private readonly TimeClock $clock,
        private readonly SettingsResolver $settings,
    ) {}

    /**
     * @return array{period: array{from: string, to: string, label: string}, team: array<string, mixed>, people: list<array<string, mixed>>}
     */
    public function for(User $actor, ?string $from, ?string $to): array
    {
        $scope = ViewScope::forUser($actor)->settingsScope();
        $tz = $this->clock->viewTimezone($actor);
        $timeFormat = (string) $this->settings->get('time_format', $scope);
        $locale = (string) $this->settings->get('locale', $scope);
        $grace = (int) $this->settings->get('time_clock.grace_minutes', $actor->organization);

        [$start, $end] = $this->period($actor, $from, $to, $tz);

        $people = $this->clock->visiblePeople($actor)
            ->where('is_active', true)
            ->orderBy('name')
            ->with('role:id,name')
            ->get(['id', 'organization_id', 'name', 'avatar_path', 'work_schedule', 'role_id']);
        $ids = $people->modelKeys();

        // A day's margin either side: a person's day is on THEIR place's
        // clock, which can sit hours from the reader's.
        $shifts = TimeClockShift::query()
            ->whereIn('user_id', $ids)
            ->where('clocked_in_at', '>=', $start->copy()->subDay()->utc())
            ->where('clocked_in_at', '<', $end->copy()->addDays(2)->utc())
            ->orderBy('clocked_in_at')
            ->get()
            ->groupBy('user_id');

        $records = TimeClockDay::query()
            ->whereIn('user_id', $ids)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->groupBy('user_id');

        // Who is expected on the clock: anyone who punched or was tagged in
        // the period, or who has a schedule of their own. Not everybody the
        // reader can see: an owner or a field tech who never clocks in would
        // read as "missed" every day and swamp the team's numbers.
        $people = $people->filter(fn (User $p) => $shifts->has($p->id) || $records->has($p->id) || is_array($p->work_schedule));

        $rows = $people->map(fn (User $person) => $this->person(
            $person,
            $shifts->get($person->id, collect()),
            $records->get($person->id, collect()),
            $start->toDateString(),
            $end->toDateString(),
            $grace,
            $timeFormat,
        ))->values();

        return [
            'period' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'label' => $start->copy()->locale($locale)->isoFormat('MMM D').' – '.$end->copy()->locale($locale)->isoFormat('MMM D, YYYY'),
            ],
            'grace_minutes' => $grace,
            'team' => $this->team($rows),
            'people' => $rows->map(fn (array $row) => collect($row)->except(['_in_minutes', '_out_minutes', '_late_minutes'])->all())->all(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} start of the first day, start of the last, in `$tz`. */
    private function period(User $actor, ?string $from, ?string $to, string $tz): array
    {
        $date = '/^\d{4}-\d{2}-\d{2}$/';
        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            if ($value !== null && $value !== '' && ! preg_match($date, $value)) {
                throw ValidationException::withMessages([$key => 'Choose a day as YYYY-MM-DD.']);
            }
        }

        $start = Carbon::parse($from ?: $this->clock->weekStartOf($actor), $tz)->startOfDay();
        $end = $to ? Carbon::parse($to, $tz)->startOfDay() : $start->copy()->addDays(6);

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['to' => 'The end comes before the start.']);
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose at most '.self::MAX_DAYS.' days.']);
        }

        return [$start, $end];
    }

    /**
     * @param  Collection<int, TimeClockShift>  $shifts
     * @param  Collection<int, TimeClockDay>  $records
     * @return array<string, mixed>
     */
    private function person(User $person, Collection $shifts, Collection $records, string $from, string $to, int $grace, string $timeFormat): array
    {
        $schedule = $this->clock->scheduleFor($person);
        $tz = (string) ($schedule['timezone'] ?? 'UTC');
        $today = Carbon::now($tz)->toDateString();
        $now = now();

        // Their shifts by THEIR day, inside the period only.
        $byDay = $shifts->groupBy(fn (TimeClockShift $s) => Carbon::parse($s->clocked_in_at)->timezone($tz)->toDateString())
            ->filter(fn ($rows, string $date) => $date >= $from && $date <= $to);
        $tags = $records->keyBy(fn (TimeClockDay $d) => Carbon::parse($d->date)->toDateString());

        $n = array_fill_keys([
            'scheduled_days', 'on_time', 'late', 'left_early', 'missed',
            'outside_schedule', 'auto_closed', 'extensions', 'shifts',
        ], 0);
        $total = 0;
        $lateMinutes = [];
        $inMinutes = [];
        $outMinutes = [];

        foreach ($byDay as $rows) {
            foreach ($rows as $shift) {
                $n['shifts']++;
                $total += (int) Carbon::parse($shift->clocked_in_at)->diffInSeconds($shift->clocked_out_at ?? $now, true);
                $n['outside_schedule'] += (int) $shift->outside_schedule;
                $n['auto_closed'] += (int) ($shift->ended_by === TimeClockShift::ENDED_BY_SCHEDULE);
                $n['extensions'] += (int) $shift->extensions;
            }
            $first = Carbon::parse($rows->first()->clocked_in_at)->timezone($tz);
            $inMinutes[] = $first->hour * 60 + $first->minute;
            $last = $rows->last();
            if ($last->clocked_out_at !== null) {
                $out = Carbon::parse($last->clocked_out_at)->timezone($tz);
                $outMinutes[] = $out->hour * 60 + $out->minute;
            }
        }

        for ($day = Carbon::parse($from, $tz); $day->toDateString() <= min($to, $today); $day->addDay()) {
            $date = $day->toDateString();
            $window = WorkSchedule::windowFor($schedule, $day->copy()->setTime(12, 0));
            if ($window === null) {
                continue;
            }
            $n['scheduled_days']++;
            $rows = $byDay->get($date);

            if ($rows === null) {
                // A tag or extra time says why; nothing at all, on a day that
                // is over, is a day missed.
                $record = $tags->get($date);
                if ($date < $today && ($record === null || ($record->tag === null && (int) $record->extra_minutes === 0))) {
                    $n['missed']++;
                }

                continue;
            }

            $late = (int) floor(Carbon::parse($window['start_at'])->diffInMinutes(Carbon::parse($rows->first()->clocked_in_at), false));
            if ($late > $grace) {
                $n['late']++;
                $lateMinutes[] = $late;
            } else {
                $n['on_time']++;
            }

            $last = $rows->last();
            if ($last->clocked_out_at !== null
                && $last->ended_by === TimeClockShift::ENDED_BY_PERSON
                && Carbon::parse($last->clocked_out_at)->lt(Carbon::parse($window['end_at'])->subMinutes($grace))) {
                $n['left_early']++;
            }
        }

        $extraMinutes = (int) $records->sum('extra_minutes');
        $total += $extraMinutes * 60;
        $worked = $byDay->keys()
            ->merge($records->filter(fn (TimeClockDay $d) => $d->extra_minutes > 0)->map(fn (TimeClockDay $d) => Carbon::parse($d->date)->toDateString()))
            ->unique()->count();

        return [
            'id' => $person->id,
            'name' => $person->name,
            'has_avatar' => $person->avatar_path !== null,
            'role' => $person->role === null ? null : ['id' => $person->role->id, 'name' => $person->role->name],
            ...$n,
            'days_worked' => $worked,
            'total_seconds' => $total,
            'average_seconds' => $worked === 0 ? 0 : intdiv($total, $worked),
            'late_average_minutes' => $lateMinutes === [] ? null : (int) round(array_sum($lateMinutes) / count($lateMinutes)),
            'on_time_rate' => self::rate($n['on_time'], $n['late']),
            'average_in' => self::clockOf($inMinutes, $timeFormat),
            'average_out' => self::clockOf($outMinutes, $timeFormat),
            'tags' => collect(TimeClockDay::TAGS)->mapWithKeys(fn (string $t) => [$t => $records->where('tag', $t)->count()])->all(),
            '_in_minutes' => $inMinutes,
            '_out_minutes' => $outMinutes,
            '_late_minutes' => $lateMinutes,
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function team(Collection $rows): array
    {
        $sum = fn (string $key) => (int) $rows->sum($key);
        $late = $rows->flatMap(fn (array $r) => $r['_late_minutes']);
        $worked = $sum('days_worked');

        return [
            'people' => $rows->count(),
            'clocked_in' => $rows->where('shifts', '>', 0)->count(),
            ...collect(['scheduled_days', 'days_worked', 'total_seconds', 'shifts', 'on_time', 'late', 'left_early', 'missed', 'outside_schedule', 'auto_closed', 'extensions'])
                ->mapWithKeys(fn (string $k) => [$k => $sum($k)])->all(),
            'average_seconds' => $worked === 0 ? 0 : intdiv($sum('total_seconds'), $worked),
            'late_average_minutes' => $late->isEmpty() ? null : (int) round($late->avg()),
            'on_time_rate' => self::rate($sum('on_time'), $sum('late')),
        ];
    }

    private static function rate(int $onTime, int $late): ?int
    {
        return $onTime + $late === 0 ? null : (int) round($onTime * 100 / ($onTime + $late));
    }

    /** The mean of minutes-past-midnight, as a clock in the org's format; null when none. */
    private static function clockOf(array $minutes, string $timeFormat): ?string
    {
        if ($minutes === []) {
            return null;
        }
        $at = Carbon::today()->addMinutes((int) round(array_sum($minutes) / count($minutes)));

        return $timeFormat === '12h' ? $at->format('g:i A') : $at->format('H:i');
    }
}
