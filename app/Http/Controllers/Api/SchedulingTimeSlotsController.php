<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\SchedulingTimeSlot;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
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
        $company = $this->viewCompany($request);
        $owner = SchedulingTimeSlot::ownerFor($company);

        return response()->json([
            'data' => $this->ordered($owner),
            'can_edit' => $this->mayEdit($request),
            // Per company since 2026-10-28: whose windows these are, when not
            // the company in view's own.
            'inherited_from' => $company === null || $owner === $company ? null
                : ($owner === null ? 'the organization' : Company::query()->whereKey($owner)->value('name')),
        ]);
    }

    /** POST /api/v1/scheduling/time-slots */
    public function store(Request $request): JsonResponse
    {
        $this->assertMayEdit($request);
        [$start, $end] = $this->window($request);
        $owner = $this->ownSet($request);
        $this->assertNoOverlap($owner, $start, $end);

        SchedulingTimeSlot::create(['company_id' => $owner, 'start_time' => $start, 'end_time' => $end]);

        return response()->json(['data' => $this->ordered($owner)], 201);
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
        [$start, $end] = $this->window($request);
        [$owner, $row] = $this->ownRow($request, $slot);
        $this->assertNoOverlap($owner, $start, $end, $row->id);

        DB::transaction(function () use ($row, $start, $end, $owner) {
            ServiceAreaSlot::query()
                ->whereIn('service_area_id', $this->areasOf($owner))
                ->where('start_time', $this->hm($row->start_time))
                ->where('end_time', $this->hm($row->end_time))
                ->update(['start_time' => $start, 'end_time' => $end]);

            $row->update(['start_time' => $start, 'end_time' => $end]);
        });

        return response()->json(['data' => $this->ordered($owner)]);
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

        [$owner, $row] = $this->ownRow($request, $slot);
        DB::transaction(function () use ($row, $owner) {
            ServiceAreaSlot::query()
                ->whereIn('service_area_id', $this->areasOf($owner))
                ->where('start_time', $this->hm($row->start_time))
                ->where('end_time', $this->hm($row->end_time))
                ->delete();

            $row->delete();
        });

        return response()->json(['data' => $this->ordered($owner)]);
    }

    /** The company in view (a branch's company); null at organization level. */
    private function viewCompany(Request $request): ?string
    {
        $view = ViewScope::forUser($request->user());

        return match ($view->type) {
            ViewScope::COMPANY => $view->id,
            ViewScope::FRANCHISE => Franchise::query()->whereKey($view->id)->value('company_id'),
            default => null,
        };
    }

    /**
     * The set a change in this view writes: the company's own. A company
     * still on inherited windows gets a copy first, so its change never
     * moves another company's (copy on write).
     */
    private function ownSet(Request $request): ?string
    {
        $company = $this->viewCompany($request);
        $owner = SchedulingTimeSlot::ownerFor($company);
        if ($company !== null && $owner !== $company) {
            foreach (SchedulingTimeSlot::query()->where('company_id', $owner)->get() as $w) {
                SchedulingTimeSlot::create(['company_id' => $company, 'start_time' => $this->hm($w->start_time), 'end_time' => $this->hm($w->end_time)]);
            }
        }

        return $company;
    }

    /** @return array{0: ?string, 1: SchedulingTimeSlot} the view's own row with the hours of $slot */
    private function ownRow(Request $request, string $slot): array
    {
        $picked = SchedulingTimeSlot::query()->findOrFail($slot);
        $owner = $this->ownSet($request);

        return [$owner, SchedulingTimeSlot::query()->where('company_id', $owner)
            ->where('start_time', $picked->start_time)->where('end_time', $picked->end_time)->firstOrFail()];
    }

    /** Areas booking into $owner's windows: every company inheriting them. */
    private function areasOf(?string $owner): Builder
    {
        return ServiceArea::query()->whereIn('franchise_id',
            Franchise::query()->whereIn('company_id', SchedulingTimeSlot::inheritors($owner))->select('id'))->select('id');
    }

    /** @return array<int, array{id: string, start_time: string, end_time: string}> */
    private function ordered(?string $owner): array
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
            ->where('company_id', $owner)
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
    private function assertNoOverlap(?string $owner, string $start, string $end, ?string $except = null): void
    {
        $clash = SchedulingTimeSlot::query()
            ->where('company_id', $owner)
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

        $company = $this->viewCompany($request);
        if ($company !== null) {
            $row = Company::query()->find($company);

            return $row !== null && $user->canActOnCompany($row);
        }

        return $user->organization !== null && $user->canActOnOrganization($user->organization);
    }

    private function assertMayEdit(Request $request): void
    {
        abort_unless(
            $this->mayEdit($request),
            403,
            'Time slots apply to every branch of a company, so only someone who works at the company '
                .'or organization level can change them. You can still set how many jobs your own areas take.',
        );
    }

    /** "08:00:00" and "08:00" both come back as "08:00". */
    private function hm(mixed $time): string
    {
        return substr((string) $time, 0, 5);
    }
}
