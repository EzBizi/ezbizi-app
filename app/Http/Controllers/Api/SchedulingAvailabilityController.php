<?php

namespace App\Http\Controllers\Api;

use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Support\Scoping\ViewScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "What can we take in Dallas on Tuesday morning?"
 * (design docs/previews/scheduling-layouts.html #4).
 *
 * ── The noun, which is the whole reason this reads honestly ──────
 *
 * Every number here is CONFIGURED capacity, not REMAINING capacity, and the
 * response says which in `basis` rather than leaving the client to name it.
 *
 * Nothing yet burns a slot: `Job` has `scheduled_for` and no link to a service
 * area, so there is no booked count to subtract. A cell labelled "remaining"
 * would therefore read 2 of 2 forever, however many jobs were booked — which
 * is not an empty screen but a confident false statement, and worse than an
 * empty one because it looks populated. `ezbizi-ea` made that argument and Jose
 * ruled on it: name the number for what it is.
 *
 * `basis` travels in the payload so the WORD changes with the MEANING. The day
 * Scheduled Jobs exists this returns `remaining`, the client's label follows,
 * and nobody who learned the first meaning is quietly shown the second.
 */
class SchedulingAvailabilityController extends Controller
{
    /** Nothing counts bookings yet — see the class docblock. */
    private const BASIS_CONFIGURED = 'configured';

    /** GET /api/v1/scheduling/availability */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        $data = $request->validate([
            'zip' => ['nullable', 'string', 'max:16'],
            'area_id' => ['nullable', 'uuid'],
            // The screen has one search box and a person types a NAME into it,
            // not a uuid. `area_id` stays for the rail, which already knows the
            // id of the thing it just drew.
            'area_name' => ['nullable', 'string', 'max:191'],
            'week' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $searched = $data['zip'] ?? $data['area_name'] ?? $data['area_id'] ?? null;

        if ($searched === null) {
            throw ValidationException::withMessages([
                'zip' => 'Search by a zip code or a service area name.',
            ]);
        }

        $scope = ViewScope::forUser($request->user());
        $franchiseIds = $scope->franchiseIds();

        $area = match (true) {
            ($data['area_id'] ?? null) !== null => ServiceArea::whereIn('franchise_id', $franchiseIds)
                ->find($data['area_id']),
            ($data['zip'] ?? null) !== null => $this->areaForZip(trim((string) $data['zip']), $franchiseIds),
            default => ServiceArea::whereIn('franchise_id', $franchiseIds)
                ->where('name', trim((string) $data['area_name']))
                ->first(),
        };

        if ($area === null) {
            // A zip nobody covers is a real answer and not an error: the
            // caller is on the phone to somebody outside the service area, and
            // "we don't cover that" is what they need to say.
            return response()->json([
                'data' => [
                    'matched' => null,
                    'searched' => $searched,
                    'basis' => self::BASIS_CONFIGURED,
                    'days' => [],
                ],
            ]);
        }

        $this->authorize('view', $area);

        $monday = $this->weekStart($data['week'] ?? null);

        $slots = ServiceAreaSlot::where('service_area_id', $area->id)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->groupBy('weekday');

        return response()->json([
            'data' => [
                'matched' => [
                    'area_id' => $area->id,
                    'area_name' => $area->name,
                    // How it was found, so the screen can say "75201 is in
                    // Dallas" rather than silently showing a different place
                    // than the one that was typed.
                    'by' => match (true) {
                        ($data['area_id'] ?? null) !== null => 'area',
                        ($data['zip'] ?? null) !== null => 'zip',
                        default => 'name',
                    },
                ],
                'basis' => self::BASIS_CONFIGURED,
                'week_start' => $monday->toDateString(),
                'previous_week' => $monday->subWeek()->toDateString(),
                'next_week' => $monday->addWeek()->toDateString(),
                'days' => $this->week($monday, $slots),
            ],
        ]);
    }

    /**
     * The seven days, each either closed or carrying its slots.
     *
     * A closed day is present in the list and flagged, rather than omitted:
     * the client decides whether to draw six columns or seven, and it cannot
     * make that choice from an absence it cannot distinguish from a gap in the
     * payload. The distinction the STORAGE keeps — no rows means closed — is
     * preserved here as `closed: true` with an empty slot list, which is a
     * different thing from a day whose slots are all full.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ServiceAreaSlot>>  $byWeekday
     * @return array<int, array<string, mixed>>
     */
    private function week(CarbonImmutable $monday, $byWeekday): array
    {
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $monday->addDays($i);
            $slots = $byWeekday->get($date->dayOfWeek, collect());

            $days[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->dayOfWeek,
                'closed' => $slots->isEmpty(),
                'slots' => $slots->map(fn (ServiceAreaSlot $s) => [
                    'start_time' => substr((string) $s->start_time, 0, 5),
                    'end_time' => substr((string) $s->end_time, 0, 5),
                    'capacity' => $s->max_jobs,
                ])->values()->all(),
            ];
        }

        return $days;
    }

    /**
     * The area covering a zip, within the franchises in view.
     *
     * One row at most by construction — the unique index on
     * (franchise_id, zip) is what makes this lookup unambiguous, and it is the
     * reason overlapping zips were refused rather than made configurable.
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
     * The Monday of the week being asked about.
     *
     * Monday because the design's week runs Mon–Sun. Weekday numbers stay
     * Carbon's (0 = Sunday) all the way through to the database, so nothing
     * translates between two conventions — the week's START is a display
     * decision, the weekday NUMBER is storage, and conflating them is how
     * Sunday ends up in the wrong column.
     */
    private function weekStart(?string $week): CarbonImmutable
    {
        $day = $week === null
            ? CarbonImmutable::today()
            : CarbonImmutable::createFromFormat('Y-m-d', $week)->startOfDay();

        return $day->startOfWeek(CarbonImmutable::MONDAY);
    }
}
