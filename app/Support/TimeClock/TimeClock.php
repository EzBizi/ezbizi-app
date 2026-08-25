<?php

namespace App\Support\TimeClock;

use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TimeClock
{
    public function __construct(
        private readonly SettingsResolver $settings,
        private readonly EventEmitter $events,
        private readonly AuditLogger $audit,
    ) {}

    public function organizationEnabled(Organization $organization): bool
    {
        return (bool) $this->settings->get('time_clock.enabled', $organization);
    }

    /**
     * Chrome for `/me` and the punch responses (design §8).
     *
     * `show_button` follows §4: org on and (`time_clock.view` or an open shift).
     * Instants on the wire are UTC ISO-8601 with a Z suffix. The client never
     * supplies a time — `now()` is the only clock.
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
            'show_button' => $enabled && ($canClock || $open !== null),
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
}
