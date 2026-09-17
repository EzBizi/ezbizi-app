<?php

namespace App\Support\TimeClock;

use App\Models\Assignment;
use App\Models\AutomationEvent;
use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TimeClock
{
    /** How early a punch may be before it is "outside schedule" (design 2026-09-08 §6). */
    private const EARLY_GRACE_MINUTES = 30;

    public function __construct(
        private readonly SettingsResolver $settings,
        private readonly EventEmitter $events,
        private readonly AuditLogger $audit,
        private readonly ScopeReach $reach,
    ) {}

    public function organizationEnabled(Organization $organization): bool
    {
        return (bool) $this->settings->get('time_clock.enabled', $organization);
    }

    /**
     * Chrome for `/me` and the punch responses (design §8).
     *
     * `show_button` is Clock in when the org is on and they hold the grant, or
     * Clock out while a shift is still open — including after the org switch
     * or grant is taken away (design §3.10). Instants on the wire are UTC
     * ISO-8601 with a Z suffix. The client never supplies a time — `now()` is
     * the only clock.
     *
     * @return array{
     *     organization_enabled: bool,
     *     can_clock: bool,
     *     show_button: bool,
     *     open: null|array{id: string, clocked_in_at: string}
     * }
     */
    public function payload(User $user): array
    {
        $organization = $user->organization;
        $enabled = $this->organizationEnabled($organization);
        $canClock = $user->hasPermission('time_clock.view');
        $open = TimeClockShift::openFor($user);
        $window = WorkSchedule::windowFor($this->scheduleFor($user), now());

        // The four gates of design 2026-09-08 §3.8, plus the org's own Clock
        // in switch. A day off never locks; after the scheduled end on a
        // working day still does.
        $lock = $open === null
            && $enabled
            && (bool) $this->settings->get('time_clock.lock_screen', $organization)
            && $user->hasPermission('time_clock.lock_screen')
            && ! $user->lock_screen_exempt
            && $window !== null;

        return [
            'organization_enabled' => $enabled,
            'can_clock' => $canClock,
            'show_button' => ($enabled && $canClock) || $open !== null,
            'open' => $open === null ? null : [
                'id' => $open->id,
                'clocked_in_at' => $this->instant($open->clocked_in_at),
            ],
            'lock' => $lock,
            'scheduled_end_at' => $this->instant($open?->scheduled_end_at),
            'warning_minutes' => (int) $this->settings->get('time_clock.warning_minutes', $organization),
            'extension_minutes' => (int) $this->settings->get('time_clock.extension_minutes', $organization),
            'today' => $window === null ? null : ['start' => $window['start'], 'end' => $window['end']],
        ];
    }

    /**
     * `$causedBy` is set when an automation action did this rather than a person.
     *
     * It is not decoration. AutomationEngine reads `depth` and the causation
     * chain off the event to stop a cascade, and both are derived from what is
     * passed here. Emitting without it starts a FRESH root at depth 0, so a
     * rule of "when somebody clocks in, clock them out" would raise a clocked_out
     * that looks person-initiated, re-enter the engine, and ping-pong past both
     * the depth cap and the cascade budget — the exact A→B→A that §5.5 says
     * depth alone does not catch.
     *
     * @return array{organization_enabled: bool, can_clock: bool, show_button: bool, open: null|array{id: string, clocked_in_at: string}}
     */
    public function clockIn(User $user, ?AutomationEvent $causedBy = null): array
    {
        if (! $this->organizationEnabled($user->organization)) {
            throw ValidationException::withMessages([
                'time_clock' => 'Clock in is switched off for this organization.',
            ]);
        }

        if (! $user->hasPermission('time_clock.view')) {
            abort(403, Refusal::permission('clock in')->message());
        }

        try {
            return DB::transaction(function () use ($user, $causedBy) {
                $open = TimeClockShift::query()
                    ->open()
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($open !== null) {
                    throw ValidationException::withMessages([
                        'time_clock' => 'You are already clocked in.',
                    ]);
                }

                $now = now();
                $window = WorkSchedule::windowFor($this->scheduleFor($user), $now);

                $shift = TimeClockShift::query()->create([
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->getKey(),
                    'clocked_in_at' => $now,
                    'clocked_out_at' => null,
                    // After the scheduled end there is nothing to count down
                    // to: they are working late, and the chip carries them.
                    'scheduled_end_at' => $window !== null && $now->lt($window['end_at']) ? $window['end_at'] : null,
                    // A day off, or well before the start. Informational —
                    // Clock In is never refused by the schedule (design §3.4).
                    'outside_schedule' => $window === null
                        || $now->lt($window['start_at']->copy()->subMinutes(self::EARLY_GRACE_MINUTES)),
                ]);

                $this->audit->log('time_clock.clocked_in', $shift);
                $this->events->emit(
                    'time_clock.clocked_in',
                    $user,
                    $this->eventPayload($shift),
                    causedBy: $causedBy,
                );

                return $this->payload($user);
            });
        } catch (UniqueConstraintViolationException) {
            // lockForUpdate on an empty open query does not serialize two first
            // inserts. The unique index still holds; this is the sentence.
            throw ValidationException::withMessages([
                'time_clock' => 'You are already clocked in.',
            ]);
        }
    }

    /**
     * Close the open shift. Allowed after the org switch or grant is taken away
     * (design §3.10, §4); only an open row is required.
     *
     * `$causedBy` carries the automation cascade when a rule did this rather
     * than a person — see clockIn() for why it is load-bearing.
     *
     * @return array{organization_enabled: bool, can_clock: bool, show_button: bool, open: null|array{id: string, clocked_in_at: string}}
     */
    public function clockOut(
        User $user,
        ?AutomationEvent $causedBy = null,
        string $endedBy = TimeClockShift::ENDED_BY_PERSON,
    ): array {
        return DB::transaction(function () use ($user, $causedBy, $endedBy) {
            $open = TimeClockShift::query()
                ->open()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($open === null) {
                throw ValidationException::withMessages([
                    'time_clock' => 'You are not clocked in.',
                ]);
            }

            $open->clocked_out_at = now();
            // A rule's punch is an automation's, whatever the caller said.
            $open->ended_by = $causedBy !== null ? TimeClockShift::ENDED_BY_AUTOMATION : $endedBy;
            $open->save();

            $this->audit->log('time_clock.clocked_out', $open);
            $this->events->emit(
                'time_clock.clocked_out',
                $user,
                $this->eventPayload($open),
                causedBy: $causedBy,
            );

            return $this->payload($user);
        });
    }

    /**
     * Push the scheduled end out by the organization's extension length.
     * Needs only an open scheduled shift — allowed after the grant or the
     * org switch is gone, like clock out (design 2026-09-08 §7).
     */
    public function extend(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $open = TimeClockShift::query()
                ->open()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($open === null || $open->scheduled_end_at === null) {
                throw ValidationException::withMessages([
                    'time_clock' => 'You are not on a scheduled shift.',
                ]);
            }

            $minutes = (int) $this->settings->get('time_clock.extension_minutes', $user->organization);

            // ponytail: adds to the stamped end, not to now(). If the close
            // ran a second earlier this is a race the row already lost.
            $open->scheduled_end_at = $open->scheduled_end_at->copy()->addMinutes($minutes);
            $open->extensions = $open->extensions + 1;
            $open->save();

            $this->audit->log('time_clock.extended', $open, metadata: ['minutes' => $minutes]);
            $this->events->emit('time_clock.extended', $user, $this->eventPayload($open));

            return $this->payload($user);
        });
    }

    /**
     * One calendar day of shifts in the current view timezone (design §7, §9).
     *
     * Rows are people this caller can already see on Records. A shift is
     * bucketed by `clocked_in_at` in the view zone — overnight still-open
     * from yesterday is yesterday's row, not today's.
     *
     * @return array{
     *     date: string,
     *     timezone: string,
     *     timezone_label: string,
     *     shifts: list<array{
     *         id: string,
     *         user: array{id: string, name: string},
     *         clocked_in_at: string,
     *         clocked_out_at: ?string,
     *         clocked_in: string,
     *         clocked_out: ?string,
     *         duration_seconds: ?int,
     *         open: bool
     *     }>
     * }
     */
    public function dayList(User $actor, ?string $on): array
    {
        $view = ViewScope::forUser($actor);
        $tz = (string) $this->settings->get('timezone', $view->settingsScope());
        $timeFormat = (string) $this->settings->get('time_format', $view->settingsScope());
        $on = $this->resolveDate($on, $tz);

        $start = Carbon::parse($on, $tz)->startOfDay()->utc();
        $end = Carbon::parse($on, $tz)->addDay()->startOfDay()->utc();

        $shifts = TimeClockShift::query()
            ->with('user:id,name')
            ->where('clocked_in_at', '>=', $start)
            ->where('clocked_in_at', '<', $end)
            ->whereHas('user', fn (Builder $users) => $users->where(
                fn (Builder $q) => $this->visibleTo($q, $actor)
            ))
            ->get()
            ->sort(function (TimeClockShift $a, TimeClockShift $b) {
                $cmp = $b->clocked_in_at <=> $a->clocked_in_at;

                return $cmp !== 0 ? $cmp : strcasecmp((string) $a->user?->name, (string) $b->user?->name);
            })
            ->values();

        return [
            'date' => $on,
            'timezone' => $tz,
            'timezone_label' => SettingRegistry::timezoneLabel($tz),
            'shifts' => $shifts->map(fn (TimeClockShift $shift) => $this->presentShift($shift, $tz, $timeFormat))->all(),
        ];
    }

    /**
     * The person's own schedule, or their place's Business hours when they
     * have none (2026-09-09: the lock did nothing for anybody until HR typed a
     * personal schedule, even with hours set on the organization).
     *
     * Place is the FIRST assignment: franchise, then company, then the
     * organization, resolved through the settings cascade so a branch with
     * its own hours and clock gets them. ponytail: a person assigned to two
     * places takes the first; a per-assignment choice waits for somebody
     * needing it.
     *
     * @return null|array{timezone: string, days: array<string, null|array{start: string, end: string}>}
     */
    private function scheduleFor(User $user): ?array
    {
        $assignment = $user->assignments()->orderBy('created_at')->first();
        $scope = match ($assignment?->scopeType()) {
            Assignment::SCOPE_FRANCHISE => $assignment->assignedFranchise,
            Assignment::SCOPE_COMPANY => $assignment->assignedCompany,
            default => null,
        } ?? $user->organization;

        /*
         * ── The clock is the PLACE's, read now; never the stored one ──
         *
         * `work_schedule.timezone` is a record of where somebody stood when
         * they saved it. It is NOT the clock the shift runs on, and reading it
         * as one cost Jose an hour on 2026-09-16: his row still said
         * `Etc/GMT+5` from before the platform default became
         * `America/New_York`, a fixed offset keeps no daylight saving, and so
         * his 17:00 resolved to 18:00 Eastern. Every reminder and every
         * automatic clock-out ran an hour late, and every morning punch was
         * flagged as outside schedule.
         *
         * A snapshot cannot be right: correcting a place's timezone has to move
         * the people who work there, and a person assigned somewhere else again
         * works on the new place's clock. So the zone is resolved here, through
         * the same cascade the business-hours fallback below already uses, and
         * the same answer serves both.
         */
        $timezone = (string) $this->settings->get('timezone', $scope);

        if (is_array($user->work_schedule)) {
            return [
                'timezone' => $timezone,
                'days' => is_array($user->work_schedule['days'] ?? null)
                    ? $user->work_schedule['days']
                    : [],
            ];
        }

        $hours = $this->settings->get('ops.business_hours', $scope);

        if (! is_array($hours)) {
            return null;
        }

        $days = [];
        foreach (SettingDefinition::WEEKDAYS as $day) {
            $window = $hours[$day] ?? null;
            $days[$day] = is_array($window) && isset($window['open'], $window['close'])
                ? ['start' => (string) $window['open'], 'end' => (string) $window['close']]
                : null;
        }

        return ['timezone' => $timezone, 'days' => $days];
    }

    /**
     * One week of shifts, grouped by person, with the hours totalled.
     *
     * The day list answers "who was in on this date". Work Hours asks the other
     * question — what each person worked this week — so the shape is one entry
     * per person in reach rather than one per punch, and EVERYBODY in reach is
     * listed, including somebody who worked nothing. A name missing from the
     * list would read as "not on the team" when it means "did not clock in".
     *
     * `?on=` is any date inside the week wanted; the window comes from the
     * organization's `time_clock.week_starts_on`, so an organization counting
     * from Thursday gets Thursday to Wednesday.
     *
     * @return array{
     *     week_start: string,
     *     week_end: string,
     *     starts_on: string,
     *     timezone: string,
     *     timezone_label: string,
     *     days: list<array{date: string, weekday: string, label: string, in_future: bool}>,
     *     people: list<array<string, mixed>>
     * }
     */
    public function weekList(User $actor, ?string $on): array
    {
        $view = ViewScope::forUser($actor);
        $tz = (string) $this->settings->get('timezone', $view->settingsScope());
        $timeFormat = (string) $this->settings->get('time_format', $view->settingsScope());
        $startsOn = (string) $this->settings->get('time_clock.week_starts_on', $actor->organization);

        $locale = (string) $this->settings->get('locale', $view->settingsScope());
        $dateFormat = (string) $this->settings->get('date_format', $view->settingsScope());

        $weekStart = $this->weekStart($this->resolveDate($on, $tz), $tz, $startsOn);
        $weekEnd = $weekStart->copy()->addDays(7);

        $shifts = TimeClockShift::query()
            ->where('clocked_in_at', '>=', $weekStart->copy()->utc())
            ->where('clocked_in_at', '<', $weekEnd->copy()->utc())
            ->whereHas('user', fn (Builder $users) => $users->where(
                fn (Builder $q) => $this->visibleTo($q, $actor)
            ))
            ->orderBy('clocked_in_at')
            ->get()
            ->groupBy('user_id');

        $people = User::query()
            ->where(fn (Builder $q) => $this->visibleTo($q, $actor))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'avatar_path']);

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->addDays(6)->toDateString(),
            'starts_on' => $startsOn,
            'timezone' => $tz,
            'timezone_label' => SettingRegistry::timezoneLabel($tz),
            'days' => $this->weekDays($weekStart, $tz, $locale, $dateFormat),
            'people' => $people
                ->map(fn (User $person) => $this->presentWeekPerson(
                    $person,
                    $shifts->get($person->getKey()) ?? collect(),
                    $tz,
                    $timeFormat,
                ))
                ->all(),
        ];
    }

    /**
     * The start of the week `$date` falls in, in this organization's reckoning.
     *
     * Carbon's own `startOfWeek()` is deliberately not used: it answers for the
     * locale, and this has to answer for a setting somebody chose.
     */
    private function weekStart(string $date, string $tz, string $startsOn): Carbon
    {
        // Carbon numbers Sunday 0 through Saturday 6, which is the order below.
        $order = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $target = (int) array_search($startsOn, $order, true);
        $local = Carbon::parse($date, $tz)->startOfDay();

        return $local->subDays(((int) $local->dayOfWeek - $target + 7) % 7);
    }

    /**
     * The seven headings of the week, named and in order.
     *
     * Here rather than on the client for the reason the clock times are: the
     * weekday name is words, the app ships in two languages, and there is no
     * shared date formatter on that side — `hours_pane.dart` and `web/hours.dart`
     * each carry their own English `['Monday', ...]`, and a third copy in a
     * screen that ships in Spanish would simply be wrong.
     *
     * Every day is here, including the ones nobody worked. A day with no shifts
     * still needs a heading to say so, and deriving it by walking dates from
     * `week_start` would mean the client guessing both the length of a week and
     * the name of a day.
     *
     * `in_future` is the one fact the client cannot work out for itself. A day
     * is in the future against TODAY IN THE ORGANIZATION'S CLOCK, and the only
     * today the client has is the device's — which is the whole error this
     * module spent a day on. Whether a screen then hides those days is its own
     * decision; this only says which they are.
     *
     * @return list<array{date: string, weekday: string, label: string, in_future: bool}>
     */
    private function weekDays(Carbon $weekStart, string $tz, string $locale, string $dateFormat): array
    {
        $today = Carbon::now($tz)->toDateString();
        $days = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $weekStart->copy()->addDays($offset);

            $days[] = [
                'date' => $day->toDateString(),
                // Today is not the future. A week gone by has none of these; a
                // week ahead is all of them.
                'in_future' => $day->toDateString() > $today,
                // The English key, untranslated, for anything that has to branch
                // on the day rather than print it.
                'weekday' => strtolower($day->locale('en')->isoFormat('dddd')),
                // Month before day where the organization writes dates that way,
                // so a heading does not contradict every other date on screen.
                'label' => $day->locale($locale)->isoFormat(
                    str_starts_with($dateFormat, 'M') ? 'dddd, MMM D' : 'dddd D MMM'
                ),
            ];
        }

        return $days;
    }

    /**
     * One person and their week.
     *
     * An open shift counts the time worked SO FAR rather than reporting null
     * the way the day list does, because the number beside it here is part of a
     * total: a running shift contributing nothing would make today read as zero
     * hours until somebody clocked out.
     *
     * @param  iterable<TimeClockShift>  $shifts
     */
    private function presentWeekPerson(User $person, iterable $shifts, string $tz, string $timeFormat): array
    {
        $now = now();
        $total = 0;
        $days = [];
        $open = false;
        $rows = [];

        foreach ($shifts as $shift) {
            $isOpen = $shift->clocked_out_at === null;
            $seconds = ($isOpen ? $now : $shift->clocked_out_at)->getTimestamp()
                - $shift->clocked_in_at->getTimestamp();

            $total += $seconds;
            $open = $open || $isOpen;
            $date = Carbon::parse($shift->clocked_in_at)->timezone($tz)->toDateString();
            $days[$date] = true;

            $row = $this->presentShift($shift, $tz, $timeFormat);
            unset($row['user']);
            $row['date'] = $date;
            $row['duration_seconds'] = $seconds;
            $rows[] = $row;
        }

        $worked = count($days);

        return [
            'id' => $person->id,
            'name' => $person->name,
            'is_active' => (bool) $person->is_active,
            'has_avatar' => $person->avatar_path !== null,
            'total_seconds' => $total,
            'days_worked' => $worked,
            // Over the days actually WORKED, not over seven: "six hours a day
            // when I am in" is the useful average, and dividing by a fixed week
            // would report every part-timer as failing.
            'average_seconds' => $worked === 0 ? 0 : intdiv($total, $worked),
            'open' => $open,
            'shifts' => $rows,
        ];
    }

    /** @return array{id: string, user_id: string, clocked_in_at: string, clocked_out_at: ?string} */
    private function eventPayload(TimeClockShift $shift): array
    {
        return [
            'id' => $shift->id,
            'user_id' => $shift->user_id,
            'clocked_in_at' => $this->instant($shift->clocked_in_at),
            'clocked_out_at' => $this->instant($shift->clocked_out_at),
            'scheduled_end_at' => $this->instant($shift->scheduled_end_at),
            'ended_by' => $shift->ended_by,
            // What a rule can filter on: "text me when somebody starts outside
            // schedule" is a condition on clocked_in, not a trigger of its own.
            'outside_schedule' => (bool) $shift->outside_schedule,
            'extensions' => (int) $shift->extensions,
        ];
    }

    private function instant(mixed $at): ?string
    {
        if ($at === null) {
            return null;
        }

        return Carbon::parse($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * People this caller may already see on Records. Same three arms as
     * HrPeopleController::visibleTo — yourself, anybody assigned to a place
     * your authority covers, and the unassigned only with organization reach.
     */
    private function visibleTo(Builder $query, User $actor): void
    {
        $query->whereKey($actor->getKey())
            ->orWhereHas('assignments', fn (Builder $a) => $this->reach->constrain($a, $actor));

        if ($actor->canActOnOrganization($actor->organization)) {
            $query->orWhereDoesntHave('assignments');
        }
    }

    /**
     * @return array{
     *     id: string,
     *     user: array{id: string, name: string},
     *     clocked_in_at: string,
     *     clocked_out_at: ?string,
     *     clocked_in: string,
     *     clocked_out: ?string,
     *     duration_seconds: ?int,
     *     open: bool
     * }
     */
    private function presentShift(TimeClockShift $shift, string $tz, string $timeFormat): array
    {
        $open = $shift->clocked_out_at === null;

        return [
            'id' => $shift->id,
            'user' => [
                'id' => $shift->user->id,
                'name' => $shift->user->name,
            ],
            'clocked_in_at' => $this->instant($shift->clocked_in_at),
            'clocked_out_at' => $this->instant($shift->clocked_out_at),
            'clocked_in' => $this->wallClock($shift->clocked_in_at, $tz, $timeFormat),
            'clocked_out' => $open ? null : $this->wallClock($shift->clocked_out_at, $tz, $timeFormat),
            'duration_seconds' => $open
                ? null
                : $shift->clocked_out_at->getTimestamp() - $shift->clocked_in_at->getTimestamp(),
            'open' => $open,
            'ended_by' => $shift->ended_by,
            'outside_schedule' => (bool) $shift->outside_schedule,
            'extensions' => (int) $shift->extensions,
        ];
    }

    private function wallClock(mixed $at, string $tz, string $timeFormat): string
    {
        $local = Carbon::parse($at)->timezone($tz);

        // DisplayFormats.formatTime: 12h midnight is 12 AM; anything else is 24h.
        return $timeFormat === '12h'
            ? $local->format('g:i A')
            : $local->format('H:i');
    }

    private function resolveDate(?string $on, string $tz): string
    {
        if ($on === null || $on === '') {
            return Carbon::now($tz)->toDateString();
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) {
            throw ValidationException::withMessages([
                'on' => 'Choose a day as YYYY-MM-DD.',
            ]);
        }

        [$year, $month, $day] = array_map('intval', explode('-', $on));

        if (! checkdate($month, $day, $year)) {
            throw ValidationException::withMessages([
                'on' => 'Choose a day as YYYY-MM-DD.',
            ]);
        }

        return $on;
    }
}
