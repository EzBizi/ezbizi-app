<?php

namespace App\Http\Controllers\Api;

use App\Models\SchedulingHelper;
use App\Models\SchedulingHelperBooking;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Scheduling › Helpers: a franchise's helpers booked to technicians for a day
 * or a half, brought over from A1 Tools' helper_scheduling.php (A1 Leads
 * takeover, Jose 2026-10-20 "go ahead and do everything you can"). Its three
 * rules hold here, on the server:
 *
 * 1. No double-booking: a full day collides with either half. Checked inside a
 *    transaction holding the helper's rows for that date, so two technicians
 *    tapping at once cannot both get it.
 * 2. Anyone with the tab books for themselves and changes their own bookings
 *    (made by or for them). Booking for someone else, changing anyone's, and
 *    the helper roster take `scheduling.edit`.
 * 3. Franchise scope: every read and write is limited to the franchises the
 *    caller can see.
 */
final class SchedulingHelpersController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /scheduling/helpers?franchise_id= */
    public function index(Request $request): JsonResponse
    {
        $franchise = $this->franchise($request, (string) $request->query('franchise_id', ''));

        return response()->json(['data' => SchedulingHelper::query()
            ->where('franchise_id', $franchise)->orderByDesc('is_active')->orderBy('name')
            ->get(['id', 'franchise_id', 'name', 'notes', 'is_active'])]);
    }

    /** POST /scheduling/helpers (scheduling.edit) */
    public function store(Request $request): JsonResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'franchise_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $this->franchise($request, $data['franchise_id']);
        $helper = SchedulingHelper::create($data + ['organization_id' => $request->user()->organization_id]);
        $this->audit->log('scheduling.helper_created', $helper, metadata: ['name' => $helper->name]);

        return response()->json(['data' => $helper], 201);
    }

    /** PATCH /scheduling/helpers/{helper} — rename, notes, retire (scheduling.edit) */
    public function update(Request $request, string $helper): JsonResponse
    {
        $this->manager($request);
        $row = $this->helper($request, $helper);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $row->update($data);
        $this->audit->log('scheduling.helper_updated', $row, metadata: $data);

        return response()->json(['data' => $row]);
    }

    /** GET /scheduling/helpers/bookings?franchise_id=&month=Y-m */
    public function bookings(Request $request): JsonResponse
    {
        $data = $request->validate(['franchise_id' => ['required', 'uuid'], 'month' => ['required', 'date_format:Y-m']]);
        $franchise = $this->franchise($request, $data['franchise_id']);
        $start = CarbonImmutable::parse($data['month'].'-01');

        return response()->json(['data' => SchedulingHelperBooking::query()
            ->with(['helper:id,name', 'technician:id,name'])
            ->where('franchise_id', $franchise)
            ->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->orderBy('date')->get()
            ->map(fn (SchedulingHelperBooking $b) => $this->shown($b, $request->user()))]);
    }

    /** POST /scheduling/helpers/bookings */
    public function book(Request $request): JsonResponse
    {
        $data = $this->bookingInput($request);
        $helper = $this->helper($request, $data['helper_id']);
        if (! $helper->is_active) {
            throw ValidationException::withMessages(['helper_id' => 'That helper is retired.']);
        }
        $technician = $this->technician($request, $data['technician_id'] ?? null);

        $booking = DB::transaction(function () use ($request, $data, $helper, $technician) {
            $this->refuseCollision($helper->id, $data['date'], $data['slot']);

            return SchedulingHelperBooking::create([
                'organization_id' => $helper->organization_id,
                'franchise_id' => $helper->franchise_id,
                'helper_id' => $helper->id,
                'date' => $data['date'],
                'slot' => $data['slot'],
                'technician_id' => $technician,
                'job_number' => $data['job_number'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });
        $this->audit->log('scheduling.helper_booked', $booking, metadata: ['helper' => $helper->name, 'date' => $data['date'], 'slot' => $data['slot']]);

        return response()->json(['data' => $this->shown($booking->load(['helper:id,name', 'technician:id,name']), $request->user())], 201);
    }

    /** PATCH /scheduling/helpers/bookings/{booking} — own, or scheduling.edit */
    public function updateBooking(Request $request, string $booking): JsonResponse
    {
        $row = $this->ownBooking($request, $booking);
        $data = $this->bookingInput($request, partial: true);
        $helper = isset($data['helper_id']) ? $this->helper($request, $data['helper_id']) : $row->helper;
        $technician = array_key_exists('technician_id', $data) ? $this->technician($request, $data['technician_id']) : $row->technician_id;
        $date = $data['date'] ?? $row->date->toDateString();
        $slot = $data['slot'] ?? $row->slot;

        DB::transaction(function () use ($request, $row, $helper, $technician, $date, $slot, $data) {
            $this->refuseCollision($helper->id, $date, $slot, except: $row->id);
            $row->update([
                'helper_id' => $helper->id, 'date' => $date, 'slot' => $slot, 'technician_id' => $technician,
                'job_number' => array_key_exists('job_number', $data) ? $data['job_number'] : $row->job_number,
                'note' => array_key_exists('note', $data) ? $data['note'] : $row->note,
                'updated_by' => $request->user()->id,
            ]);
        });
        $this->audit->log('scheduling.helper_booking_changed', $row, metadata: ['date' => $date, 'slot' => $slot]);

        return response()->json(['data' => $this->shown($row->fresh(['helper:id,name', 'technician:id,name']), $request->user())]);
    }

    /** DELETE /scheduling/helpers/bookings/{booking} — own, or scheduling.edit */
    public function destroyBooking(Request $request, string $booking): JsonResponse
    {
        $row = $this->ownBooking($request, $booking);
        $this->audit->log('scheduling.helper_booking_removed', $row, metadata: ['date' => $row->date->toDateString(), 'slot' => $row->slot]);
        $row->delete();

        return response()->json(['data' => null]);
    }

    private function bookingInput(Request $request, bool $partial = false): array
    {
        $need = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'helper_id' => [$need, 'uuid'],
            'date' => [$need, 'date_format:Y-m-d'],
            'slot' => [$need, Rule::in(SchedulingHelperBooking::SLOTS)],
            'technician_id' => ['sometimes', 'nullable', 'uuid'],
            'job_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
    }

    /** The same helper, day and colliding half is refused, under a row lock. */
    private function refuseCollision(string $helper, string $date, string $slot, ?string $except = null): void
    {
        $taken = SchedulingHelperBooking::query()
            ->where('helper_id', $helper)->whereDate('date', $date)
            ->whereIn('slot', SchedulingHelperBooking::collides($slot))
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->lockForUpdate()->exists();

        if ($taken) {
            throw ValidationException::withMessages(['slot' => 'That helper is already booked then.']);
        }
    }

    /** Booking for someone else takes scheduling.edit; they must be in your franchises' org. */
    private function technician(Request $request, ?string $id): string
    {
        $me = $request->user();
        if ($id === null || $id === $me->id) {
            return $me->id;
        }
        $this->manager($request);
        abort_unless(User::query()->where('organization_id', $me->organization_id)->whereKey($id)->exists(), 422, 'No such person.');

        return $id;
    }

    private function ownBooking(Request $request, string $id): SchedulingHelperBooking
    {
        $row = SchedulingHelperBooking::query()
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())->findOrFail($id);
        $me = $request->user();
        abort_unless($row->technician_id === $me->id || $row->created_by === $me->id || $me->hasPermission('scheduling.edit'), 403,
            'Only your own helper bookings can be changed.');

        return $row;
    }

    private function helper(Request $request, string $id): SchedulingHelper
    {
        return SchedulingHelper::query()
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())->findOrFail($id);
    }

    private function franchise(Request $request, string $id): string
    {
        abort_unless(in_array($id, ViewScope::forUser($request->user())->franchiseIds(), true), 404);

        return $id;
    }

    private function manager(Request $request): void
    {
        abort_unless($request->user()->hasPermission('scheduling.edit'), 403,
            'Booking for others and the helper list need the right to edit Scheduling.');
    }

    private function shown(SchedulingHelperBooking $b, User $me): array
    {
        return [
            'id' => $b->id,
            'helper' => ['id' => $b->helper_id, 'name' => $b->helper?->name],
            'technician' => ['id' => $b->technician_id, 'name' => $b->technician?->name],
            'date' => $b->date->toDateString(),
            'slot' => $b->slot,
            'job_number' => $b->job_number,
            'note' => $b->note,
            // The screen hides edit buttons it cannot use; the rule is above.
            'can_change' => $b->technician_id === $me->id || $b->created_by === $me->id || $me->hasPermission('scheduling.edit'),
        ];
    }
}
