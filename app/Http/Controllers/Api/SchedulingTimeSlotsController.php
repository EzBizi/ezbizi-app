<?php

namespace App\Http\Controllers\Api;

use App\Models\SchedulingTimeSlot;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The organization's time windows — "10 AM – 1 PM", "1 – 3", "3 – 6".
 *
 * Stated once, used by every service area; see the migration
 * `2026_09_24_100000_create_scheduling_time_slots` for why capacity rows copy
 * the window rather than pointing at it, and so why an edit here moves them.
 *
 * ── Who may change them ─────────────────────────────────────────
 *
 * Reading is anyone who may open Settings. CHANGING a window changes it for
 * every franchise in the organization, so it takes an organization-level
 * assignment: a branch manager holding `scheduling.settings.view` may set how
 * many jobs their own areas take, and may not move the hours of a branch they
 * cannot see.
 */
class SchedulingTimeSlotsController extends Controller
{
    /** GET /api/v1/scheduling/time-slots */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->ordered(),
            'can_edit' => $this->mayEdit($request),
        ]);
    }

    /** POST /api/v1/scheduling/time-slots */
    public function store(Request $request): JsonResponse
    {
        $this->assertMayEdit($request);
        [$start, $end] = $this->window($request);
        $this->assertNoOverlap($start, $end);

        SchedulingTimeSlot::create(['start_time' => $start, 'end_time' => $end]);

        return response()->json(['data' => $this->ordered()], 201);
    }

    /**
     * PATCH /api/v1/scheduling/time-slots/{slot}
     *
     * Moves every capacity row on the old window to the new one, in the same
     * transaction. Without that, changing "8 – 12" to "10 – 1" would leave all
     * of A-1's 108 rows on 8 – 12: still counted by availability, no longer
     * offered by the editor, and impossible to see from the screen that set
     * them.
     */
    public function update(Request $request, string $slot): JsonResponse
    {
        $this->assertMayEdit($request);
        $row = SchedulingTimeSlot::query()->findOrFail($slot);
        [$start, $end] = $this->window($request);
        $this->assertNoOverlap($start, $end, $row->id);

        DB::transaction(function () use ($row, $start, $end) {
            ServiceAreaSlot::query()
                ->where('start_time', $this->hm($row->start_time))
                ->where('end_time', $this->hm($row->end_time))
                ->update(['start_time' => $start, 'end_time' => $end]);

            $row->update(['start_time' => $start, 'end_time' => $end]);
        });

        return response()->json(['data' => $this->ordered()]);
    }

    /**
     * DELETE /api/v1/scheduling/time-slots/{slot}
     *
     * Takes the capacity on that window with it, for every area. A window
     * nobody books into any more is not a window any area should still be
     * counted as offering.
     */
    public function destroy(Request $request, string $slot): JsonResponse
    {
        $this->assertMayEdit($request);
        $row = SchedulingTimeSlot::query()->findOrFail($slot);

        // A REMOVAL MUST NAME WHAT IT REMOVES (Jose, 2026-09-28: "anyone could
        // click by accident and delete a full time slot"). It deletes every
        // area's capacity on the window, so the request has to carry the
        // window's own hours, ?confirm=08:00-11:00. The screen asks for them
        // typed; a stray click cannot produce them.
        $window = $this->hm($row->start_time).'-'.$this->hm($row->end_time);
        if ($request->query('confirm') !== $window) {
            throw ValidationException::withMessages([
                'confirm' => "To remove this time slot, confirm it as {$window}.",
            ]);
        }

        DB::transaction(function () use ($row) {
            ServiceAreaSlot::query()
                ->where('start_time', $this->hm($row->start_time))
                ->where('end_time', $this->hm($row->end_time))
                ->delete();

            $row->delete();
        });

        return response()->json(['data' => $this->ordered()]);
    }

    /** @return array<int, array{id: string, start_time: string, end_time: string}> */
    private function ordered(): array
    {
        // How many areas have capacity on each window, so a change or a
        // removal can say what it will touch before anybody confirms it.
        // Counted within the view (company or branch), not the whole
        // organization (Jose, 2026-10-28: A1 Garage Doors read "43 areas").
        $used = ServiceAreaSlot::query()
            ->whereIn('service_area_id', ServiceArea::query()->whereIn('franchise_id', ViewScope::forUser(request()->user())->franchiseIds())->select('id'))
            ->selectRaw('start_time, end_time, COUNT(DISTINCT service_area_id) as areas')
            ->groupBy('start_time', 'end_time')
            ->get()
            ->mapWithKeys(fn ($r) => [$this->hm($r->start_time).'-'.$this->hm($r->end_time) => (int) $r->areas]);

        return SchedulingTimeSlot::query()
            ->orderBy('start_time')
            ->get()
            ->map(fn (SchedulingTimeSlot $s) => [
                'id' => $s->id,
                'start_time' => $this->hm($s->start_time),
                'end_time' => $this->hm($s->end_time),
                'areas' => $used[$this->hm($s->start_time).'-'.$this->hm($s->end_time)] ?? 0,
            ])
            ->all();
    }

    /** @return array{0: string, 1: string} */
    private function window(Request $request): array
    {
        $data = $request->validate([
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
        ]);

        if ($data['end_time'] <= $data['start_time']) {
            throw ValidationException::withMessages([
                'end_time' => 'A time slot has to end after it starts.',
            ]);
        }

        return [$data['start_time'], $data['end_time']];
    }

    /**
     * Two windows may touch — 10:00–13:00 and 13:00–15:00 — and may not
     * overlap. A1 Tools allows overlaps and then needs a tie-break rule to
     * decide which window a 14:30 job belongs to; refusing them here means
     * that question never has to be answered.
     */
    private function assertNoOverlap(string $start, string $end, ?string $except = null): void
    {
        $clash = SchedulingTimeSlot::query()
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'start_time' => 'That overlaps '.$this->hm($clash->start_time).' – '
                    .$this->hm($clash->end_time).'. Time slots can touch but not overlap.',
            ]);
        }
    }

    private function mayEdit(Request $request): bool
    {
        $user = $request->user();

        return $user->organization !== null && $user->canActOnOrganization($user->organization);
    }

    private function assertMayEdit(Request $request): void
    {
        abort_unless(
            $this->mayEdit($request),
            403,
            'Time slots apply to every branch, so only someone who works at the whole-organization '
                .'level can change them. You can still set how many jobs your own areas take.',
        );
    }

    /** "08:00:00" and "08:00" both come back as "08:00". */
    private function hm(mixed $time): string
    {
        return substr((string) $time, 0, 5);
    }
}
