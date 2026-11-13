<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\FieldAlert;
use App\Models\FieldGeocode;
use App\Models\FieldLocation;
use App\Models\FieldPresence;
use App\Models\Job;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Field\FieldEvaluator;
use App\Support\Field\FieldJobs;
use App\Support\Field\FieldMaps;
use App\Support\Field\FieldTracking;
use App\Support\Scoping\ViewScope;
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
        $user = $request->user();
        $job = FieldPresence::query()->where('user_id', $user->getKey())->first()?->atJob()->with('customer:id,state')->first();

        return response()->json(['data' => $this->tracking->status($user) + [
            // The record card: whether recording is allowed here and now, and
            // whether the technician must confirm they told the customer.
            'recording' => $this->tracking->recordingRule($user, $job?->customer?->state),
            'at_job' => $job === null ? null : ['id' => $job->id, 'number' => $job->number],
        ]]);
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
            'device' => ['nullable', 'array'],
            'device.battery_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'device.charging' => ['nullable', 'boolean'],
            'device.platform' => ['nullable', 'string', 'max:16'],
            'device.os_version' => ['nullable', 'string', 'max:64'],
            'device.device_model' => ['nullable', 'string', 'max:100'],
            'device.app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $kept = $this->evaluator->ingest($request->user(), $data['points'], $data['device'] ?? []);

        return response()->json(['data' => [
            'kept' => $kept,
            'refused' => count($data['points']) - $kept,
            'status' => $this->tracking->status($request->user()),
        ]]);
    }

    /** POST /field/sos */
    public function sos(Request $request): JsonResponse
    {
        // Optional: an SOS from a desktop carries no position (the desktop
        // apps never ask for location), and an SOS without one still goes out.
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        $alert = $this->evaluator->sos(
            $request->user(),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

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

        $open = FieldAlert::query()->whereIn('user_id', $ids)->whereNull('acknowledged_at')
            ->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')->map(fn ($n) => (int) $n)->all();

        return response()->json(['data' => [
            'counts' => [
                'tracked' => count($ids),
                'reporting' => $fresh->count(),
                'at_job' => $fresh->whereNotNull('at_job_id')->count(),
                // Open alerts by kind, uncapped: the total is the sum of its tiles (UI audit 10.1).
                'stopped' => $open[FieldAlert::STOPPED] ?? 0,
                'silent' => $open[FieldAlert::SIGNAL_LOST] ?? 0,
                'sos' => $open[FieldAlert::SOS] ?? 0,
                'open_alerts' => array_sum($open),
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

        // EVERY tracked person, not only those who have reported: the list,
        // the Dashboard and the phone chip must count the same people (UI
        // review, 2026-10-08: "8 Tracked" beside "People 0"). Somebody who
        // has never reported has no position and no pin, and says so.
        // Only the roles Settings › Field shows on the map (Jose, 2026-10-19).
        $people = $this->tracking->trackedPeople($request->user())
            ->filter(fn (User $u) => $this->tracking->shownOnMap($u))->values();
        $presences = FieldPresence::query()->with('atJob:id,number,title')
            ->whereIn('user_id', $people->modelKeys())->get()->keyBy('user_id');

        return response()->json(['data' => $people->map(function (User $person) use ($presences) {
            $p = $presences[$person->getKey()] ?? null;

            return [
                'user' => ['id' => $person->getKey(), 'name' => $person->name],
                'lat' => $p?->latitude,
                'lng' => $p?->longitude,
                'accuracy_m' => $p?->accuracy_m,
                'last_seen_at' => $p?->last_seen_at->toIso8601ZuluString(),
                'fresh' => $p !== null && $p->last_seen_at->gt(now()->subMinutes(self::FRESH_MINUTES)),
                // Inside their window now (shift, schedule, always): silence is
                // a problem only then, so the map greys out the rest.
                'should_track' => $this->tracking->covers($person, [now()])[0],
                'stopped' => (bool) $p?->stopped_alerted,
                'silent' => (bool) $p?->signal_lost_alerted,
                'at_job' => $p?->atJob ? ['id' => $p->atJob->id, 'number' => $p->atJob->number, 'title' => $p->atJob->title] : null,
                'device' => [
                    'battery_pct' => $p?->battery_pct,
                    'charging' => $p?->charging,
                    'platform' => $p?->platform,
                    'os_version' => $p?->os_version,
                    'model' => $p?->device_model,
                    'app_version' => $p?->app_version,
                ],
            ] + $this->today($person);
        })->sortBy('user.name')->values()]);
    }

    /**
     * Today, where the person works: when tracking started, how far they have
     * gone, how fast (now, on average while moving, at most), when they
     * clocked in, and their next job. Miles, like the business.
     *
     * ponytail: reads the day's points per person; a column on field_presences
     * if a team ever outgrows one query each.
     */
    private function today(User $person): array
    {
        $tz = (string) $this->tracking->setting('timezone', $person);
        $from = now()->timezone($tz)->startOfDay()->utc();

        $points = FieldLocation::query()
            ->where('user_id', $person->getKey())
            ->where('recorded_at', '>=', $from)
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'recorded_at', 'speed_mps']);

        $metres = 0.0;
        for ($i = 1; $i < $points->count(); $i++) {
            $metres += FieldMaps::metres(
                $points[$i - 1]->latitude, $points[$i - 1]->longitude, $points[$i]->latitude, $points[$i]->longitude,
            );
        }

        $last = $points->last();
        // Moving means over 2 mph: parked jitter is not driving.
        $moving = $points->pluck('speed_mps')->filter(fn ($v) => $v !== null && $v > 0.9);
        $shift = TimeClockShift::query()->open()->where('user_id', $person->getKey())->first();
        $next = $this->jobs->on($person, $this->jobs->today($person))
            ->first(fn (Job $j) => $j->scheduled_for !== null && $j->scheduled_for->gte(now()));

        return [
            'since' => $points->first()?->recorded_at->toIso8601ZuluString(),
            'distance_mi' => round($metres / 1609.344, 1),
            'speed_mph' => $last?->speed_mps === null ? null : round($last->speed_mps * 2.23694),
            'avg_speed_mph' => $moving->isEmpty() ? null : round($moving->avg() * 2.23694),
            'top_speed_mph' => $moving->isEmpty() ? null : round($moving->max() * 2.23694),
            'clocked_in_at' => $shift?->clocked_in_at->toIso8601ZuluString(),
            'next_job' => $next === null ? null : [
                'id' => $next->getKey(), 'number' => $next->number,
                'customer' => $next->customer?->name, 'scheduled_for' => $next->scheduled_for->toIso8601String(),
            ],
        ];
    }

    /**
     * GET /field/jobs?date= — the day's jobs in the current view, for the map.
     *
     * Customer names and addresses are Jobs' data, so this needs `jobs.view`
     * on top of the Map tab. Only a stored geocode places a job; the sweep
     * fills those for the next days, so a job without one is listed unplaced
     * rather than holding the request up on Google.
     */
    public function jobs(Request $request): JsonResponse
    {
        $this->mustSeeJobs($request->user());
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? now()->timezone((string) $this->tracking->setting('timezone', $request->user()))->toDateString();

        $jobs = Job::query()
            ->with(['customer', 'franchise'])
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->whereBetween('scheduled_for', ["{$date} 00:00:00", "{$date} 23:59:59"])
            ->orderBy('scheduled_for')
            ->limit(500)
            ->get();

        $performers = User::query()->whereIn('id', $jobs->pluck('performed_by_user_id')->filter()->unique())
            ->pluck('name', 'id');

        // One query for every job's place, not one each: this runs every 30 s per open map.
        $hashOf = fn (Job $j) => $j->customer && ($a = FieldGeocode::addressOf($j->customer)) !== null
            ? FieldGeocode::hash($a) : null;
        $places = FieldGeocode::query()
            ->whereIn('address_hash', $jobs->map($hashOf)->filter()->unique()->values())
            ->where('status', FieldGeocode::OK)
            ->get(['address_hash', 'latitude', 'longitude'])
            ->keyBy('address_hash');

        return response()->json(['data' => $jobs->map(function (Job $job) use ($performers, $places, $hashOf) {
            $place = $places[$hashOf($job)] ?? null;
            $point = $place ? ['lat' => $place->latitude, 'lng' => $place->longitude] : null;

            return $this->presentJob($job) + [
                'lat' => $point['lat'] ?? null,
                'lng' => $point['lng'] ?? null,
                'tech' => $job->performed_by_user_id === null ? null
                    : ['id' => $job->performed_by_user_id, 'name' => $performers[$job->performed_by_user_id] ?? null],
            ];
        })->values(), 'date' => $date]);
    }

    /**
     * GET /field/eta?job_id=&user_id=
     *
     * With a person: their drive to the job from where they are, with the road
     * to draw and a sentence the dispatcher can read to the caller. Without:
     * the five nearest people reporting now, ranked by drive time.
     */
    public function eta(Request $request): JsonResponse
    {
        $actor = $request->user();
        $this->mustSeeJobs($actor);
        $data = $request->validate(['job_id' => ['required', 'uuid'], 'user_id' => ['nullable', 'uuid']]);

        $job = Job::query()->with('customer')
            ->whereIn('franchise_id', ViewScope::forUser($actor)->franchiseIds())
            ->findOrFail($data['job_id']);
        $to = $this->jobs->pointFor($job, ask: true);
        $base = ['job' => $this->presentJob($job), 'placed' => $to !== null,
            'lat' => $to['lat'] ?? null, 'lng' => $to['lng'] ?? null];

        if ($to === null) {
            return response()->json(['data' => $base + ['reason' => 'This job’s address cannot be placed on the map.']]);
        }

        $fresh = fn (User $u) => FieldPresence::query()->where('user_id', $u->getKey())
            ->where('last_seen_at', '>', now()->subMinutes(self::FRESH_MINUTES))->first();

        if (isset($data['user_id'])) {
            $person = $this->visiblePerson($actor, $data['user_id']);
            $at = $fresh($person);
            $base['user'] = ['id' => $person->getKey(), 'name' => $person->name];

            if ($at === null) {
                return response()->json(['data' => $base + [
                    'reason' => "{$person->name} has not reported in the last ".self::FRESH_MINUTES.' minutes.']]);
            }

            return response()->json(['data' => $base + $this->presentDrive($person, $at, $to)]);
        }

        $candidates = $this->tracking->trackedPeople($actor)
            ->map(fn (User $u) => ['user' => $u, 'at' => $fresh($u)])
            ->filter(fn ($c) => $c['at'] !== null)
            ->sortBy(fn ($c) => FieldMaps::metres($c['at']->latitude, $c['at']->longitude, $to['lat'], $to['lng']))
            ->take(5)
            ->values();

        $times = $this->maps->matrix(
            $candidates->map(fn ($c) => ['lat' => $c['at']->latitude, 'lng' => $c['at']->longitude])->all(),
            $to,
        );

        $ranked = $candidates->map(fn ($c, $i) => [
            'user' => ['id' => $c['user']->getKey(), 'name' => $c['user']->name],
            'assigned' => $job->performed_by_user_id === $c['user']->getKey(),
            'minutes' => isset($times[$i]) ? (int) ceil($times[$i]['seconds'] / 60) : null,
            'miles' => isset($times[$i])
                ? round($times[$i]['meters'] / 1609.344, 1)
                : round(FieldMaps::metres($c['at']->latitude, $c['at']->longitude, $to['lat'], $to['lng']) / 1609.344, 1),
        ])->sortBy(fn ($c) => $c['minutes'] ?? PHP_INT_MAX)->values();

        return response()->json(['data' => $base + [
            'candidates' => $ranked,
            'reason' => $times === null ? 'Drive times need the Google Maps key; these are straight-line miles.' : null,
        ]]);
    }

    /** @param array{lat: float, lng: float} $to */
    private function presentDrive(User $person, FieldPresence $at, array $to): array
    {
        $from = ['lat' => $at->latitude, 'lng' => $at->longitude];
        $drive = $this->maps->drive($from, $to);

        if ($drive === null) {
            return ['from' => $from, 'minutes' => null, 'polyline' => null,
                'miles' => round(FieldMaps::metres($from['lat'], $from['lng'], $to['lat'], $to['lng']) / 1609.344, 1),
                'reason' => 'Drive time needs the Google Maps key; this is the straight-line distance.'];
        }

        $minutes = (int) ceil($drive['seconds'] / 60);
        $first = explode(' ', trim($person->name))[0];
        $arrive = now()->addSeconds($drive['seconds'])
            ->timezone((string) $this->tracking->setting('timezone', $person))->format('g:i A');

        return [
            'from' => $from,
            'minutes' => $minutes,
            'miles' => round($drive['meters'] / 1609.344, 1),
            'arrive_at' => $arrive,
            'polyline' => $drive['polyline'],
            // What the dispatcher reads to the caller, word for word.
            'say' => "{$first} is about {$minutes} minutes away and should arrive around {$arrive}.",
        ];
    }

    private function mustSeeJobs(User $user): void
    {
        if (! $user->hasPermission('jobs.view')) {
            throw new AuthorizationException(Refusal::permission('see jobs on the map')->message());
        }
    }

    /**
     * GET /field/places — the companies in the current view that have an
     * address, as headquarters on the map, with whether each has a logo
     * (the client fetches it from /branding/companies/{id}). A company
     * address is geocoded the first time it is asked for; there are few.
     */
    public function places(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->offices($request->user())]);
    }

    /**
     * GET /field/closest-office?zip= (or ?lat=&lng=): the offices you can see,
     * nearest by drive time first (A1 Tools' ZIP to closest office, 2026-10-19).
     * Without a Maps key, or when Google cannot route, the straight-line
     * distance times the road factor at the average speed estimates it (by:
     * estimate). Both are Field settings.
     */
    public function closestOffice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'zip' => ['required_without:lat', 'nullable', 'regex:/^\d{5}(-\d{4})?$/'],
            'lat' => ['required_without:zip', 'nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        $to = isset($data['lat'])
            ? ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']]
            : $this->jobs->pointAt(substr($data['zip'], 0, 5).', USA', ask: true);
        if ($to === null) {
            return response()->json(['message' => 'That ZIP code could not be found on the map.'], 422);
        }

        $offices = $this->offices($request->user());
        $drives = $this->maps->matrix(array_map(fn ($o) => ['lat' => $o['lat'], 'lng' => $o['lng']], $offices), $to) ?? [];

        $user = $request->user();
        ['mph' => $mph, 'road_factor' => $winding, 'similar_miles' => $similar] = $this->tracking->estimateTuning($user);

        $ranked = collect($offices)->map(function (array $office, int $i) use ($drives, $to, $mph, $winding) {
            if (isset($drives[$i])) {
                return ['office' => $office, 'minutes' => (int) round($drives[$i]['seconds'] / 60),
                    'miles' => round($drives[$i]['meters'] / 1609.344, 1), 'by' => 'drive'];
            }
            // No route: the crow-flies distance, stretched by the road factor,
            // at the organization's average speed.
            $miles = FieldMaps::metres($office['lat'], $office['lng'], $to['lat'], $to['lng']) / 1609.344 * $winding;

            return ['office' => $office, 'minutes' => (int) round($miles / $mph * 60),
                'miles' => round($miles, 1), 'by' => 'estimate'];
        })->sortBy(fn ($r) => [$r['minutes'], $r['miles']])->values();

        // Offices within N miles of the nearest are as good a choice (A1's "similar").
        $best = $ranked->first()['miles'] ?? null;
        $ranked = $ranked->map(fn ($r) => $r + ['similar' => $best !== null && $similar >= $r['miles'] - $best]);

        return response()->json(['data' => ['to' => $to, 'offices' => $ranked]]);
    }

    /**
     * GET /field/geocode-zips?zips=44120,44114: each ZIP's centre, from Field's
     * cache; up to 25 not seen before are asked of Google per call, so a long
     * list fills over a few calls. Unknown ones are left out.
     */
    public function geocodeZips(Request $request): JsonResponse
    {
        $data = $request->validate(['zips' => ['required', 'string', 'max:6000']]);
        preg_match_all('/\b\d{5}\b/', $data['zips'], $m);
        $zips = array_slice(array_values(array_unique($m[0])), 0, 500);

        $budget = 25;
        $out = [];
        foreach ($zips as $zip) {
            $point = $this->jobs->pointAt("{$zip}, USA");
            if ($point === null && $budget > 0) {
                $budget--;
                $point = $this->jobs->pointAt("{$zip}, USA", ask: true);
            }
            if ($point !== null) {
                $out[$zip] = $point;
            }
        }

        return response()->json(['data' => (object) $out]);
    }

    /**
     * GET /field/coverage?from=&to= (dates): the offices and the jobs in the
     * window, with their pins from Field's cache (no Google calls), for the
     * Stats map. Leads adds its own layer.
     */
    public function coverage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $user = $request->user();
        $exact = $user->hasPermission('field.map.view');

        $jobs = Job::query()
            ->with('customer:id,address_line1,address_line2,city,state,postal_code')
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->whereBetween('scheduled_for', [$data['from'].' 00:00:00', $data['to'].' 23:59:59'])
            ->limit(5000)
            ->get(['id', 'number', 'status', 'customer_id', 'franchise_id', 'scheduled_for']);

        $hashes = $jobs->mapWithKeys(fn (Job $j) => [$j->getKey() => ($a = $j->customer ? FieldGeocode::addressOf($j->customer) : null) ? FieldGeocode::hash($a) : null]);
        $points = FieldGeocode::query()->whereIn('address_hash', $hashes->filter()->values())
            ->where('status', FieldGeocode::OK)->get(['address_hash', 'latitude', 'longitude'])->keyBy('address_hash');

        return response()->json(['data' => [
            'offices' => $this->offices($user),
            // Without field.map.view (a Leads-only role) a pin is about a
            // kilometre wide and names no job or customer: an exact pin with
            // a customer id is somebody's home and job (Roles review, 2026-10-19).
            'jobs' => $jobs->map(function (Job $j) use ($hashes, $points, $exact) {
                $p = $points[$hashes[$j->getKey()]] ?? null;
                if ($p === null) {
                    return null;
                }

                return $exact ? [
                    'job_id' => $j->getKey(), 'number' => $j->number, 'status' => $j->status,
                    'customer_id' => $j->customer_id, 'franchise_id' => $j->franchise_id,
                    'lat' => (float) $p->latitude, 'lng' => (float) $p->longitude,
                ] : [
                    'status' => $j->status, 'franchise_id' => $j->franchise_id,
                    'lat' => round((float) $p->latitude, 2), 'lng' => round((float) $p->longitude, 2),
                ];
            })->filter()->values(),
        ]]);
    }

    /**
     * The companies you can see that have an address, with their pin.
     *
     * @return list<array{company_id: string, name: string, address: string, lat: float, lng: float, has_logo: bool}>
     */
    private function offices(User $user): array
    {
        // Reach, narrowed to the top-bar view (the Web Management glance's
        // pattern): companyIds() alone is empty in the organization view.
        // Somebody placed at a franchise reaches the franchise, not its
        // company, yet works out of that company's headquarters: so the
        // companies of reachable franchises count too.
        $view = ViewScope::forUser($user);
        $ids = $user->reachableCompanies()->modelKeys();
        $ids = array_merge($ids, $user->reachableFranchises()->pluck('company_id')->filter()->all());

        return Company::query()
            ->whereIn('id', array_unique($ids))
            ->orderBy('name')
            ->get()
            ->filter(fn (Company $c) => $view->coversCompany($c->getKey()))
            ->map(function (Company $c) {
                $address = FieldGeocode::addressOf($c);
                $point = $this->jobs->pointAt($address, ask: true);

                return $point === null ? null : [
                    'company_id' => $c->getKey(),
                    'name' => $c->name,
                    'address' => $address,
                    'lat' => $point['lat'],
                    'lng' => $point['lng'],
                    'has_logo' => $c->logo_path !== null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** GET /field/map/tiles */
    public function tiles(Request $request): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->maps->tileUrl((string) $request->query('theme', 'light'))]]);
    }

    /** GET /field/map/attribution — Google's copyright line for what is on screen. */
    public function attribution(Request $request): JsonResponse
    {
        $v = $request->validate([
            'zoom' => ['required', 'integer', 'between:0,22'],
            'north' => ['required', 'numeric', 'between:-90,90'],
            'south' => ['required', 'numeric', 'between:-90,90'],
            'east' => ['required', 'numeric', 'between:-180,180'],
            'west' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(['data' => ['copyright' => $this->maps->attribution(
            (int) $v['zoom'], (float) $v['north'], (float) $v['south'], (float) $v['east'], (float) $v['west'],
        )]]);
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
