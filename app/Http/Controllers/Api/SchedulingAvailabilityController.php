<?php

namespace App\Http\Controllers\Api;

use App\Support\Leads\AddressCheck;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Support\Scheduling\NearbySuggestion;
use App\Support\Scheduling\Openings;
use App\Support\Scheduling\Reschedules;
use App\Support\Scheduling\RouteDrive;
use App\Support\Scheduling\Routes;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * "When can we come out?" — the first day a service area has room, and the
 * next few after it, window by window.
 *
 * Laid out the way A1 Tools' Availability tab is, on Jose's instruction of
 * 2026-09-25 ("this is how it looks on A1Tools and my dispatchers are used to
 * it"): search by zip, or pick a franchise and see every area in it.
 *
 * ── The noun changed, and `basis` says so ───────────────────────
 *
 * Until now every number here was CONFIGURED capacity, because nothing counted
 * bookings, and `basis: configured` travelled in the payload so the label could
 * not claim more. The count exists now — App\Support\Scheduling\Openings, the
 * one place "taken" is computed — so `basis` is `remaining`, exactly the switch
 * this docblock promised when it was first written.
 *
 * ── An honest unknown beats a confident wrong answer ────────────
 *
 * A zip nobody covers, and an area nobody has configured, both come back as a
 * plain absence the client turns into "check availability on Workiz" — never
 * "we don't cover that" or "fully booked". A1 Tools said both, and Jose's
 * dispatchers turned away jobs A-1 could have done (A1 d76f4a43).
 */
class SchedulingAvailabilityController extends Controller
{
    /** How many open days are listed under "After that". */
    private const AFTER = 5;

    /*
     * No constructor injection. A route keeps its controller instance for the
     * life of a test, and SettingsResolver caches per request — held here it
     * would answer a later request from an earlier one's cache.
     */

    /**
     * GET /api/v1/scheduling/availability/suggestion?zip=
     *
     * The earliest open window for a zip, and the one nearest work already
     * booked, with the jobs it weighed so the screen can draw them on a map
     * (Jose, 2026-10-13). See App\Support\Scheduling\NearbySuggestion.
     */
    public function suggestion(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);
        $data = $request->validate(['zip' => ['required', 'string', 'max:16']]);
        $zip = $this->zip5((string) $data['zip']);
        $area = $this->areaForZip($zip, ViewScope::forUser($request->user())->franchiseIds());

        if ($area === null || ! Openings::hasCapacity($area)) {
            return response()->json(['data' => null]);
        }

        $from = Openings::tomorrow((string) app(SettingsResolver::class)->get('timezone', $request->user()->organization));

        return response()->json(['data' => ['area_id' => $area->id, 'area_name' => $area->name]
            + app(NearbySuggestion::class)->for($area, $zip, $from)]);
    }

    /**
     * GET /api/v1/scheduling/availability/fit?zip=&date=&start_time=
     *
     * How a window already picked — a website booking on Leads' Online
     * Booking card — sits beside booked work, and a better day if there is
     * one. See NearbySuggestion::fit().
     */
    public function fit(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);
        $data = $request->validate([
            'zip' => ['required', 'string', 'max:16'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
        ]);
        $zip = $this->zip5((string) $data['zip']);
        $area = $this->areaForZip($zip, ViewScope::forUser($request->user())->franchiseIds());

        if ($area === null || ! Openings::hasCapacity($area)) {
            return response()->json(['data' => null]);
        }

        $from = Openings::tomorrow((string) app(SettingsResolver::class)->get('timezone', $request->user()->organization));

        return response()->json(['data' => ['area_id' => $area->id, 'area_name' => $area->name]
            + app(NearbySuggestion::class)->fit($area, $zip, $data['date'], $data['start_time'], $from)]);
    }

    /**
     * GET /api/v1/scheduling/routes?area_id=&date=
     *
     * Scheduling › Routing (Jose, 2026-10-14): the day's jobs as one route
     * per technician (capacity / 6), each with its Google Maps directions.
     * See App\Support\Scheduling\Routes.
     */
    public function routes(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d']]);
        $area = ServiceArea::query()->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->findOrFail($data['area_id']);
        $plan = app(Routes::class)->for($area, CarbonImmutable::parse($data['date']));

        $rows = collect($plan['routes'])->pluck('stops')->flatten(1)->merge($plan['unplaced'])->merge($plan['elsewhere']);
        $drive = app(RouteDrive::class);
        $customers = Customer::query()->whereIn('id', $rows->pluck('customer_id')->filter()->unique())->get()->keyBy('id');
        $job = fn (object $row) => SchedulingOverbookingController::detail($row, $customers[$row->customer_id ?? ''] ?? null)
            + ['latitude' => (float) $row->latitude, 'longitude' => (float) $row->longitude,
                // Placed at its zip's centre: Workiz had no location for it.
                'approximate' => $row->approximate ?? false];

        return response()->json(['data' => [
            'area_id' => $area->id,
            'area_name' => $area->name,
            'date' => $data['date'],
            'capacity' => $plan['capacity'],
            'technicians' => $plan['technicians'],
            // `drive` is Google's road path and road miles/minutes, drawn in
            // the app (null without a key: the screen draws straight lines).
            'routes' => array_map(fn (array $r) => ['stops' => array_map($job, $r['stops']),
                'drive' => $drive->for(array_map(fn ($s) => [(float) $s->latitude, (float) $s->longitude], $r['stops']))] + $r,
                $plan['routes']),
            // No place on the map (Workiz could not geocode them): listed so
            // nobody is forgotten, routed by the dispatcher.
            'unplaced' => array_map($job, $plan['unplaced']),
            // Over 60 miles from the day's centre: almost always booked in the
            // wrong area. Listed for the dispatcher, kept out of the routes.
            'elsewhere' => array_map($job, $plan['elsewhere']),
        ]]);
    }

    /**
     * GET /api/v1/scheduling/reschedules[?area_id=]
     *
     * Scheduling › Re-Scheduling (Jose, 2026-10-13): booked jobs alone on
     * their day, each with the open window beside other work it could move
     * to. One area, or every area in view. See App\Support\Scheduling\Reschedules.
     */
    public function reschedules(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => ['nullable', 'uuid']]);
        $inView = ViewScope::forUser($request->user())->franchiseIds();
        $areas = ServiceArea::query()->whereIn('franchise_id', $inView)->whereNull('shares_with_id')
            ->when($data['area_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')->get()
            ->filter(fn (ServiceArea $a) => Openings::hasCapacity($a));
        $from = Openings::tomorrow((string) app(SettingsResolver::class)->get('timezone', $request->user()->organization));

        $found = $areas->flatMap(fn (ServiceArea $a) => collect(app(Reschedules::class)->for($a, $from))
            ->map(fn (array $r) => $r + ['area' => $a]));
        $customers = Customer::query()->whereIn('id', $found->pluck('row.customer_id')->filter()->unique())->get()->keyBy('id');

        return response()->json(['data' => $found->map(fn (array $r) => [
            'area_id' => $r['area']->id,
            'area_name' => $r['area']->name,
            'job' => SchedulingOverbookingController::detail($r['row'], $customers[$r['row']->customer_id ?? ''] ?? null)
                // What POST scheduling/board/jobs/{job}/reschedule takes.
                + ['workiz_job_id' => $r['row']->workiz_job_id ?? null, 'date' => $r['current']['date'], 'latitude' => (float) $r['row']->latitude, 'longitude' => (float) $r['row']->longitude],
            'current_nearest_miles' => $r['current']['nearest_miles'],
            // The day it leaves, with its open spots before and after the move
            // (suggested carries the same for the day it joins).
            'current' => array_intersect_key($r['current'], array_flip(['date', 'day', 'window_index', 'spots'])),
            'suggested' => $r['suggested'],
        ])->values()->all()]);
    }

    /** GET /api/v1/scheduling/availability */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        $data = $request->validate([
            'zip' => ['nullable', 'string', 'max:16'],
            'area_id' => ['nullable', 'uuid'],
            'area_name' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:120'],
            // "Search by Area": a franchise, answered with every area in it.
            'franchise_id' => ['nullable', 'uuid'],
            // "All areas" (Jose, 2026-09-28): every area in view.
            'all' => ['nullable', 'boolean'],
            // A street address a customer wrote ("86 Jerusalem hill
            // Trumbull"): its zip is looked up and searched (Mia, 2026-10-02).
            'address' => ['nullable', 'string', 'max:300'],
        ]);

        $resolved = null;

        if (($data['address'] ?? null) !== null && trim($data['address']) !== '') {
            $resolved = AddressCheck::check(trim($data['address']));

            if ($resolved === null || $resolved['zip'] === null) {
                throw ValidationException::withMessages([
                    'address' => 'Could not find a zip code for that address. Add the town or '
                        .'state, or search by the zip code.',
                ]);
            }

            $data['zip'] = $resolved['zip'];
        }

        $searched = $data['zip']
            ?? $data['city']
            ?? $data['area_name']
            ?? $data['area_id']
            ?? $data['franchise_id']
            ?? (($data['all'] ?? false) ? 'all' : null);

        if ($searched === null) {
            throw ValidationException::withMessages([
                'zip' => 'Search by a zip code, or pick a franchise.',
            ]);
        }

        $franchiseIds = ViewScope::forUser($request->user())->franchiseIds();
        $franchiseNames = Franchise::whereIn('id', $franchiseIds)->pluck('name', 'id');

        [$areas, $by] = match (true) {
            (bool) ($data['all'] ?? false) => [
                // Franchise by franchise, the way the chips read.
                ServiceArea::whereIn('franchise_id', $franchiseIds)->get()
                    ->sortBy(fn (ServiceArea $a) => [$franchiseNames[$a->franchise_id] ?? '', $a->name])
                    ->values(),
                'all',
            ],
            ($data['franchise_id'] ?? null) !== null => [
                in_array($data['franchise_id'], $franchiseIds, true)
                    ? ServiceArea::where('franchise_id', $data['franchise_id'])->orderBy('name')->get()
                    : collect(),
                'franchise',
            ],
            ($data['area_id'] ?? null) !== null => [
                collect([ServiceArea::whereIn('franchise_id', $franchiseIds)->find($data['area_id'])])->filter(),
                'area',
            ],
            ($data['zip'] ?? null) !== null => [
                collect([$this->areaForZip($this->zip5((string) $data['zip']), $franchiseIds)])->filter(),
                'zip',
            ],
            ($data['city'] ?? null) !== null => [
                collect([$this->areaForCity(trim((string) $data['city']), $franchiseIds)])->filter(),
                'city',
            ],
            default => [
                ServiceArea::whereIn('franchise_id', $franchiseIds)
                    ->where('name', trim((string) $data['area_name']))->get(),
                'name',
            ],
        };

        $organization = $request->user()->organization;
        $from = Openings::tomorrow((string) app(SettingsResolver::class)->get('timezone', $organization));
        $franchises = Franchise::whereIn('id', $areas->pluck('franchise_id'))->pluck('name', 'id');

        return response()->json([
            'data' => [
                'searched' => $searched,
                'by' => $by,
                // The address as Google placed it, so the reader can see which
                // house the zip came from before telling a customer anything.
                'resolved' => $resolved,
                'basis' => 'remaining',
                'from' => $from->toDateString(),
                'areas' => $areas->map(function (ServiceArea $area) use ($from, $franchises) {
                    $this->authorize('view', $area);

                    $configured = Openings::hasCapacity($area);
                    $next = $configured ? app(Openings::class)->next($area, $from, self::AFTER) : null;

                    return [
                        'area_id' => $area->id,
                        'area_name' => $area->name,
                        'franchise_name' => $franchises[$area->franchise_id] ?? null,
                        // FALSE is "nobody has set this area up" — not closed,
                        // not full. The client says "check Workiz".
                        'configured' => $configured,
                        'first' => $next['first'] ?? null,
                        'after' => $next['after'] ?? [],
                        'closed' => $next['closed'] ?? [],
                        'none_because' => $next['none_because'] ?? null,
                    ];
                })->values()->all(),
            ],
        ]);
    }

    /**
     * GET /api/v1/scheduling/availability/franchises
     *
     * The chips for "Search by Area": franchises in view that have at least one
     * service area. Drawn from here rather than from the session's franchise
     * list because A-1 holds empty duplicate franchise rows (Connecticut ×8 on
     * 2026-09-25), and a chip per row would offer eight Connecticuts, seven of
     * which answer with nothing.
     */
    public function franchises(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        $ids = ServiceArea::whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->distinct()
            ->pluck('franchise_id');

        return response()->json([
            'data' => Franchise::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Franchise $f) => ['id' => $f->id, 'name' => $f->name])->all(),
        ]);
    }

    /**
     * GET /api/v1/scheduling/availability/calendar?month=YYYY-MM&franchise_id=[&area_id=]
     *
     * A month at a glance, a franchise or one of its areas (Jose, 2026-09-25,
     * with A1 Tools' calendar: "a sort of Calendar View for each franchise or
     * service area"). Per day: what it can take, what is booked, what is left.
     * Every number is Openings', so the calendar and the search cannot
     * disagree, and the franchise's daily limit applies the same way.
     *
     * For the whole franchise, a day with a limit counts the franchise's own
     * bookings (any area, or none) and never shows more than the limit; one
     * area shows its own bookings, with what is left capped by the franchise.
     * A day no area works is `closed` — distinct from full — and `over` is
     * bookings beyond what the day can take, closed days included.
     */
    public function calendar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            // None is "All areas" (Jose, 2026-09-28): every franchise in view,
            // added up.
            'franchise_id' => ['nullable', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
        ]);

        $inView = ViewScope::forUser($request->user())->franchiseIds();
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay();
        $count = $from->daysInMonth;

        if (($data['franchise_id'] ?? null) === null) {
            $byFranchise = ServiceArea::query()->whereIn('franchise_id', $inView)->get()->groupBy('franchise_id');

            // Each franchise's month on its own — its daily limit caps its own
            // days — then summed. A day is closed only when every one is.
            $months = $byFranchise->map(fn ($areas) => $this->month($areas, null, $from, $count))->values();
            $days = [];
            for ($i = 0; $i < $count; $i++) {
                $all = $months->map(fn ($m) => $m[$i]);
                $days[] = [
                    'date' => $all->first()['date'] ?? $from->addDays($i)->toDateString(),
                    'closed' => $all->every(fn ($d) => $d['closed']),
                    'capacity' => $all->sum('capacity'),
                    'booked' => $all->sum('booked'),
                    'remaining' => $all->sum('remaining'),
                    'over' => $all->sum('over'),
                    'limit' => null,
                ];
            }

            return response()->json(['data' => [
                'month' => $data['month'],
                'franchise' => null,
                'areas' => [],
                'area_id' => null,
                'configured' => $byFranchise->flatten()->contains(fn (ServiceArea $a) => Openings::hasCapacity($a)),
                'today' => $this->today($request),
                'days' => $days,
            ]]);
        }

        $franchise = Franchise::query()->whereIn('id', $inView)->findOrFail($data['franchise_id']);

        $areas = ServiceArea::query()->where('franchise_id', $franchise->id)->orderBy('name')->get();
        $area = ($data['area_id'] ?? null) === null ? null : $areas->firstWhere('id', $data['area_id']);
        abort_if(($data['area_id'] ?? null) !== null && $area === null, 404);

        return response()->json([
            'data' => [
                'month' => $data['month'],
                'franchise' => ['id' => $franchise->id, 'name' => $franchise->name],
                'areas' => $areas->map(fn (ServiceArea $a) => ['id' => $a->id, 'name' => $a->name])->all(),
                'area_id' => $area?->id,
                'configured' => ($area === null ? $areas : collect([$area]))
                    ->contains(fn (ServiceArea $a) => Openings::hasCapacity($a)),
                'today' => $this->today($request),
                'days' => $this->month($areas, $area, $from, $count),
            ],
        ]);
    }

    /** Today in the organization's own clock, for the calendar's highlight. */
    private function today(Request $request): string
    {
        return Openings::tomorrow((string) app(SettingsResolver::class)
            ->get('timezone', $request->user()->organization))->subDay()->toDateString();
    }

    /**
     * One franchise's month — all of `$areas`, or just `$area` of them. See
     * calendar() for how the franchise's daily limit applies.
     *
     * @param  Collection<int, ServiceArea>  $areas  every area of ONE franchise
     * @return array<int, array<string, mixed>>
     */
    private function month(Collection $areas, ?ServiceArea $area, CarbonImmutable $from, int $count): array
    {
        // A shared pool counts once, under its lead: a member answers the
        // lead's days, so adding both would double the pool.
        $shown = $area === null ? $areas->whereNull('shares_with_id') : collect([$area]);
        $openings = app(Openings::class);
        $perArea = $shown->map(fn (ServiceArea $a) => $openings->days($a, $from, $count))->all();

        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $capacity = $booked = $remaining = 0;
            $open = false;
            $limit = $franchiseBooked = null;

            foreach ($perArea as $areaDays) {
                $day = $areaDays[$i];
                $open = $open || $day['windows'] !== [];
                $own = array_sum(array_column($day['windows'], 'capacity'));
                $capacity += $day['area_limit'] === null ? $own : min($own, $day['area_limit']);
                $booked += array_sum(array_column($day['windows'], 'taken')) + $day['no_time'];
                $remaining += array_sum(array_column($day['windows'], 'remaining'));
                $limit = $day['franchise_limit'];
                $franchiseBooked = $day['franchise_booked'];
            }

            if ($limit !== null) {
                $remaining = min($remaining, max(0, $limit - $franchiseBooked));

                if ($area === null) {
                    $capacity = min($capacity, $limit);
                    $booked = $franchiseBooked;
                }
            }

            $days[] = [
                'date' => $from->addDays($i)->toDateString(),
                'closed' => ! $open,
                'capacity' => $capacity,
                'booked' => $booked,
                'remaining' => $remaining,
                'over' => max(0, $booked - $capacity),
                'limit' => $limit,
            ];
        }

        return $days;
    }

    /**
     * Five digits, the way the zip map stores them — a four-digit New England
     * zip that lost its leading zero is padded back, or it matches nothing.
     *
     * Anything that is not a zip says so (Jovanni, 2026-10-01: "pls improve
     * the zip code validation"): 5 digits, zip+4, or that 4-digit zip. Before,
     * "123456" was cut to 12345 and searched as if typed, and "123" came back
     * as a zip no area covers.
     */
    private function zip5(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        if (! in_array(strlen($digits), [4, 5, 9], true)) {
            throw ValidationException::withMessages(['zip' => self::NOT_A_ZIP]);
        }

        return substr(str_pad($digits, 5, '0', STR_PAD_LEFT), 0, 5);
    }

    /** What a search says when what was typed is not a zip code. */
    public const NOT_A_ZIP = 'That is not a zip code. A zip code is 5 digits, like 06810, or zip+4, like 06810-1234.';

    /**
     * The area covering a zip, within the franchises in view.
     *
     * One row at most by construction — the unique index on
     * (organization_id, zip) is what makes this lookup unambiguous, and it is
     * the reason overlapping zips were refused rather than made configurable.
     *
     * @param  string[]  $franchiseIds
     */
    private function areaForZip(string $zip, array $franchiseIds): ?ServiceArea
    {
        return ServiceArea::forZip($zip, $franchiseIds);
    }

    /**
     * The area covering a city.
     *
     * A city is a set of zips and those zips can sit in two areas, so unlike
     * the zip lookup this is NOT unambiguous by construction. It takes the
     * first by area name, deterministically, and the suggest endpoint is what
     * warns that a city is split before anybody picks it — a silent "first
     * match" would otherwise show one area's week under a city name that
     * covers two.
     *
     * @param  string[]  $franchiseIds
     */
    private function areaForCity(string $city, array $franchiseIds): ?ServiceArea
    {
        $ids = ServiceAreaZip::whereIn('franchise_id', $franchiseIds)
            ->where('city', $city)
            ->pluck('service_area_id')
            ->unique();

        if ($ids->isEmpty()) {
            return null;
        }

        return ServiceArea::whereIn('id', $ids)->orderBy('name')->first();
    }
}
