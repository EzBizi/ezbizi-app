<?php

namespace App\Http\Controllers\Api;

use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Support\Scheduling\SlotShape;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applying one slot pattern across many service areas
 * (design docs/previews/scheduling-layouts.html #7, Overview pane).
 *
 * ── The two guards, and why they are here and not in the client ──
 *
 * 1. ALL OR NONE. A partial apply is the only thing in this design that
 *    manufactures a difference nobody chose: reach twenty-eight areas, miss
 *    two, and those two now differ with no human having decided they should.
 *    Since "an area differs" is derived from its rows, that drift is
 *    indistinguishable afterwards from a deliberate exception. So it happens
 *    in one transaction or not at all — a client attempting this tidily with
 *    twenty-eight requests is exactly the thing that cannot be made safe.
 *
 * 2. NAME WHAT IT WILL OVERWRITE, FIRST. `preview` returns every area that
 *    would change AND what it would change, so the confirmation can say
 *    "Houston takes 1 on Thursday afternoon; this will change it to 2".
 *    A generic "this will overwrite existing settings" is what people click
 *    through, and clicking through it is the silent flatten this exists to
 *    prevent.
 *
 * These two together are what stands in place of a stored "this area is
 * deliberately different" flag. See the spec on why that column is not here.
 *
 * ── NOTHING CALLS EITHER OF THESE. Measured 2026-09-17 ───────────
 *
 * Neither endpoint has ever appeared in the client, in ANY commit:
 * `git log -S"pattern/apply" -- client/` returns nothing, against three hits
 * for a route that is wired. They were built on 2026-08-31 alongside an
 * Overview card that offered "apply this pattern to every area", the card was
 * never wired to them, and c2de5403 then removed the card outright on Jose's
 * instruction — "get rid of your slot pattern, i dont need to see that".
 *
 * So this is reachable API with green tests and no caller, which is the exact
 * thing that reads as maintained and is not. It is recorded here rather than
 * deleted because the decision is Jose's and he has not answered it twice;
 * asking a third time is noise, and silently deleting a shipped endpoint on
 * his behalf is worse. It is gated behind `scheduling.settings.view` with
 * everything else that changes capacity, so it is not an open door.
 *
 * WHOEVER PICKS THIS UP: the choice is delete it, or wire "apply to every
 * area" back into Settings. What it must not do is sit here for another month
 * looking like a feature.
 */
class SchedulingPatternController extends Controller
{
    /** POST /api/v1/scheduling/pattern/preview */
    public function preview(Request $request): JsonResponse
    {
        [$areas, $slots] = $this->intent($request, 'view');

        $shape = SlotShape::of($this->hydrate($slots));

        $changes = [];

        foreach ($areas as $area) {
            $current = SlotShape::of($area->slots);

            if ($current === $shape) {
                continue;
            }

            $changes[] = [
                'id' => $area->id,
                'name' => $area->name,
                'from' => SlotShape::describe($current),
                'to' => SlotShape::describe($shape),
            ];
        }

        return response()->json([
            'data' => [
                'changes' => $changes,
                'unchanged' => $areas->count() - count($changes),
                // The client shows this before it shows a button. An empty
                // list is a real answer — "every area already matches" — and
                // is not the same as nothing being selected.
                'changes_nothing' => $changes === [],
            ],
        ]);
    }

    /** POST /api/v1/scheduling/pattern/apply */
    public function apply(Request $request): JsonResponse
    {
        [$areas, $slots] = $this->intent($request, 'update');

        $applied = DB::transaction(function () use ($areas, $slots) {
            $count = 0;

            foreach ($areas as $area) {
                ServiceAreaSlot::where('service_area_id', $area->id)->delete();
                $area->forceFill(['capacity_set_at' => now()])->save();

                foreach ($slots as $slot) {
                    ServiceAreaSlot::create([
                        'service_area_id' => $area->id,
                        'weekday' => $slot['weekday'],
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'max_jobs' => $slot['max_jobs'],
                    ]);
                }

                $count++;
            }

            return $count;
        });

        return response()->json(['data' => ['applied_to' => $applied]]);
    }

    /**
     * The areas and the slots a request is asking about, both checked.
     *
     * Every named area is authorized individually. Authorizing the franchise
     * once and trusting the list would let a caller name an area it cannot
     * reach and have it rewritten alongside ones it can — the bulk operation
     * must not be a way to reach further than the single-area one.
     *
     * @return array{0: Collection<int, ServiceArea>, 1: array<int, array{weekday:int, start_time:string, end_time:string, max_jobs:int}>}
     */
    private function intent(Request $request, string $ability): array
    {
        $data = $request->validate([
            'area_ids' => ['required', 'array', 'min:1'],
            'area_ids.*' => ['required', 'uuid'],
            'slots' => ['present', 'array'],
            'slots.*.weekday' => ['required', 'integer', 'between:0,6'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
            'slots.*.max_jobs' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        $scope = ViewScope::forUser($request->user());

        $areas = ServiceArea::query()
            ->whereIn('franchise_id', $scope->franchiseIds())
            ->whereIn('id', $data['area_ids'])
            ->with('slots')
            ->orderBy('name')
            ->get();

        // Refuse rather than silently applying to the subset that resolved:
        // "applied to 27 of 29" is the partial state guard 1 exists to make
        // unreachable, and it would be reached here instead of in the loop.
        if ($areas->count() !== count(array_unique($data['area_ids']))) {
            throw ValidationException::withMessages([
                'area_ids' => 'One of those service areas is not in view, so nothing was changed. '
                    .'Reload the list of areas and try again.',
            ]);
        }

        foreach ($areas as $area) {
            $this->authorize($ability, $area);
        }

        return [$areas, $data['slots']];
    }

    /**
     * Slot arrays as models, so SlotShape sees the same thing whether the rows
     * came from the database or from the request.
     *
     * @param  array<int, array{weekday:int, start_time:string, end_time:string, max_jobs:int}>  $slots
     * @return Collection<int, ServiceAreaSlot>
     */
    private function hydrate(array $slots): Collection
    {
        return collect($slots)->map(fn (array $s) => new ServiceAreaSlot([
            'weekday' => $s['weekday'],
            'start_time' => $s['start_time'],
            'end_time' => $s['end_time'],
            'max_jobs' => $s['max_jobs'],
        ]));
    }
}
