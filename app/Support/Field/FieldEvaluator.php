<?php

namespace App\Support\Field;

use App\Models\FieldAlert;
use App\Models\FieldLocation;
use App\Models\FieldPresence;
use App\Models\Job;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns points into facts: arrived, left, stopped too long, lost signal, SOS.
 *
 * All of it runs here, on the server, so every platform raises the same
 * triggers and a rule can change without an app release (spec decision 6).
 */
final class FieldEvaluator
{
    /** Moving less than this from where they stopped is still standing still. */
    public const STILL_METRES = 75;

    public function __construct(
        private readonly FieldTracking $tracking,
        private readonly FieldJobs $jobs,
        private readonly EventEmitter $events,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Store the points the tracking window allows and evaluate the newest.
     *
     * @param  array<int, array{lat: float, lng: float, recorded_at: string, accuracy_m?: ?int, speed_mps?: ?float, heading?: ?int}>  $points
     * @return int how many were kept
     */
    public function ingest(User $user, array $points, array $device = []): int
    {
        $instants = array_map(fn (array $p) => Carbon::parse($p['recorded_at'])->utc(), $points);
        $allowed = $this->tracking->covers($user, $instants);

        $kept = [];
        foreach ($points as $i => $p) {
            if ($allowed[$i]) {
                $kept[] = [
                    'latitude' => $p['lat'],
                    'longitude' => $p['lng'],
                    'accuracy_m' => $p['accuracy_m'] ?? null,
                    'speed_mps' => $p['speed_mps'] ?? null,
                    'heading' => $p['heading'] ?? null,
                    'recorded_at' => $instants[$i],
                ];
            }
        }

        if ($kept === []) {
            return 0;
        }

        usort($kept, fn ($a, $b) => $a['recorded_at'] <=> $b['recorded_at']);

        DB::transaction(function () use ($user, $kept) {
            foreach ($kept as $row) {
                FieldLocation::query()->create($row + ['user_id' => $user->getKey()]);
            }
        });

        $this->evaluate($user, end($kept), $device);

        return count($kept);
    }

    /** @param array{latitude: float, longitude: float, accuracy_m: ?int, recorded_at: Carbon} $point */
    private function evaluate(User $user, array $point, array $device = []): void
    {
        $presence = FieldPresence::query()->firstOrNew(['user_id' => $user->getKey()]);
        $at = $point['recorded_at'];

        // A late-arriving batch must not move the map backwards.
        if ($presence->exists && $presence->last_seen_at->gt($at)) {
            return;
        }

        $lat = $point['latitude'];
        $lng = $point['longitude'];

        // Recovered by itself: the alert it raised closes, so the dashboard's
        // open alerts are the ones still true (review 2026-11-02: 270 alerts
        // in 30 days on prod, not one acknowledged). acknowledged_by stays
        // null, which is how a row says nobody had to.
        $moved = ! $presence->exists
            || FieldMaps::metres($presence->anchor_latitude, $presence->anchor_longitude, $point['latitude'], $point['longitude']) > self::STILL_METRES;
        $recovered = array_merge(
            $presence->signal_lost_alerted ? [FieldAlert::SIGNAL_LOST] : [],
            $presence->stopped_alerted && $moved ? [FieldAlert::STOPPED] : [],
        );
        if ($presence->exists && $recovered !== []) {
            FieldAlert::query()->where('user_id', $user->getKey())->whereIn('kind', $recovered)
                ->whereNull('acknowledged_at')->update(['acknowledged_at' => now()]);
        }

        if (! $presence->exists
            || FieldMaps::metres($presence->anchor_latitude, $presence->anchor_longitude, $lat, $lng) > self::STILL_METRES) {
            $presence->fill([
                'anchor_latitude' => $lat, 'anchor_longitude' => $lng,
                'anchored_at' => $at, 'stopped_alerted' => false,
            ]);
        }

        $presence->fill([
            'latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => $point['accuracy_m'],
            'last_seen_at' => $at, 'signal_lost_alerted' => false,
        ]);

        // What the phone says about itself. Only what it sent: an older app
        // that sends nothing leaves the last known values alone.
        $presence->fill(array_intersect_key($device, array_flip([
            'battery_pct', 'charging', 'platform', 'os_version', 'device_model', 'app_version',
        ])));

        $radius = $this->tracking->arrivalRadius($user);

        // Left the job they were at?
        if ($presence->at_job_id !== null) {
            $job = Job::query()->with('customer')->find($presence->at_job_id);
            $there = $job ? $this->jobs->pointFor($job) : null;

            if ($job === null || $there === null
                || FieldMaps::metres($there['lat'], $there['lng'], $lat, $lng) > $radius * 1.5) {
                $presence->at_job_id = null;

                if ($job !== null) {
                    $this->events->emit('field.left_job', $job, $this->payload($user, $lat, $lng));
                }
            }
        }

        // Arrived at one of today's?
        if ($presence->at_job_id === null) {
            $nearest = null;
            $best = INF;

            foreach ($this->jobs->on($user, $this->jobs->today($user)) as $job) {
                $there = $this->jobs->pointFor($job);
                $d = $there ? FieldMaps::metres($there['lat'], $there['lng'], $lat, $lng) : INF;

                if ($d <= $radius && $d < $best) {
                    [$nearest, $best] = [$job, $d];
                }
            }

            if ($nearest !== null) {
                $presence->at_job_id = $nearest->getKey();
                $this->events->emit('field.arrived_at_job', $nearest, $this->payload($user, $lat, $lng));
            }
        }

        // Standing still, away from any job, for too long?
        $minutes = $this->tracking->stoppedMinutes($user);

        if ($presence->at_job_id === null
            && ! $presence->stopped_alerted
            && $presence->anchored_at->copy()->addMinutes($minutes)->lte($at)) {
            $presence->stopped_alerted = true;
            $this->raise($user, FieldAlert::STOPPED, 'field.stopped_too_long', $lat, $lng,
                ['minutes' => $minutes]);
        }

        $presence->save();
    }

    public function sos(User $user, ?float $lat, ?float $lng): FieldAlert
    {
        $alert = $this->raise($user, FieldAlert::SOS, 'field.sos', $lat, $lng);
        $this->audit->log('field.sos', $alert, metadata: ['latitude' => $lat, 'longitude' => $lng]);

        // Straight away, rule or no rule: everybody who may watch the map and can see this person.
        foreach ($this->watchers($user) as $manager) {
            $this->notifications->dispatch(
                title: "SOS from {$user->name}",
                body: 'Pressed at '.now()->format('g:i A').'. '
                    .($lat === null ? 'Sent from a computer, without a location.' : 'Open Field to see where.'),
                subject: $alert,
                onlyTo: $manager,
            );
        }

        return $alert;
    }

    /** The lost-signal half, which only the clock can notice. Called by ezbizi:field-sweep inside a tenant. */
    public function checkSilence(FieldPresence $presence): bool
    {
        $user = $presence->user;

        if ($user === null || $presence->signal_lost_alerted) {
            return false;
        }

        $minutes = $this->tracking->lostSignalMinutes($user);

        if ($presence->last_seen_at->copy()->addMinutes($minutes)->gt(now())
            || ! $this->tracking->covers($user, [now()])[0]) {
            return false;
        }

        $presence->update(['signal_lost_alerted' => true]);
        $this->raise($user, FieldAlert::SIGNAL_LOST, 'field.signal_lost',
            $presence->latitude, $presence->longitude,
            ['minutes' => $minutes, 'last_seen_at' => $presence->last_seen_at->toIso8601ZuluString()]);

        return true;
    }

    /** @return Collection<int, User> ponytail: one query per active user; fine at tens of managers. */
    public function watchers(User $about)
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($about->getKey())
            ->get()
            ->filter(fn (User $u) => $u->hasPermission('field.map.view')
                && $this->tracking->visiblePeople($u)->whereKey($about->getKey())->exists())
            ->values();
    }

    private function raise(User $user, string $kind, string $event, ?float $lat, ?float $lng, array $extra = []): FieldAlert
    {
        $alert = FieldAlert::query()->create([
            'user_id' => $user->getKey(), 'kind' => $kind, 'latitude' => $lat, 'longitude' => $lng,
        ]);

        $this->events->emit($event, $alert, $this->payload($user, $lat, $lng) + $extra + ['kind' => $kind]);

        return $alert;
    }

    private function payload(User $user, ?float $lat, ?float $lng): array
    {
        return [
            'user_id' => $user->getKey(),
            'user_name' => $user->name,
            'latitude' => $lat,
            'longitude' => $lng,
            'map_url' => $lat === null ? null : "https://www.google.com/maps?q={$lat},{$lng}",
        ];
    }
}
