<?php

namespace App\Support\TimeClock;

use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TimeClock
{
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
        $enabled = $this->organizationEnabled($user->organization);
        $canClock = $user->hasPermission('time_clock.view');
        $open = TimeClockShift::openFor($user);

        return [
            'organization_enabled' => $enabled,
            'can_clock' => $canClock,
            'show_button' => ($enabled && $canClock) || $open !== null,
            'open' => $open === null ? null : [
                'id' => $open->id,
                'clocked_in_at' => $this->instant($open->clocked_in_at),
            ],
        ];
    }

    /** @return array{organization_enabled: bool, can_clock: bool, show_button: bool, open: null|array{id: string, clocked_in_at: string}} */
    public function clockIn(User $user): array
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
            return DB::transaction(function () use ($user) {
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

                $shift = TimeClockShift::query()->create([
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->getKey(),
                    'clocked_in_at' => now(),
                    'clocked_out_at' => null,
                ]);

                $this->audit->log('time_clock.clocked_in', $shift);
                $this->events->emit('time_clock.clocked_in', $user, $this->eventPayload($shift));

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
     * @return array{organization_enabled: bool, can_clock: bool, show_button: bool, open: null|array{id: string, clocked_in_at: string}}
     */
    public function clockOut(User $user): array
    {
        return DB::transaction(function () use ($user) {
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
            $open->save();

            $this->audit->log('time_clock.clocked_out', $open);
            $this->events->emit('time_clock.clocked_out', $user, $this->eventPayload($open));

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

    /** @return array{id: string, user_id: string, clocked_in_at: string, clocked_out_at: ?string} */
    private function eventPayload(TimeClockShift $shift): array
    {
        return [
            'id' => $shift->id,
            'user_id' => $shift->user_id,
            'clocked_in_at' => $this->instant($shift->clocked_in_at),
            'clocked_out_at' => $this->instant($shift->clocked_out_at),
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
