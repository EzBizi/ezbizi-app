<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Support\Scheduling\Openings;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        ]);

        $searched = $data['zip']
            ?? $data['city']
            ?? $data['area_name']
            ?? $data['area_id']
            ?? $data['franchise_id']
            ?? null;

        if ($searched === null) {
            throw ValidationException::withMessages([
                'zip' => 'Search by a zip code, or pick a franchise.',
            ]);
        }

        $franchiseIds = ViewScope::forUser($request->user())->franchiseIds();

        [$areas, $by] = match (true) {
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
     * Five digits, the way the zip map stores them — a four-digit New England
     * zip that lost its leading zero is padded back, or it matches nothing.
     */
    private function zip5(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        return strlen($digits) < 4 ? $digits : substr(str_pad($digits, 5, '0', STR_PAD_LEFT), 0, 5);
    }

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
        $held = ServiceAreaZip::whereIn('franchise_id', $franchiseIds)
            ->where('zip', $zip)
            ->first();

        return $held?->serviceArea;
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
