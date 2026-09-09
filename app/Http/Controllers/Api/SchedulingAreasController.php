<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Support\Scheduling\SlotShape;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service areas, their zip codes, and their capacity grid
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md).
 *
 * Draws the Settings tab: design docs/previews/scheduling-layouts.html #7.
 */
class SchedulingAreasController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/scheduling/areas
     *
     * The rail and the Overview pane in one answer, because they are one
     * screen and a second round trip would let them disagree about which
     * areas exist.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        $areas = $this->reachable($request)
            ->with(['slots', 'zips'])
            ->orderBy('name')
            ->get();

        $shapes = $areas->mapWithKeys(
            fn (ServiceArea $a) => [$a->id => SlotShape::of($a->slots)]
        );

        $pattern = SlotShape::commonest($shapes->values()->all());

        return response()->json([
            'data' => $areas->map(fn (ServiceArea $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'franchise_id' => $a->franchise_id,
                'zip_count' => $a->zips->count(),
                // Derived, never stored. An area "differs" because its rows
                // differ, so the two can never disagree — see the spec on why
                // there is no pattern table.
                'follows_pattern' => $pattern !== null
                    && $shapes[$a->id] === $pattern,
            ])->all(),
            'pattern' => $pattern === null ? null : [
                'slots' => SlotShape::describe($pattern),
                'followed_by' => $shapes->filter(fn ($s) => $s === $pattern)->count(),
                'of' => $areas->count(),
            ],
        ]);
    }

    /** POST /api/v1/scheduling/areas */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'franchise_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:191'],
        ]);

        $franchise = Franchise::where('organization_id', $this->tenant->idOrFail())
            ->findOrFail($data['franchise_id']);

        $this->authorize('create', [ServiceArea::class, $franchise]);

        $taken = ServiceArea::where('franchise_id', $franchise->id)
            ->where('name', $data['name'])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "This franchise already has a service area called {$data['name']}.",
            ]);
        }

        $area = ServiceArea::create([
            'franchise_id' => $franchise->id,
            'name' => $data['name'],
        ]);

        return response()->json(['data' => $this->detail($area->fresh(['slots', 'zips']))], 201);
    }

    /** GET /api/v1/scheduling/areas/{area} */
    public function show(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->with(['slots', 'zips'])->findOrFail($area);

        $this->authorize('view', $row);

        return response()->json(['data' => $this->detail($row)]);
    }

    /** PATCH /api/v1/scheduling/areas/{area} */
    public function update(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);

        $this->authorize('update', $row);

        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);

        $taken = ServiceArea::where('franchise_id', $row->franchise_id)
            ->where('name', $data['name'])
            ->whereKeyNot($row->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "This franchise already has a service area called {$data['name']}.",
            ]);
        }

        $row->update(['name' => $data['name']]);

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /** DELETE /api/v1/scheduling/areas/{area} */
    public function destroy(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);

        $this->authorize('delete', $row);

        $row->delete();

        return response()->json(null, 204);
    }

    /**
     * PUT /api/v1/scheduling/areas/{area}/slots
     *
     * Replaces the whole grid. A weekday with no slots in the payload is a
     * CLOSED day — the rows are deleted rather than zeroed, which is what
     * keeps "we do not work Sundays" distinguishable from "we work Sundays
     * and are full". A zero here is refused for the same reason: it is the
     * one write that would collapse the two.
     */
    public function slots(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);

        $this->authorize('update', $row);

        $data = $request->validate([
            'slots' => ['present', 'array'],
            'slots.*.weekday' => ['required', 'integer', 'between:0,6'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
            'slots.*.max_jobs' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        $this->assertSlotsAreSane($data['slots']);

        DB::transaction(function () use ($row, $data) {
            ServiceAreaSlot::where('service_area_id', $row->id)->delete();

            foreach ($data['slots'] as $slot) {
                ServiceAreaSlot::create([
                    'service_area_id' => $row->id,
                    'weekday' => $slot['weekday'],
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'max_jobs' => $slot['max_jobs'],
                ]);
            }
        });

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /**
     * PUT /api/v1/scheduling/areas/{area}/zips
     *
     * Replaces the set. A zip another area in this franchise already holds is
     * refused BY NAME rather than silently moved — the unique index would
     * refuse it anyway, but as a 500 that tells nobody which area to go and
     * look at.
     */
    public function zips(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);

        $this->authorize('update', $row);

        $data = $request->validate([
            'zips' => ['present', 'array'],
            // Two accepted shapes on purpose. An entry is either a bare zip
            // string or `{zip, city}` — the desktop build already shipped
            // sending strings, and a client older than this deploy must not
            // start failing the moment the server learns about cities.
            'zips.*' => ['required'],
            'zips.*.zip' => ['sometimes', 'string', 'max:16'],
            'zips.*.city' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $wanted = $this->readZips($data['zips']);

        $clash = ServiceAreaZip::where('franchise_id', $row->franchise_id)
            ->whereIn('zip', $wanted->keys())
            ->where('service_area_id', '!=', $row->id)
            ->with('serviceArea')
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'zips' => "{$clash->zip} is already in {$clash->serviceArea?->name}. "
                    .'A zip belongs to one service area, so that a search by zip has one answer.',
            ]);
        }

        DB::transaction(function () use ($row, $wanted) {
            ServiceAreaZip::where('service_area_id', $row->id)->delete();

            foreach ($wanted as $zip => $city) {
                ServiceAreaZip::create([
                    'franchise_id' => $row->franchise_id,
                    'service_area_id' => $row->id,
                    'zip' => (string) $zip,
                    'city' => $city,
                ]);
            }
        });

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /**
     * The posted zip list as `zip => city|null`, keyed so a duplicate zip in
     * one payload collapses rather than hitting the unique index.
     *
     * Accepts a bare string or `{zip, city}` per entry — see the validation
     * above for why both.
     *
     * @param  array<int, mixed>  $entries
     * @return \Illuminate\Support\Collection<string, string|null>
     */
    private function readZips(array $entries): \Illuminate\Support\Collection
    {
        $out = collect();

        foreach ($entries as $entry) {
            $zip = trim((string) (is_array($entry) ? ($entry['zip'] ?? '') : $entry));

            if ($zip === '') {
                continue;
            }

            $city = is_array($entry) ? trim((string) ($entry['city'] ?? '')) : '';

            // Last one wins, so a re-send that adds a city to a zip already
            // stored without one updates it rather than being ignored.
            $out[$zip] = $city === '' ? null : $city;
        }

        return $out;
    }

    /**
     * Two slots overlapping on the same day is a configuration nobody meant,
     * and it would make a booking ambiguous about which slot it filled.
     * Checked here rather than by a constraint because "overlap" is not
     * something a unique index can express.
     *
     * @param  array<int, array{weekday:int, start_time:string, end_time:string}>  $slots
     */
    private function assertSlotsAreSane(array $slots): void
    {
        foreach ($slots as $i => $slot) {
            if ($slot['end_time'] <= $slot['start_time']) {
                throw ValidationException::withMessages([
                    "slots.{$i}.end_time" => 'A slot has to end after it starts.',
                ]);
            }
        }

        $byDay = [];

        foreach ($slots as $i => $slot) {
            foreach ($byDay[$slot['weekday']] ?? [] as $seen) {
                if ($slot['start_time'] < $seen['end_time'] && $seen['start_time'] < $slot['end_time']) {
                    throw ValidationException::withMessages([
                        "slots.{$i}.start_time" => 'Two slots on the same day overlap. '
                            .'A job booked into the overlap would belong to both.',
                    ]);
                }
            }

            $byDay[$slot['weekday']][] = $slot;
        }
    }

    /**
     * Areas this user can reach, filtered by the franchises in view.
     *
     * `franchiseIds()` and not `companyIds()`: it intersects reach in every
     * arm, and Phone shipped a cross-company leak on exactly that difference.
     */
    private function reachable(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $scope = ViewScope::forUser($request->user());

        return ServiceArea::query()->whereIn('franchise_id', $scope->franchiseIds());
    }

    /** @return array<string, mixed> */
    private function detail(ServiceArea $area): array
    {
        return [
            'id' => $area->id,
            'name' => $area->name,
            'franchise_id' => $area->franchise_id,
            'zips' => $area->zips
                ->sortBy('zip')
                ->map(fn (ServiceAreaZip $z) => ['zip' => $z->zip, 'city' => $z->city])
                ->values()
                ->all(),
            'slots' => $area->slots
                ->sortBy([['weekday', 'asc'], ['start_time', 'asc']])
                ->map(fn (ServiceAreaSlot $s) => [
                    'id' => $s->id,
                    'weekday' => $s->weekday,
                    'start_time' => substr((string) $s->start_time, 0, 5),
                    'end_time' => substr((string) $s->end_time, 0, 5),
                    'max_jobs' => $s->max_jobs,
                ])->values()->all(),
        ];
    }
}
