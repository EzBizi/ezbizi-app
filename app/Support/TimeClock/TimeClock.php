<?php

namespace App\Support\TimeClock;

use App\Models\Assignment;
use App\Models\AutomationEvent;
use App\Models\Organization;
use App\Models\TimeClockDay;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Dashboard\Widgets\Hr\ScopesToPlaces;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TimeClock
{
    use ScopesToPlaces;

    /**
     * Out and back in in LESS than this is one shift (2 until 2026-10-13, when
     * Jose asked for 5: "if the difference between a clock out and a clock in
     * is less than 5 mins they should merge").
     */
    public const MERGE_MINUTES = 5;

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
        $schedule = $this->scheduleFor($user);
        $window = WorkSchedule::windowFor($schedule, now());
        $scope = ViewScope::forUser($user)->settingsScope();

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
            // So the lock screen stops saying "start your day" at 5 PM: after
            // the end, Clock In buys one extension, and it should say so.
            'after_hours' => $window !== null && now()->gte($window['end_at']),
            // The client asks the device for a position only when this is true,
            // so nobody outside a zone is ever prompted for location.
            'needs_location' => $this->geofenceFor($user) !== null,
            // "Wednesday, September 30" for the lock screen: the day in the
            // schedule's zone, so it is the day `today` is about, in the
            // organization's language. The device's day can be another one.
            'today_label' => now()
                ->timezone((string) ($schedule['timezone'] ?? $this->settings->get('timezone', $scope)))
                ->locale((string) $this->settings->get('locale', $scope))
                ->isoFormat(str_starts_with((string) $this->settings->get('date_format', $scope), 'M') ? 'dddd, MMMM D' : 'dddd D MMMM'),
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
    public function clockIn(User $user, ?AutomationEvent $causedBy = null, ?array $position = null): array
    {
        if (! $this->organizationEnabled($user->organization)) {
            throw ValidationException::withMessages([
                'time_clock' => 'Clock in is switched off for this organization.',
            ]);
        }

        if (! $user->hasPermission('time_clock.view')) {
            abort(403, Refusal::permission('clock in')->message());
        }

        // A person's own punch only: a rule clocking somebody in has no device.
        if ($causedBy === null) {
            $this->assertInsideZone($user, $position, 'clock in');
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

                $fields = [
                    /*
                     * NEVER NULL (2026-09-23). It used to be null whenever the
                     * schedule gave no end ahead of now, on the reasoning that
                     * somebody working late has nothing to count down to — and
                     * that is true about the countdown and was false about the
                     * close. CloseEndedShifts reads `whereNotNull`, so a null
                     * here meant no sweep would ever look at the row again.
                     *
                     * The schedule's end when there is one ahead; otherwise the
                     * organization's cap, which is a backstop and not a shift
                     * length. It also gives Extend something to push: a late
                     * shift could not be extended before, because extend()
                     * refuses a row with no scheduled end.
                     */
                    'scheduled_end_at' => match (true) {
                        // No schedule today at all — a day off, or nobody set
                        // one. The cap is the only end there is.
                        $window === null => $now->copy()->addHours($this->maxHours($user)),
                        $now->lt($window['end_at']) => $window['end_at'],
                        /*
                         * AFTER THE DAY HAS ENDED: one extension, not the cap
                         * (2026-09-24).
                         *
                         * Measured on production the evening after the cap
                         * shipped: the sweep closed six shifts at 21:00:02 and
                         * Adeline clocked back in at 21:00:14, Jason at
                         * 21:00:24. Adeline did the same the night before, at
                         * 21:00:10. That is not overtime, it is the lock screen:
                         * on a working day it still locks after the scheduled
                         * end, its button is Clock In, and a person who wants
                         * their computer back to save a file presses it and
                         * goes home. With the cap each of those was a 16-hour
                         * shift.
                         *
                         * The extension length is the right bound because it is
                         * already what this organization decided working past
                         * the end costs: the reminder fires before it, Extend
                         * pushes it the same way as any shift, and somebody who
                         * really is staying late sees exactly the prompt they
                         * would have seen at 4:55.
                         */
                        default => $now->copy()->addMinutes(
                            (int) $this->settings->get('time_clock.extension_minutes', $user->organization)
                        ),
                    },
                    // A day off, or well before the start. Informational —
                    // Clock In is never refused by the schedule (design §3.4).
                    'outside_schedule' => $window === null
                        || $now->lt($window['start_at']->copy()->subMinutes(self::EARLY_GRACE_MINUTES)),
                ];

                /*
                 * MERGE A QUICK RE-PUNCH (Jose, 2026-10-01: "merges, for less
                 * than 2 mins shift changes so we don't have these build ups
                 * and unnecesary extra rows"). Out and back in within
                 * MERGE_MINUTES continues the shift that just closed instead of
                 * starting another: 8:00–8:39, 8:39–8:39, 8:39–8:54 is one
                 * shift, 8:00–8:54. Not a shift HR ended or edited — reopening
                 * it would undo their decision.
                 */
                $recent = TimeClockShift::query()
                    ->where('user_id', $user->getKey())
                    ->whereNotNull('clocked_out_at')
                    ->whereBetween('clocked_out_at', [$now->copy()->subMinutes(self::MERGE_MINUTES)->addSecond(), $now])
                    ->whereIn('ended_by', [
                        TimeClockShift::ENDED_BY_PERSON,
                        TimeClockShift::ENDED_BY_SCHEDULE,
                        TimeClockShift::ENDED_BY_AUTOMATION,
                    ])
                    ->latest('clocked_out_at')
                    ->lockForUpdate()
                    ->first();

                if ($recent !== null) {
                    $recent->forceFill([
                        'clocked_out_at' => null,
                        'ended_by' => null,
                        'scheduled_end_at' => $fields['scheduled_end_at'],
                    ])->save();
                    $shift = $recent;
                } else {
                    $shift = TimeClockShift::query()->create([
                        'organization_id' => $user->organization_id,
                        'user_id' => $user->getKey(),
                        'clocked_in_at' => $now,
                        'clocked_out_at' => null,
                        ...$fields,
                    ]);
                }

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
        ?array $position = null,
    ): array {
        // Only a person clocking themselves out by hand: the end-of-day sweep,
        // HR and automations never carry a device, and must always close.
        if ($causedBy === null && $endedBy === TimeClockShift::ENDED_BY_PERSON) {
            $this->assertInsideZone($user, $position, 'clock out');
        }

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

            /*
             * A person's clock-out is now. THE SWEEP'S is the end it was
             * sweeping — never now.
             *
             * Normally those differ by the second the sweep took to get there.
             * They differ by days on a row the sweep could not see until a
             * backfill handed it one, and crediting `now()` there would have
             * paid Adeline for the 40 hours her forgotten punch sat open
             * instead of the 16 the cap allows. A close cannot invent hours.
             */
            $open->clocked_out_at = $endedBy === TimeClockShift::ENDED_BY_SCHEDULE
                && $open->scheduled_end_at !== null
                && $open->scheduled_end_at->lt(now())
                    ? $open->scheduled_end_at->copy()
                    : now();
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
     * Set a shift's times by hand (Jose, 2026-09-23: "we should have a
     * correction system as well to be able to edit or eliminate shifts").
     *
     * ── Why this is not `hr.edit` ──
     *
     * These are the hours somebody is PAID for. Maintaining a person's row and
     * rewriting what they are owed are different authorities, and the module
     * already makes that distinction for `security.reset`, which is split from
     * `edit` on the same argument. Folding corrections into `hr.edit` would
     * have granted them silently, on deploy, to everybody who keeps the people
     * list.
     *
     * ── What it will not do ──
     *
     * It cannot invent a shift, and the controller does not let it reopen one
     * that is finished. An open row is
     * a claim that somebody is working right now, and a manager creating one
     * out of a date picker is the shape that turns a clock into a spreadsheet.
     * If a punch is missing entirely, the shift that exists gets corrected or
     * deleted; there is no third case yet, and it refuses with a sentence that
     * says so rather than failing a validator.
     *
     * NO AUTOMATION EVENT, deliberately. `time_clock.clocked_in` and its
     * siblings are things that happened to the business; a correction is an
     * administrator amending the record afterwards, and a rule firing "when
     * somebody is clocked out" on it would text a technician who went home
     * yesterday. The audit entry below is the trail.
     */
    public function correct(User $actor, TimeClockShift $shift, ?Carbon $in, ?Carbon $out, bool $requested = false, ?string $reason = null): void
    {
        $this->assertReaches($actor, $shift->user);
        // Unmarked own edits are Jose's alone (2026-10-05: "only to me").
        $own = $actor->is($shift->user) && $actor->time_clock_edits_unmarked;

        DB::transaction(function () use ($actor, $shift, $in, $out, $requested, $own, $reason) {
            $shift = TimeClockShift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();

            // Null means UNCHANGED in both arguments. The controller refuses
            // the reopen, because only the request can tell an absent key from
            // an explicit null and both reach here as the same value.
            $in ??= $shift->clocked_in_at;
            $out ??= $shift->clocked_out_at;

            $now = now();

            if ($in->gt($now) || ($out !== null && $out->gt($now))) {
                throw ValidationException::withMessages([
                    'clocked_in_at' => 'A shift cannot be in the future.',
                ]);
            }

            if ($out !== null && $out->lte($in)) {
                throw ValidationException::withMessages([
                    'clocked_out_at' => 'Clock out must be after clock in.',
                ]);
            }

            /*
             * Overlap is REFUSED rather than allowed-and-flagged, unlike a
             * punch outside schedule. A person on two shifts at once is not an
             * unusual day — it is the same hour counted twice, and nothing on
             * Work Hours could show that the total says so.
             */
            $clash = TimeClockShift::query()
                ->where('user_id', $shift->user_id)
                ->whereKeyNot($shift->getKey())
                ->where('clocked_in_at', '<', $out ?? $now->copy()->addCentury())
                ->where(fn ($q) => $q->whereNull('clocked_out_at')->orWhere('clocked_out_at', '>', $in))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'clocked_in_at' => 'These times overlap another shift for the same person.',
                ]);
            }

            $before = [
                'clocked_in_at' => $this->instant($shift->clocked_in_at),
                'clocked_out_at' => $this->instant($shift->clocked_out_at),
            ];

            $shift->clocked_in_at = $in;
            $shift->clocked_out_at = $out;

            // Only when the end actually moved. Nudging a start must not
            // rewrite the row's account of who ended it. Your own end is
            // yours: as if you had clocked out then.
            if ($out !== null && $before['clocked_out_at'] !== $this->instant($out)) {
                $shift->ended_by = $own ? TimeClockShift::ENDED_BY_PERSON : TimeClockShift::ENDED_BY_CORRECTION;
            }

            // The mark on the row (Jose, 2026-10-01): "Shift corrected" for an
            // approved request, "Edited" for HR's own edit, and nothing when
            // you edit your own hours.
            $shift->edit_source = match (true) {
                $requested => TimeClockShift::EDITED_BY_REQUEST,
                $own => $shift->edit_source,
                default => TimeClockShift::EDITED_BY_HR,
            };

            $shift->save();

            $this->audit->log('time_clock.corrected', $shift, changes: [
                'before' => $before,
                'after' => [
                    'clocked_in_at' => $this->instant($shift->clocked_in_at),
                    'clocked_out_at' => $this->instant($shift->clocked_out_at),
                ],
            ], metadata: $reason === null ? [] : ['reason' => $reason]);

            // An ADJUSTMENT with a reason (Daily approval, 2026-10-22) is a
            // decision a rule may act on; a plain correction stays silent
            // (see the docblock above).
            if ($reason !== null) {
                $this->events->emit('time_clock.shift_adjusted', $shift->user, [
                    'shift_id' => $shift->id,
                    'before' => $before,
                    'after' => ['clocked_in_at' => $this->instant($shift->clocked_in_at), 'clocked_out_at' => $this->instant($shift->clocked_out_at)],
                    'reason' => $reason,
                    'adjusted_by' => $actor->getKey(),
                ], actor: $actor);
            }

            $this->joinNeighbours($shift);
        });
    }

    /**
     * Join `$shift` with a neighbour less than MERGE_MINUTES away (2026-10-13).
     *
     * Clock-in already continues a shift that just closed; this is the same
     * rule after an edit or an added shift, which is how Jose's own week kept
     * two rows: the punches were 5:43 and 7:49 apart, and his corrections
     * then closed the gaps to seconds, but nothing joined them. The earlier
     * row survives and takes the later one's end, its ender and its
     * scheduled end; extensions add up; a correction request on the later
     * row moves to the survivor; the audit keeps both rows' times.
     *
     * @return TimeClockShift the row that holds the time now
     */
    private function joinNeighbours(TimeClockShift $shift): TimeClockShift
    {
        $gap = self::MERGE_MINUTES * 60;
        $near = fn () => TimeClockShift::query()->where('user_id', $shift->user_id)->whereKeyNot($shift->getKey());

        $before = $near()->whereNotNull('clocked_out_at')
            ->where('clocked_out_at', '<=', $shift->clocked_in_at)
            ->where('clocked_out_at', '>', $shift->clocked_in_at->copy()->subSeconds($gap))
            ->latest('clocked_out_at')->first();
        if ($before !== null) {
            $shift = $this->join($before, $shift);
        }

        if ($shift->clocked_out_at !== null) {
            $after = $near()->where('clocked_in_at', '>=', $shift->clocked_out_at)
                ->where('clocked_in_at', '<', $shift->clocked_out_at->copy()->addSeconds($gap))
                ->oldest('clocked_in_at')->first();
            if ($after !== null) {
                $shift = $this->join($shift, $after);
            }
        }

        return $shift;
    }

    private function join(TimeClockShift $earlier, TimeClockShift $later): TimeClockShift
    {
        $before = [
            'earlier' => ['id' => $earlier->id, 'clocked_in_at' => $this->instant($earlier->clocked_in_at), 'clocked_out_at' => $this->instant($earlier->clocked_out_at)],
            'later' => ['id' => $later->id, 'clocked_in_at' => $this->instant($later->clocked_in_at), 'clocked_out_at' => $this->instant($later->clocked_out_at)],
        ];

        DB::table('time_clock_correction_requests')->where('shift_id', $later->id)->update(['shift_id' => $earlier->id]);
        // The later row goes first: an open later row and the survivor taking
        // its open end would otherwise be two open shifts for a moment.
        $later->delete();

        $earlier->forceFill([
            'clocked_out_at' => $later->clocked_out_at,
            'ended_by' => $later->ended_by,
            'scheduled_end_at' => $later->scheduled_end_at,
            'extensions' => (int) $earlier->extensions + (int) $later->extensions,
            'edit_source' => $earlier->edit_source ?? $later->edit_source,
        ])->save();

        $this->audit->log('time_clock.merged', $earlier, changes: [
            'before' => $before,
            'after' => ['clocked_in_at' => $this->instant($earlier->clocked_in_at), 'clocked_out_at' => $this->instant($earlier->clocked_out_at)],
        ]);

        return $earlier;
    }

    /**
     * Delete a shift outright — the punch that should never have existed.
     *
     * Hard delete, not a soft one. A shift is a fact about a clock rather than
     * a record with a life of its own, and every total on Work Hours sums the
     * table: a soft-deleted row is one `whereNull` away from being counted by
     * the next query somebody writes. The audit entry carries both times, so
     * the deletion can be read and undone by hand.
     */
    public function remove(User $actor, TimeClockShift $shift): void
    {
        $this->assertReaches($actor, $shift->user);

        DB::transaction(function () use ($shift) {
            $this->audit->log('time_clock.deleted', $shift, changes: [
                'before' => [
                    'clocked_in_at' => $this->instant($shift->clocked_in_at),
                    'clocked_out_at' => $this->instant($shift->clocked_out_at),
                ],
            ]);

            $shift->delete();
        });
    }

    /**
     * The people Work Hours shows `$actor` — for the dashboard box, so the box
     * and the screen cannot count different people.
     */
    public function visiblePeople(User $actor): Builder
    {
        return User::query()->where(fn (Builder $q) => $this->visibleTo($q, $actor));
    }

    /**
     * Narrow a people query to the top-bar view (2026-10-05), as HR's screen
     * and every dashboard box do: on A1-Group's empty company "EzBizi" the
     * screen listed all of A1-Group. For SCREENS only. A saved report and its
     * scheduled send keep their whole reach: an automation has no view, and a
     * sender's last-picked company must not change what the email covers.
     * Reach itself (assertReaches, corrections) is not narrowed either.
     */
    public function narrowToView(Builder $users, User $actor): Builder
    {
        $places = $this->placesInView($actor);

        return $users->where(function (Builder $inView) use ($places) {
            $inView->whereHas('assignments', fn (Builder $a) => $this->constrainToPlaces($a, $places));
            // Nobody placed anywhere counts only at the whole organization.
            if ($places['organization'] !== null) {
                $inView->orWhereDoesntHave('assignments');
            }
        });
    }

    /** The zone Work Hours shows `$actor` times in — and reads corrections back in. */
    public function viewTimezone(User $actor): string
    {
        return (string) $this->settings->get('timezone', ViewScope::forUser($actor)->settingsScope());
    }

    /**
     * Add a shift nobody punched (Jose, 2026-10-01: "im also missing the
     * button to add shift"; Anisa worked the Tuesday of the A1 Tools
     * switch-over and has no row for it).
     *
     * Always closed: an open one would be a claim that somebody is working
     * right now, made from a date picker. The same refusals as correct() —
     * not in the future, end after start, no overlap — and stamped as a
     * correction, so the row says HR wrote it rather than a clock.
     */
    public function addShift(User $actor, User $person, Carbon $in, Carbon $out, bool $requested = false): TimeClockShift
    {
        $this->assertReaches($actor, $person);
        $own = $actor->is($person) && $actor->time_clock_edits_unmarked;

        return DB::transaction(function () use ($person, $in, $out, $requested, $own) {
            if ($out->gt(now())) {
                throw ValidationException::withMessages([
                    'clocked_in_at' => 'A shift cannot be in the future.',
                ]);
            }

            if ($out->lte($in)) {
                throw ValidationException::withMessages([
                    'clocked_out_at' => 'Clock out must be after clock in.',
                ]);
            }

            $clash = TimeClockShift::query()
                ->where('user_id', $person->getKey())
                ->where('clocked_in_at', '<', $out)
                ->where(fn ($q) => $q->whereNull('clocked_out_at')->orWhere('clocked_out_at', '>', $in))
                ->lockForUpdate()
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'clocked_in_at' => 'These times overlap another shift for the same person.',
                ]);
            }

            $shift = TimeClockShift::query()->create([
                'organization_id' => $person->organization_id,
                'user_id' => $person->getKey(),
                'clocked_in_at' => $in,
                'clocked_out_at' => $out,
                'ended_by' => $own ? TimeClockShift::ENDED_BY_PERSON : TimeClockShift::ENDED_BY_CORRECTION,
                'edit_source' => match (true) {
                    $requested => TimeClockShift::EDITED_BY_REQUEST,
                    $own => null,
                    default => TimeClockShift::EDITED_BY_HR,
                },
                'outside_schedule' => false,
                'extensions' => 0,
            ]);

            $this->audit->log('time_clock.added', $shift, changes: [
                'after' => [
                    'clocked_in_at' => $this->instant($shift->clocked_in_at),
                    'clocked_out_at' => $this->instant($shift->clocked_out_at),
                ],
            ]);

            return $this->joinNeighbours($shift);
        });
    }

    /**
     * Force clock out: HR ends somebody's running shift now (2026-09-24).
     *
     * Through clockOut() rather than a row write, so it is audited, raises
     * `time_clock.clocked_out` for automations, and lands on the lock screen
     * exactly like any other close — it IS a clock-out that happened to the
     * business, unlike a correction, which amends one after the fact.
     */
    public function forceClockOut(User $actor, TimeClockShift $shift): void
    {
        $this->assertReaches($actor, $shift->user);

        if ($shift->clocked_out_at !== null) {
            throw ValidationException::withMessages([
                'shift' => 'This shift has already ended.',
            ]);
        }

        $this->clockOut($shift->user, endedBy: TimeClockShift::ENDED_BY_MANAGER);
    }

    /**
     * Set what HR says about one person's day: a tag, extra minutes, a note.
     *
     * Each key is optional and absent means unchanged; null (or 0 for minutes)
     * clears it. A day left with nothing on it is deleted rather than kept as
     * an empty row, so "has a record" always means somebody said something.
     *
     * Tags may be set on a future date — Vacation next week is the commonest
     * reason to open this — but extra minutes may not: time not yet worked is
     * not time worked.
     *
     * @param  array{tag?: ?string, extra_minutes?: ?int, note?: ?string}  $changes
     */
    public function recordDay(User $actor, User $person, string $date, array $changes): void
    {
        $this->assertReaches($actor, $person);

        $view = ViewScope::forUser($actor);
        $tz = (string) $this->settings->get('timezone', $view->settingsScope());

        if (($changes['extra_minutes'] ?? 0) > 0 && $date > Carbon::now($tz)->toDateString()) {
            throw ValidationException::withMessages([
                'extra_minutes' => 'Extra hours cannot be added to a day that has not happened yet.',
            ]);
        }

        DB::transaction(function () use ($person, $date, $changes) {
            $day = TimeClockDay::query()
                ->where('user_id', $person->getKey())
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->first()
                ?? new TimeClockDay([
                    'organization_id' => $person->organization_id,
                    'user_id' => $person->getKey(),
                    'date' => $date,
                    'extra_minutes' => 0,
                ]);

            $before = $day->exists ? $this->presentDay($day) : null;

            foreach (['tag', 'note'] as $key) {
                if (array_key_exists($key, $changes)) {
                    $value = $changes[$key];
                    $day->{$key} = is_string($value) && trim($value) !== '' ? trim($value) : null;
                }
            }

            if (array_key_exists('extra_minutes', $changes)) {
                $day->extra_minutes = (int) ($changes['extra_minutes'] ?? 0);
            }

            $empty = $day->tag === null && $day->note === null && (int) $day->extra_minutes === 0;

            if ($empty) {
                if ($day->exists) {
                    $day->delete();
                }
            } else {
                $day->save();
            }

            $this->audit->log('time_clock.day_recorded', $person, changes: [
                'date' => $date,
                'before' => $before,
                'after' => $empty ? null : $this->presentDay($day),
            ]);
        });
    }

    /**
     * Refuse a shift or day that belongs to somebody outside the actor's reach.
     *
     * The route gate says the actor may correct hours; it does not say WHOSE.
     * The tenant scope stops another organization, and this stops another
     * franchise: a branch manager holding `work_hours.manage` sees only their
     * own people on Work Hours, and must not be able to edit anybody else's
     * by pasting an id. Same rule the week list draws with, so what can be
     * seen and what can be changed cannot drift apart.
     *
     * 404 rather than 403, so an id from outside does not confirm it exists.
     */
    public function assertReaches(User $actor, ?User $person): void
    {
        $visible = $person !== null && User::query()
            ->whereKey($person->getKey())
            ->where(fn (Builder $q) => $this->visibleTo($q, $actor))
            ->exists();

        if (! $visible) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }
    }

    /** @return array{date: string, tag: ?string, extra_minutes: int, note: ?string} */
    private function presentDay(TimeClockDay $day): array
    {
        return [
            'date' => Carbon::parse($day->date)->toDateString(),
            'tag' => $day->tag,
            'extra_minutes' => (int) $day->extra_minutes,
            'note' => $day->note,
        ];
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
    /**
     * The organization's cap, through the settings cascade like every other
     * Clock in value — `scheduleFor()` resolves the place, this resolves the
     * number, and both are read live rather than stamped on the row.
     */
    private function maxHours(User $user): int
    {
        return (int) $this->settings->get('time_clock.max_hours', $user->organization);
    }

    /** Where somebody works: their first assignment's place, else the organization. */
    public function placeOf(User $user): Model
    {
        $assignment = $user->assignments()->orderBy('created_at')->first();

        return match ($assignment?->scopeType()) {
            Assignment::SCOPE_FRANCHISE => $assignment->assignedFranchise,
            Assignment::SCOPE_COMPANY => $assignment->assignedCompany,
            default => null,
        } ?? $user->organization;
    }

    /**
     * The clock-in zone `$user` must be inside to punch by hand, or null when
     * none applies: the zone is off or has no centre at their place, or their
     * role may "Clock in from anywhere" (A1 Tools takeover, 2026-10-16).
     *
     * @return array{lat: float, lng: float, radius: int, place: string}|null
     */
    public function geofenceFor(User $user): ?array
    {
        // Phones only (Jose, 2026-10-16: "clock in zone should only affect
        // mobile users"). Desktop and web have no reliable location, and the
        // zone is for people out in the field. The platform header is the
        // client's own word, so a modified desktop client skips the zone;
        // that is the rule as given, not a lock.
        if (! in_array(request()->header('X-EzBizi-Client-Platform'), ['android', 'ios'], true)) {
            return null;
        }
        if ($user->hasPermission('time_clock.anywhere')) {
            return null;
        }
        $place = $this->placeOf($user);
        if (! $this->settings->get('time_clock.geofence_enabled', $place)) {
            return null;
        }
        $center = (string) $this->settings->get('time_clock.geofence_center', $place);
        if (! preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $center, $m)
            || abs((float) $m[1]) > 90 || abs((float) $m[2]) > 180) {
            return null; // no usable centre: nothing to measure against
        }

        return [
            'lat' => (float) $m[1],
            'lng' => (float) $m[2],
            'radius' => (int) $this->settings->get('time_clock.geofence_radius_m', $place),
            'place' => (string) ($place->name ?? 'your place'),
        ];
    }

    /**
     * Refuse a hand punch from outside the zone. The phone's reported accuracy
     * is taken off the distance, as A1 Tools did, so a fuzzy fix at the door
     * is not turned away. Each refusal is audited with where it was.
     *
     * @param  array{latitude?: ?float, longitude?: ?float, accuracy?: ?float}|null  $position
     */
    private function assertInsideZone(User $user, ?array $position, string $verb): void
    {
        $zone = $this->geofenceFor($user);
        if ($zone === null) {
            return;
        }

        $lat = $position['latitude'] ?? null;
        $lng = $position['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            $this->audit->log('time_clock.geofence_blocked', $user, metadata: ['action' => $verb, 'reason' => 'location_required']);
            throw ValidationException::withMessages([
                'location' => "Your location is needed to {$verb} at {$zone['place']}. Turn on location for EzBizi and try again.",
            ]);
        }

        $distance = self::metresBetween((float) $lat, (float) $lng, $zone['lat'], $zone['lng']);
        if ($distance - max(0.0, (float) ($position['accuracy'] ?? 0)) > $zone['radius']) {
            $this->audit->log('time_clock.geofence_blocked', $user, metadata: [
                'action' => $verb, 'reason' => 'outside', 'distance_m' => (int) round($distance), 'radius_m' => $zone['radius'],
            ]);
            throw ValidationException::withMessages([
                'location' => sprintf('You need to be at %s to %s. You are about %d m away (allowed %d m).', $zone['place'], $verb, round($distance), $zone['radius']),
            ]);
        }
    }

    /** Great-circle distance in metres (haversine). */
    public static function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    public function scheduleFor(User $user): ?array
    {
        $scope = $this->placeOf($user);

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
    public function weekList(User $actor, ?string $on, bool $inView = false): array
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
            ->when($inView, fn (Builder $q) => $this->narrowToView($q, $actor))
            ->orderBy('name')
            // The role, for the list's role filter (Jose, 2026-09-30).
            ->with('role:id,name')
            ->get(['id', 'organization_id', 'name', 'is_active', 'avatar_path', 'work_schedule', 'role_id']);

        $records = TimeClockDay::query()
            ->whereIn('user_id', $people->modelKeys())
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekStart->copy()->addDays(6)->toDateString())
            ->orderBy('date')
            ->get()
            ->groupBy('user_id');

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
                    $records->get($person->getKey()) ?? collect(),
                    $tz,
                    $timeFormat,
                ))
                ->all(),
        ];
    }

    /**
     * The first day of the week `$weeksFromNow` away from today, in the
     * organization's own week and the view's clock — 0 is this week, -1 the
     * one that has just finished. For saved reports (2026-09-28), which ask
     * "last week" and must mean the same week Work Hours shows.
     */
    public function weekStartOf(User $actor, int $weeksFromNow = 0): string
    {
        $view = ViewScope::forUser($actor);
        $tz = (string) $this->settings->get('timezone', $view->settingsScope());
        $startsOn = (string) $this->settings->get('time_clock.week_starts_on', $actor->organization);

        return $this->weekStart($this->resolveDate(null, $tz), $tz, $startsOn)
            ->addWeeks($weeksFromNow)
            ->toDateString();
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
    private function presentWeekPerson(User $person, iterable $shifts, iterable $records, string $tz, string $timeFormat): array
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

        /*
         * Extra minutes count toward the total and make a day WORKED; a tag on
         * its own does not. "Sick" with no time is a day away, and counting it
         * would pull the average down for being ill.
         */
        $dayRows = [];
        foreach ($records as $record) {
            $row = $this->presentDay($record);
            $total += $row['extra_minutes'] * 60;
            if ($row['extra_minutes'] > 0) {
                $days[$row['date']] = true;
            }
            $dayRows[] = $row;
        }

        $worked = count($days);

        return [
            'id' => $person->id,
            'name' => $person->name,
            'is_active' => (bool) $person->is_active,
            'has_avatar' => $person->avatar_path !== null,
            'role' => $person->role === null ? null : ['id' => $person->role->id, 'name' => $person->role->name],
            'total_seconds' => $total,
            'days_worked' => $worked,
            // Over the days actually WORKED, not over seven: "six hours a day
            // when I am in" is the useful average, and dividing by a fixed week
            // would report every part-timer as failing.
            'average_seconds' => $worked === 0 ? 0 : intdiv($total, $worked),
            'open' => $open,
            'shifts' => $rows,
            // A LIST, not a map keyed by date: an empty PHP array encodes as
            // `[]`, and a client expecting `{}` for a week with no records
            // would fail on exactly the commonest week there is.
            'days' => $dayRows,
            /*
             * The schedule the clock is running on for this person, for the
             * Work schedule card on their week (2026-09-24). Effective, not
             * stored: somebody with no personal schedule is on the place's
             * business hours, and the card should say which hours those are
             * rather than "none". `schedule_is_personal` tells the two apart.
             *
             * ponytail: one scheduleFor() per person (an assignment read and a
             * settings read each); batch it if a week ever lists hundreds.
             */
            'schedule' => (object) ($this->scheduleFor($person)['days'] ?? []),
            'schedule_is_personal' => is_array($person->work_schedule),
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
        $query->whereKey($actor->getKey());

        // Work Hours without `work_hours.all` is the holder's own time only.
        if (! $actor->hasPermission('work_hours.all')) {
            return;
        }

        $query
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
    public function presentShift(TimeClockShift $shift, string $tz, string $timeFormat): array
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
            /*
             * The same instants as "Y-m-d H:i" in the VIEW's zone, for the edit
             * dialog's pickers (2026-09-24). The client cannot derive them: it
             * has UTC and a formatted string, and converting UTC with the
             * device's zone is the error that ran every reminder an hour late.
             * The correction endpoint reads the same shape back in the same
             * zone, so a picker never touches a timezone at all.
             */
            'clocked_in_local' => Carbon::parse($shift->clocked_in_at)->timezone($tz)->format('Y-m-d H:i'),
            'clocked_out_local' => $open ? null : Carbon::parse($shift->clocked_out_at)->timezone($tz)->format('Y-m-d H:i'),
            'duration_seconds' => $open
                ? null
                : $shift->clocked_out_at->getTimestamp() - $shift->clocked_in_at->getTimestamp(),
            'open' => $open,
            'ended_by' => $shift->ended_by,
            // `hr` (Edited), `request` (Shift corrected) or null.
            'edited' => $shift->edit_source,
            'outside_schedule' => (bool) $shift->outside_schedule,
            'extensions' => (int) $shift->extensions,
        ];
    }

    public function wallClock(mixed $at, string $tz, string $timeFormat): string
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
