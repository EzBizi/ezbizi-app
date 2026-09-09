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
        if (is_array($user->work_schedule)) {
            return $user->work_schedule;
        }

        $assignment = $user->assignments()->orderBy('created_at')->first();
        $scope = match ($assignment?->scopeType()) {
            Assignment::SCOPE_FRANCHISE => $assignment->assignedFranchise,
            Assignment::SCOPE_COMPANY => $assignment->assignedCompany,
            default => null,
        } ?? $user->organization;

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

        return [
            'timezone' => (string) $this->settings->get('timezone', $scope),
            'days' => $days,
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
