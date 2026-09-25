<?php

namespace App\Http\Controllers\Api;

use App\Models\FieldAlert;
use App\Models\FieldGeocode;
use App\Models\FieldLocation;
use App\Models\FieldPresence;
use App\Models\Job;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Field\FieldEvaluator;
use App\Support\Field\FieldJobs;
use App\Support\Field\FieldMaps;
use App\Support\Field\FieldTracking;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Field (docs/superpowers/specs/2026-09-25-field-module-design.md).
 *
 * Who you see is FieldTracking::visiblePeople, the one predicate the glance
 * and the SOS notifier share.
 */
class FieldController extends Controller
{
    /** A position older than this is not "now" for the map or a route. */
    private const FRESH_MINUTES = 30;

    public function __construct(
        private readonly FieldTracking $tracking,
        private readonly FieldEvaluator $evaluator,
        private readonly FieldJobs $jobs,
        private readonly FieldMaps $maps,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /field/me — the phone's instructions. Anybody signed in may ask about themselves. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tracking->status($request->user())]);
    }

    /** POST /field/locations */
    public function ingest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:100'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            // No future points, and nothing older than a day: a phone that
            // was off for a week has nothing the map still wants.
            'points.*.recorded_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(2)->toIso8601String(),
                'after:'.now()->subDay()->toIso8601String()],
            'points.*.accuracy_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'points.*.speed_mps' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'points.*.heading' => ['nullable', 'integer', 'min:0', 'max:360'],
        ]);

        $kept = $this->evaluator->ingest($request->user(), $data['points']);

        return response()->json(['data' => [
            'kept' => $kept,
            'refused' => count($data['points']) - $kept,
            'status' => $this->tracking->status($request->user()),
        ]]);
    }

    /** POST /field/sos */
    public function sos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $alert = $this->evaluator->sos($request->user(), (float) $data['lat'], (float) $data['lng']);

        return response()->json(['data' => $this->presentAlert($alert->load('user'))], 201);
    }

    /** POST /field/alerts/{alert}/acknowledge */
    public function acknowledge(Request $request, FieldAlert $alert): JsonResponse
    {
        $this->assertSees($request->user(), $alert->user);

        if ($alert->acknowledged_at === null) {
            $alert->update(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->getKey()]);
            $this->audit->log('field.alert_acknowledged', $alert, metadata: ['kind' => $alert->kind]);
        }

        return response()->json(['data' => $this->presentAlert($alert->load('user'))]);
    }

    /** GET /field/dashboard */
    public function dashboard(Request $request): JsonResponse
    {
        $people = $this->tracking->trackedPeople($request->user());
        $ids = $people->modelKeys();
        $presences = FieldPresence::query()->whereIn('user_id', $ids)->get();
        $fresh = $presences->filter(fn (FieldPresence $p) => $p->last_seen_at->gt(now()->subMinutes(self::FRESH_MINUTES)));

        $alerts = FieldAlert::query()
            ->with('user')
            ->whereIn('user_id', $ids)
            ->where(fn ($q) => $q->whereNull('acknowledged_at')->orWhere('created_at', '>=', now()->subDay()))
            ->latest()
            ->limit(50)
            ->get();

        return response()->json(['data' => [
            'counts' => [
                'tracked' => count($ids),
                'reporting' => $fresh->count(),
                'at_job' => $fresh->whereNotNull('at_job_id')->count(),
                'stopped' => $fresh->where('stopped_alerted', true)->count(),
                'silent' => $presences->where('signal_lost_alerted', true)->count(),
                'open_alerts' => $alerts->whereNull('acknowledged_at')->count(),
            ],
            'alerts' => $alerts->map(fn (FieldAlert $a) => $this->presentAlert($a))->values(),
            'me' => $this->tracking->status($request->user()),
            'maps_configured' => $this->maps->configured(),
        ]]);
    }

    /** GET /field/map — everyone's latest position, or ?user_id=&date= for one person's trail. */
    public function map(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d', 'required_with:user_id'],
        ]);

        if (isset($data['user_id'])) {
            $person = $this->visiblePerson($request->user(), $data['user_id']);
            $tz = (string) $this->tracking->setting('timezone', $person);
            $from = Carbon::parse($data['date'].' 00:00:00', $tz)->utc();

            $trail = FieldLocation::query()
                ->where('user_id', $person->getKey())
                ->whereBetween('recorded_at', [$from, $from->copy()->addDay()])
                ->orderBy('recorded_at')
                ->limit(5000) // ponytail: a full day at 50 m spacing is well under this; thin it if drives get longer.
                ->get(['latitude', 'longitude', 'recorded_at', 'speed_mps']);

            return response()->json(['data' => [
                'user' => ['id' => $person->getKey(), 'name' => $person->name],
                'points' => $trail->map(fn (FieldLocation $l) => [
                    'lat' => $l->latitude, 'lng' => $l->longitude,
                    'at' => $l->recorded_at->toIso8601ZuluString(), 'speed_mps' => $l->speed_mps,
                ])->values(),
            ]]);
        }

        $people = $this->tracking->trackedPeople($request->user())->keyBy(fn (User $u) => $u->getKey());
        $presences = FieldPresence::query()->with('atJob:id,number,title')->whereIn('user_id', $people->keys())->get();

        return response()->json(['data' => $presences->map(fn (FieldPresence $p) => [
            'user' => ['id' => $p->user_id, 'name' => $people[$p->user_id]->name],
            'lat' => $p->latitude,
            'lng' => $p->longitude,
            'accuracy_m' => $p->accuracy_m,
            'last_seen_at' => $p->last_seen_at->toIso8601ZuluString(),
            'fresh' => $p->last_seen_at->gt(now()->subMinutes(self::FRESH_MINUTES)),
            'stopped' => $p->stopped_alerted,
            'silent' => $p->signal_lost_alerted,
            'at_job' => $p->atJob ? ['id' => $p->atJob->id, 'number' => $p->atJob->number, 'title' => $p->atJob->title] : null,
        ])->values()]);
    }

    /** GET /field/map/tiles */
    public function tiles(): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->maps->tileUrl()]]);
    }

    /** GET /field/people — who the Route picker may choose. */
    public function people(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tracking->trackedPeople($request->user())
            ->map(fn (User $u) => ['id' => $u->getKey(), 'name' => $u->name])
            ->sortBy('name')->values()]);
    }

    /** GET /field/route?user_id=&date= */
    public function route(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $actor = $request->user();
        $person = isset($data['user_id']) ? $this->visiblePerson($actor, $data['user_id']) : $actor;
        $date = $data['date'] ?? $this->jobs->today($person);
        $today = $date === $this->jobs->today($person);

        $jobs = $this->jobs->on($person, $date);
        $stops = $jobs->map(fn (Job $job) => ['job' => $job, 'point' => $this->jobs->pointFor($job, ask: true)]);
        [$routable, $unroutable] = $stops->partition(fn (array $s) => $s['point'] !== null);
        $routable = $routable->values();

        $presence = FieldPresence::query()->where('user_id', $person->getKey())->first();
        $start = $today && $presence && $presence->last_seen_at->gt(now()->subMinutes(self::FRESH_MINUTES))
            ? ['lat' => $presence->latitude, 'lng' => $presence->longitude]
            : null;

        $plan = $this->plan($person, $date, $routable, $start);

        return response()->json(['data' => [
            'user' => ['id' => $person->getKey(), 'name' => $person->name],
            'date' => $date,
            'starts_from' => $start ? 'current_position' : 'first_job',
            'optimised' => $plan['optimised'],
            'reason' => $plan['reason'],
            'stops' => $plan['stops'],
            'unroutable' => $unroutable->map(fn (array $s) => $this->presentJob($s['job']) + [
                'why' => $s['job']->customer && FieldGeocode::addressOf($s['job']->customer)
                    ? 'Google could not place this address.'
                    : 'The customer has no street address.',
            ])->values(),
        ]]);
    }

    /**
     * Order the stops and time them. Never writes to a job (spec decision 3).
     *
     * @param  Collection<int, array{job: Job, point: array{lat: float, lng: float}}>  $stops
     * @param  array{lat: float, lng: float}|null  $start
     */
    private function plan(User $person, string $date, Collection $stops, ?array $start): array
    {
        if ($stops->isEmpty()) {
            return ['optimised' => false, 'reason' => null, 'stops' => []];
        }

        // Starting from where they stand, every stop but the day's last is
        // free to move; from the first job, that one stays first too. The
        // day's last booking fixes the end, because that is where the day ends.
        $fixedFirst = $start === null;
        $origin = $start ?? $stops->first()['point'];
        $last = $stops->count() - 1;
        $middle = $stops->slice($fixedFirst ? 1 : 0, max(0, $last - ($fixedFirst ? 1 : 0)))->values();

        $answer = null;
        if (! ($fixedFirst && $stops->count() === 1)) {
            $key = 'field.route.'.md5(json_encode([$person->getKey(), $date, $origin,
                $stops->map(fn ($s) => [$s['job']->getKey(), $s['job']->scheduled_for?->toIso8601String(), $s['point']])]));

            $answer = Cache::get($key);
            if ($answer === null) {
                $answer = $this->maps->optimise($origin, $middle->pluck('point')->all(), $stops[$last]['point']);
                if ($answer !== null) {
                    Cache::put($key, $answer, now()->addMinutes(10));
                }
            }
        }

        $ordered = collect();
        if ($fixedFirst) {
            $ordered->push($stops->first());
        }
        foreach ($answer['order'] ?? array_keys($middle->all()) as $i) {
            $ordered->push($middle[$i]);
        }
        if ($last > 0 || ! $fixedFirst) {
            $ordered->push($stops[$last]);
        }

        // Legs line up with the stops that FOLLOW the origin.
        $legs = $answer['legs'] ?? [];
        $legFor = fn (int $position) => $legs[$fixedFirst ? $position - 1 : $position] ?? null;

        $clock = $start !== null ? now() : ($ordered->first()['job']->scheduled_for ?? now());
        $out = [];

        foreach ($ordered->values() as $position => $stop) {
            /** @var Job $job */
            $job = $stop['job'];
            $leg = $position === 0 && $fixedFirst ? null : $legFor($position);
            $arrival = $leg ? $clock->copy()->addSeconds($leg['seconds']) : $clock->copy();
            $scheduled = $job->scheduled_for;
            // Five minutes' grace: a route that is a minute late is on time to a customer.
            $late = $answer !== null && $scheduled !== null && $arrival->gt($scheduled->copy()->addMinutes(5));

            $out[] = $this->presentJob($job) + [
                'lat' => $stop['point']['lat'],
                'lng' => $stop['point']['lng'],
                'drive_minutes' => $leg ? (int) ceil($leg['seconds'] / 60) : null,
                'drive_km' => $leg ? round($leg['meters'] / 1000, 1) : null,
                // In the franchise's zone, like scheduled_for, so the two read side by side.
                'eta' => $answer !== null ? $arrival->copy()->setTimezone($scheduled?->getTimezone() ?? 'UTC')->toIso8601String() : null,
                'late' => $late,
                'navigate_url' => 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination='
                    .$stop['point']['lat'].','.$stop['point']['lng'],
            ];

            $starts = $scheduled !== null && $scheduled->gt($arrival) ? $scheduled : $arrival;
            $clock = $starts->copy()->addMinutes((int) ($job->duration_minutes ?? 60));
        }

        return [
            'optimised' => $answer !== null,
            'reason' => $answer !== null ? null : ($this->maps->configured()
                ? 'Google did not answer, so the stops are in booked order.'
                : 'Route suggestions switch on once the Google Maps key is set up. The stops are in booked order.'),
            'stops' => $out,
        ];
    }

    private function presentJob(Job $job): array
    {
        return [
            'job_id' => $job->getKey(),
            'number' => $job->number,
            'title' => $job->title,
            'status' => $job->status,
            'customer' => $job->customer?->name,
            'address' => $job->customer ? FieldGeocode::addressOf($job->customer) : null,
            'scheduled_for' => $job->scheduled_for?->toIso8601String(),
            'duration_minutes' => $job->duration_minutes,
        ];
    }

    private function presentAlert(FieldAlert $alert): array
    {
        return [
            'id' => $alert->getKey(),
            'kind' => $alert->kind,
            'user' => ['id' => $alert->user_id, 'name' => $alert->user?->name],
            'lat' => $alert->latitude,
            'lng' => $alert->longitude,
            'created_at' => $alert->created_at->toIso8601ZuluString(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601ZuluString(),
        ];
    }

    private function visiblePerson(User $actor, string $id): User
    {
        return $this->tracking->visiblePeople($actor)->whereKey($id)->firstOrFail();
    }

    private function assertSees(User $actor, ?User $person): void
    {
        if ($person === null || ! $this->tracking->visiblePeople($actor)->whereKey($person->getKey())->exists()) {
            throw new AuthorizationException(Refusal::permission('acknowledge an alert about this person')->message());
        }
    }
}
