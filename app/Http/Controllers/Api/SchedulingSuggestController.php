<?php

namespace App\Http\Controllers\Api;

use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What somebody could mean by what they have typed so far.
 *
 * The Availability search takes a zip, a service area name, or a city, and
 * before this it took whatever was typed and guessed which of the three it was
 * from the shape of the string. Digits meant a zip and anything else meant an
 * area name — so a city reached nothing, and a half-typed area name reached
 * nothing either, both silently and both looking identical to "we do not cover
 * that".
 *
 * ── Why suggestions rather than a smarter guess ──────────────────
 *
 * The three namespaces genuinely overlap. A franchise can have an area called
 * "Dallas" AND a city called "Dallas" on twenty of its zips, and those are not
 * the same answer: the area is one row, the city is a set of zips that might
 * span two areas. Guessing picks one and hides the other. Offering both lets
 * the person say which they meant, and the answer carries the area id so the
 * next request is exact rather than another search.
 *
 * Everything is scoped by ViewScope::franchiseIds(), so a suggestion can never
 * name a place in a franchise the caller cannot reach — this is a search box
 * that reads three tables, which is exactly where a leak would be least
 * visible.
 */
class SchedulingSuggestController extends Controller
{
    /** How many of each kind. Enough to choose from, short enough to read. */
    private const PER_KIND = 5;

    /** GET /api/v1/scheduling/suggest */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        $data = $request->validate([
            'q' => ['required', 'string', 'max:120'],
        ]);

        $q = trim($data['q']);

        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $franchiseIds = ViewScope::forUser($request->user())->franchiseIds();

        return response()->json([
            'data' => [
                ...$this->areas($q, $franchiseIds),
                ...$this->cities($q, $franchiseIds),
                ...$this->zips($q, $franchiseIds),
            ],
        ]);
    }

    /**
     * @param  string[]  $franchiseIds
     * @return array<int, array<string, mixed>>
     */
    private function areas(string $q, array $franchiseIds): array
    {
        return ServiceArea::whereIn('franchise_id', $franchiseIds)
            ->where('name', 'like', $q.'%')
            ->orderBy('name')
            ->limit(self::PER_KIND)
            ->get()
            ->map(fn (ServiceArea $a) => [
                'type' => 'area',
                'label' => $a->name,
                'detail' => 'Service area',
                'area_id' => $a->id,
                'area_name' => $a->name,
            ])
            ->all();
    }

    /**
     * A city is a GROUP of zips, so it is offered once with a count rather
     * than once per zip — twenty rows all reading "Dallas" is not a choice.
     *
     * @param  string[]  $franchiseIds
     * @return array<int, array<string, mixed>>
     */
    private function cities(string $q, array $franchiseIds): array
    {
        $rows = ServiceAreaZip::whereIn('franchise_id', $franchiseIds)
            ->whereNotNull('city')
            ->where('city', 'like', $q.'%')
            ->with('serviceArea')
            ->get();

        return $rows
            ->groupBy('city')
            ->take(self::PER_KIND)
            ->map(function ($group, $city) {
                $areas = $group
                    ->map(fn (ServiceAreaZip $z) => $z->serviceArea?->name)
                    ->filter()
                    ->unique()
                    ->values();

                $count = $group->count();

                return [
                    'type' => 'city',
                    'label' => (string) $city,
                    // Says how many zips and, when a city spans more than one
                    // area, that it does — because then picking it cannot
                    // resolve to a single week and the person needs to know
                    // before they pick.
                    'detail' => $areas->count() === 1
                        ? "{$count} ".($count === 1 ? 'zip' : 'zips')." in {$areas->first()}"
                        : "{$count} zips across ".$areas->join(', '),
                    'area_id' => $areas->count() === 1 ? $group->first()->service_area_id : null,
                    'area_name' => $areas->count() === 1 ? $areas->first() : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  string[]  $franchiseIds
     * @return array<int, array<string, mixed>>
     */
    private function zips(string $q, array $franchiseIds): array
    {
        return ServiceAreaZip::whereIn('franchise_id', $franchiseIds)
            ->where('zip', 'like', $q.'%')
            ->with('serviceArea')
            ->orderBy('zip')
            ->limit(self::PER_KIND)
            ->get()
            ->map(fn (ServiceAreaZip $z) => [
                'type' => 'zip',
                'label' => $z->zip,
                'detail' => $z->city === null
                    ? ($z->serviceArea?->name ?? '')
                    : "{$z->city} · {$z->serviceArea?->name}",
                'area_id' => $z->service_area_id,
                'area_name' => $z->serviceArea?->name,
            ])
            ->all();
    }
}
