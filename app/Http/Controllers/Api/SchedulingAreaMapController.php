<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaPolygon;
use App\Models\ServiceAreaZip;
use App\Models\ServiceAreaZipRelease;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Drawn service areas, Workiz-style (Jose, 2026-10-21): draw one or more
 * shapes over an area and every ZIP whose Census centre falls inside is
 * assigned on save.
 *
 * - Preview first: what is added, which drawn ZIPs fall out, which ZIPs
 *   another area owns (a ZIP has one area per organization).
 * - A conflict moves only when the save names it in `take` and the caller can
 *   edit the area that owns it. Otherwise it stays where it is.
 * - Redrawing removes only ZIPs a drawing added (`source = 'drawn'`). Typed and
 *   job-derived ones outside the shapes are listed as `outside` and kept; a
 *   person removes them by hand.
 */
final class SchedulingAreaMapController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /scheduling/areas/{area}/map: its shapes, and its ZIPs as dots */
    public function show(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('view', $row);

        $centres = DB::table('zip_centroids')
            ->whereIn('zip', ServiceAreaZip::where('service_area_id', $row->id)->pluck('zip')->map(fn ($z) => substr($z, 0, 5)))
            ->get()->keyBy('zip');

        return response()->json(['data' => [
            'area_id' => $row->id,
            'polygons' => $this->polygons([$row->id])[$row->id] ?? [],
            'zips' => ServiceAreaZip::where('service_area_id', $row->id)->orderBy('zip')->get()
                ->map(function (ServiceAreaZip $z) use ($centres) {
                    $c = $centres[substr($z->zip, 0, 5)] ?? null;

                    return [
                        'zip' => $z->zip, 'city' => $z->city, 'source' => $z->source,
                        'latitude' => $c ? (float) $c->latitude : null,
                        'longitude' => $c ? (float) $c->longitude : null,
                    ];
                })->all(),
        ]]);
    }

    /**
     * GET /scheduling/areas-map[?franchise_id=]: every reachable area's shapes,
     * for one branch, or for the whole company without franchise_id.
     */
    public function overview(Request $request): JsonResponse
    {
        $franchises = ViewScope::forUser($request->user())->franchiseIds();
        if ($request->filled('franchise_id')) {
            abort_unless(in_array($request->query('franchise_id'), $franchises, true), 404);
            $franchises = [$request->query('franchise_id')];
        }

        $areas = ServiceArea::query()->whereIn('franchise_id', $franchises)->withCount('zips')->orderBy('name')->get();
        $names = Franchise::whereIn('id', $franchises)->pluck('name', 'id');
        $shapes = $this->polygons($areas->pluck('id')->all());

        return response()->json(['data' => $areas->map(fn (ServiceArea $a) => [
            'area_id' => $a->id,
            'name' => $a->name,
            'color' => $a->color,
            'franchise_id' => $a->franchise_id,
            'franchise_name' => $names[$a->franchise_id] ?? null,
            'zip_count' => $a->zips_count,
            'polygons' => $shapes[$a->id] ?? [],
        ])->values()->all()]);
    }

    /**
     * GET /scheduling/areas/{area}/outline: a drawn shape from the area's own
     * ZIP centres (Jose, 2026-10-27, "auto draw"), their convex hull pushed out
     * about a mile so the edge ZIPs sit inside. Not saved: the editor loads it
     * to adjust, and saving goes through preview and save as any drawing does.
     */
    public function outline(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('view', $row);
        $points = DB::table('zip_centroids')
            ->whereIn('zip', ServiceAreaZip::where('service_area_id', $row->id)->pluck('zip')->map(fn ($z) => substr($z, 0, 5)))
            ->get(['latitude', 'longitude'])->map(fn ($c) => [(float) $c->latitude, (float) $c->longitude])->unique()->values()->all();
        abort_if(count($points) < 3, 422, 'This area needs at least 3 ZIP codes on the map to draw an outline; it has '.count($points).'.');

        return response()->json(['data' => ['polygon' => self::buffered(self::hull($points), 1.0)]]);
    }

    /**
     * Andrew's monotone chain on [lat, lng].
     *
     * @param  list<array{0: float, 1: float}>  $p
     * @return list<array{0: float, 1: float}>
     */
    public static function hull(array $p): array
    {
        usort($p, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        $cross = fn ($o, $a, $b) => ($a[1] - $o[1]) * ($b[0] - $o[0]) - ($a[0] - $o[0]) * ($b[1] - $o[1]);
        $half = function (array $pts) use ($cross) {
            $h = [];
            foreach ($pts as $pt) {
                while (count($h) >= 2 && $cross($h[count($h) - 2], $h[count($h) - 1], $pt) <= 0) {
                    array_pop($h);
                }
                $h[] = $pt;
            }
            array_pop($h);

            return $h;
        };

        return array_merge($half($p), $half(array_reverse($p)));
    }

    /**
     * Each vertex pushed away from the centre by about $miles.
     * ponytail: radial push, not a true offset; fine for an outline a person adjusts.
     */
    private static function buffered(array $hull, float $miles): array
    {
        $cLat = array_sum(array_column($hull, 0)) / count($hull);
        $cLng = array_sum(array_column($hull, 1)) / count($hull);
        $milesPerLng = 69.0 * cos(deg2rad($cLat));

        return array_map(function ($v) use ($cLat, $cLng, $miles, $milesPerLng) {
            $dy = ($v[0] - $cLat) * 69.0;
            $dx = ($v[1] - $cLng) * $milesPerLng;
            $len = sqrt($dx * $dx + $dy * $dy) ?: 1.0;

            return [round($v[0] + ($dy / $len) * $miles / 69.0, 6), round($v[1] + ($dx / $len) * $miles / $milesPerLng, 6)];
        }, $hull);
    }

    /** POST /scheduling/areas/{area}/polygons/preview {polygons} */
    public function preview(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);

        return response()->json(['data' => $this->plan($row, $this->shapes($request), $request)]);
    }

    /** PUT /scheduling/areas/{area}/polygons {polygons, take?: [zip]} */
    public function save(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);
        $shapes = $this->shapes($request);
        $take = collect($request->validate(['take' => ['sometimes', 'array'], 'take.*' => ['string', 'max:16']])['take'] ?? []);

        $result = DB::transaction(function () use ($request, $row, $shapes, $take) {
            $plan = $this->plan($row, $shapes, $request);

            // Moves only what was asked for, from areas this person can edit.
            $moved = [];
            foreach ($plan['conflicts'] as $c) {
                if (! $take->contains($c['zip'])) {
                    continue;
                }
                $owner = $this->reachable($request)->find($c['area_id']);
                abort_if($owner === null || ! $request->user()->can('update', $owner), 403,
                    "{$c['zip']} is in {$c['area_name']}, which you cannot change.");
                ServiceAreaZip::where('service_area_id', $owner->id)->where('zip', $c['zip'])->delete();
                $moved[] = $c['zip'];
            }

            ServiceAreaPolygon::where('service_area_id', $row->id)->delete();
            foreach ($shapes as $points) {
                ServiceAreaPolygon::create(['franchise_id' => $row->franchise_id, 'service_area_id' => $row->id, 'points' => $points]);
            }

            ServiceAreaZip::where('service_area_id', $row->id)->whereIn('zip', $plan['remove'])->delete();
            ServiceAreaZipRelease::release($plan['remove']);
            ServiceAreaZipRelease::claim(array_merge($plan['add'], $moved));
            foreach (array_merge($plan['add'], $moved) as $zip) {
                ServiceAreaZip::create(['franchise_id' => $row->franchise_id, 'service_area_id' => $row->id, 'zip' => $zip, 'source' => 'drawn']);
            }

            return [
                'added' => $plan['add'],
                'moved' => $moved,
                'removed' => $plan['remove'],
                'left_with_owner' => collect($plan['conflicts'])->pluck('zip')->diff($moved)->values()->all(),
            ];
        });

        $this->audit->log('scheduling.area_drawn', $row, metadata: [
            'polygons' => count($shapes), 'added' => count($result['added']),
            'moved' => $result['moved'], 'removed' => count($result['removed']),
        ]);

        return response()->json(['data' => $result]);
    }

    /**
     * add: inside and in no area. conflicts: inside but another area's.
     * remove: drawn here before and now outside. outside: typed or derived and
     * now outside (kept).
     */
    private function plan(ServiceArea $row, array $shapes, Request $request): array
    {
        $inside = $this->zipsInside($shapes);
        $owners = ServiceAreaZip::query()->whereIn('zip', $inside)->with('serviceArea:id,name')->get()->keyBy('zip');
        $outsideMine = ServiceAreaZip::where('service_area_id', $row->id)->get(['zip', 'source'])
            ->reject(fn ($z) => in_array(substr($z->zip, 0, 5), $inside, true));

        return [
            'add' => array_values(array_diff($inside, $owners->keys()->map(fn ($z) => (string) $z)->all())),
            'conflicts' => $owners->filter(fn ($z) => $z->service_area_id !== $row->id)
                ->map(fn ($z) => ['zip' => (string) $z->zip, 'area_id' => $z->service_area_id, 'area_name' => $z->serviceArea?->name,
                    // Whether save's `take` may move it: the owner is reachable and editable.
                    'may_take' => ($owner = $this->reachable($request)->find($z->service_area_id)) !== null && $request->user()->can('update', $owner)])
                ->values()->all(),
            'remove' => $outsideMine->where('source', 'drawn')->pluck('zip')->values()->all(),
            'outside' => $outsideMine->filter(fn ($z) => $z->source !== 'drawn')->pluck('zip')->values()->all(),
        ];
    }

    /** @return list<string> ZIPs whose centre is inside any shape */
    private function zipsInside(array $shapes): array
    {
        $found = [];
        foreach ($shapes as $points) {
            $lats = array_column($points, 0);
            $lngs = array_column($points, 1);
            $candidates = DB::table('zip_centroids')
                ->whereBetween('latitude', [min($lats), max($lats)])
                ->whereBetween('longitude', [min($lngs), max($lngs)])
                ->get(['zip', 'latitude', 'longitude']);
            foreach ($candidates as $c) {
                if (self::contains($points, (float) $c->latitude, (float) $c->longitude)) {
                    $found[(string) $c->zip] = true;
                }
            }
        }
        $zips = array_map('strval', array_keys($found));
        sort($zips);

        return $zips;
    }

    /** Ray casting on [[lat, lng], ...]. ponytail: flat lat/lng, fine at county scale. */
    public static function contains(array $points, float $lat, float $lng): bool
    {
        $in = false;
        $n = count($points);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = $points[$i];
            [$yj, $xj] = $points[$j];
            if (($yi > $lat) !== ($yj > $lat) && $lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $in = ! $in;
            }
        }

        return $in;
    }

    /** @return list<list<array{0: float, 1: float}>> */
    private function shapes(Request $request): array
    {
        $data = $request->validate([
            'polygons' => ['present', 'array', 'max:20'],
            'polygons.*' => ['array', 'min:3', 'max:500'],
            'polygons.*.*' => ['array', 'size:2'],
            'polygons.*.*.0' => ['numeric', 'between:-90,90'],
            'polygons.*.*.1' => ['numeric', 'between:-180,180'],
        ]);

        return array_map(
            fn ($p) => array_map(fn ($pt) => [(float) $pt[0], (float) $pt[1]], array_values($p)),
            array_values($data['polygons']),
        );
    }

    /** @return array<string, list<list<array{0: float, 1: float}>>> */
    private function polygons(array $areaIds): array
    {
        return ServiceAreaPolygon::whereIn('service_area_id', $areaIds)->orderBy('created_at')->get()
            ->groupBy('service_area_id')->map(fn ($rows) => $rows->pluck('points')->all())->all();
    }

    private function reachable(Request $request): Builder
    {
        return ServiceArea::query()->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds());
    }
}
