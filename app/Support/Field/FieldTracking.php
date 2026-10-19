<?php

namespace App\Support\Field;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Access\ScopeReach;
use App\Support\Monitoring\MonitoringView;
use App\Support\Settings\SettingsResolver;
use App\Support\TimeClock\TimeClock;
use App\Support\TimeClock\WorkSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Whether a person should be reporting their location at a given instant.
 *
 * The phone asks, but the SERVER decides: ingest runs every point through
 * covers(), so a phone that keeps sending after Clock Out stores nothing.
 */
final class FieldTracking
{
    public const ALWAYS = 'always';

    public const SCHEDULED = 'scheduled';

    public const CLOCKED_IN = 'clocked_in';

    public function __construct(
        private readonly SettingsResolver $settings,
        private readonly TimeClock $timeClock,
        private readonly ScopeReach $reach,
    ) {}

    /**
     * People this actor may see on Field: reach AND the top-bar view, grouped,
     * the way Monitoring lists people. Without the view, somebody standing in
     * one company would see a sibling company's technicians. Always yourself.
     */
    public function visiblePeople(User $actor): Builder
    {
        $inView = User::query()->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor));
        MonitoringView::people($inView, $actor);

        return User::query()->where(fn (Builder $q) => $q
            ->whereKey($actor->getKey())
            ->orWhereIn('id', $inView->select('id')));
    }

    /** @return Collection<int, User> ponytail: role grants are checked in PHP; push into SQL if orgs reach thousands. */
    public function trackedPeople(User $actor): Collection
    {
        return $this->visiblePeople($actor)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $this->isTracked($u))
            ->values();
    }

    /** The place a person's Field settings resolve at: their first assignment, else the organization. */
    public function placeOf(User $user): Organization|Company|Franchise
    {
        $assignment = $user->assignments()->orderBy('created_at')->first();

        return match ($assignment?->scopeType()) {
            Assignment::SCOPE_FRANCHISE => $assignment->assignedFranchise,
            Assignment::SCOPE_COMPANY => $assignment->assignedCompany,
            default => null,
        } ?? $user->organization;
    }

    public function setting(string $key, User $user): mixed
    {
        return $this->settings->get($key, $this->placeOf($user));
    }

    public function arrivalRadius(User $user): int
    {
        return (int) $this->setting('field.arrival_radius_m', $user);
    }

    public function stoppedMinutes(User $user): int
    {
        return (int) $this->setting('field.stopped_minutes', $user);
    }

    public function lostSignalMinutes(User $user): int
    {
        return (int) $this->setting('field.lost_signal_minutes', $user);
    }

    /**
     * May this person start a recording on a job in this state, and must they
     * confirm they told the customer first?
     *
     * @return array{allowed: bool, announce: bool, reason: ?string, notice: string}
     */
    public function recordingRule(User $user, ?string $state): array
    {
        $notice = (string) $this->setting('field.recording_notice_text', $user);
        if (! $user->hasPermission('field.record') || ! $this->setting('field.recording_enabled', $user)) {
            return ['allowed' => false, 'announce' => false, 'notice' => $notice,
                'reason' => 'Recording is not turned on for you.'];
        }
        $allParty = $state !== null && in_array(strtoupper($state), $this->recordingNoticeStates($user), true);
        if ($allParty && $this->setting('field.recording_all_party_rule', $user) === 'block') {
            return ['allowed' => false, 'announce' => false, 'notice' => $notice,
                'reason' => 'Recording is not allowed in '.strtoupper($state).', where everyone must consent.'];
        }

        return ['allowed' => true, 'announce' => $allParty, 'notice' => $notice, 'reason' => null];
    }

    /**
     * How closest-office estimates a drive Google could not route.
     *
     * @return array{mph: int, road_factor: float, similar_miles: float}
     */
    public function estimateTuning(User $user): array
    {
        return [
            'mph' => max(5, (int) $this->setting('field.estimate_mph', $user)),
            'road_factor' => max(1.0, (float) $this->setting('field.estimate_road_factor', $user)),
            'similar_miles' => (float) $this->setting('field.similar_within_miles', $user),
        ];
    }

    /** Field › Map shows this person: their role is ticked, or nothing is ticked. */
    public function shownOnMap(User $user): bool
    {
        // An id that is no longer a role is ignored; empty means everybody.
        $roles = Role::query()->whereKey((array) $this->setting('field.map_roles', $user))->pluck('id')->all();

        return $roles === [] || in_array($user->role_id, $roles, true);
    }

    /** @return list<string> two-letter codes, upper case */
    public function recordingNoticeStates(User $user): array
    {
        preg_match_all('/[A-Za-z]{2}/', (string) $this->setting('field.recording_notice_states', $user), $m);

        return array_map('strtoupper', $m[0]);
    }

    public function mode(User $user): string
    {
        return (string) $this->setting('field.tracking_mode', $user);
    }

    /**
     * Administrators ARE tracked, like any tracked role, within the same
     * window. Jose, 2026-09-28, overturning the first version's exclusion:
     * "admins should be tracked as well, i never said they shouldn't" and,
     * asked whether always or only when switched on, "Always, like any
     * tracked role." Role::hasPermission is true for Administrator on every
     * key, so no clause is needed; FieldTest pins it.
     */
    public function isTracked(User $user): bool
    {
        return $user->is_active && $user->hasPermission('field.tracked');
    }

    /**
     * What /field/me tells the phone.
     *
     * @return array{tracked: bool, should_track: bool, mode: string, reason: string}
     */
    public function status(User $user): array
    {
        $mode = $this->mode($user);

        if (! $this->isTracked($user)) {
            return ['tracked' => false, 'should_track' => false, 'mode' => $mode,
                'reason' => 'Your role is not tracked.'];
        }

        $on = $this->covers($user, [now()])[0];

        return [
            'tracked' => true,
            'should_track' => $on,
            'mode' => $mode,
            // Says whether sharing is on NOW, then when it happens (UI review,
            // 2026-10-08: the off icon beside "Shared while you are clocked
            // in" read as a contradiction).
            'reason' => match (true) {
                $on => 'Sharing your location with your managers now.',
                $mode === self::CLOCKED_IN => 'Not sharing now. Your location is shared while you are clocked in.',
                default => 'Not sharing now. Your location is shared during your scheduled work hours.',
            },
        ];
    }

    /**
     * For each instant, may a point recorded then be kept?
     *
     * @param  array<int, Carbon>  $instants
     * @return array<int, bool> same keys as $instants
     */
    public function covers(User $user, array $instants): array
    {
        if ($instants === [] || ! $this->isTracked($user)) {
            return array_map(fn () => false, $instants);
        }

        return match ($this->mode($user)) {
            self::ALWAYS => array_map(fn () => true, $instants),
            self::SCHEDULED => $this->withinSchedule($user, $instants),
            default => $this->withinShifts($user, $instants),
        };
    }

    /** @param array<int, Carbon> $instants */
    private function withinSchedule(User $user, array $instants): array
    {
        $schedule = $this->timeClock->scheduleFor($user);

        return array_map(function (Carbon $at) use ($schedule) {
            $window = WorkSchedule::windowFor($schedule, $at);

            return $window !== null && $at->betweenIncluded($window['start_at'], $window['end_at']);
        }, $instants);
    }

    /** @param array<int, Carbon> $instants */
    private function withinShifts(User $user, array $instants): array
    {
        $from = min($instants);
        $to = max($instants);

        // One query for the batch: every shift that overlaps its span.
        $shifts = TimeClockShift::query()
            ->where('user_id', $user->getKey())
            ->where('clocked_in_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('clocked_out_at')->orWhere('clocked_out_at', '>=', $from))
            ->get(['clocked_in_at', 'clocked_out_at']);

        return array_map(fn (Carbon $at) => $shifts->contains(
            fn (TimeClockShift $s) => $at->gte($s->clocked_in_at)
                && ($s->clocked_out_at === null || $at->lte($s->clocked_out_at))
        ), $instants);
    }
}
