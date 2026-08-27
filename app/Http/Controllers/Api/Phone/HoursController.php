<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\ResolvesPhoneConnections;
use App\Models\Franchise;
use App\Models\PhoneConnection;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Named open-hours schedules for a Twilio connection (spec §7, Task 7).
 *
 * Create may name a number: franchise-scoped numbers default timezone from
 * franchises.timezone and attach the new schedule to that line.
 */
class HoursController extends Controller
{
    use ResolvesPhoneConnections;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/hours */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneHoursSchedule::class);

        $connectionIds = $this->connectionsInReach($request->user())->pluck('id')->all();

        $rows = PhoneHoursSchedule::query()
            ->whereIn('connection_id', $connectionIds)
            ->orderBy('name')
            ->get()
            ->map(fn (PhoneHoursSchedule $schedule) => $this->row($schedule))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/hours */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'connection_id' => ['required', 'uuid', $this->existsInTenant('phone_connections')],
            'number_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('phone_numbers')],
            'name' => ['required', 'string', 'max:191'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone', 'max:64'],
            'weeks' => ['required', 'array'],
            'exceptions' => ['sometimes', 'nullable', 'array'],
        ]);

        $connection = PhoneConnection::query()->findOrFail($input['connection_id']);
        $this->authorize('create', [PhoneHoursSchedule::class, $connection]);

        $number = null;
        if (filled($input['number_id'] ?? null)) {
            $number = PhoneNumber::query()->findOrFail($input['number_id']);
            if ($number->connection_id !== $connection->id) {
                throw ValidationException::withMessages([
                    'number_id' => 'That phone number is not on this connection.',
                ]);
            }
        }

        $timezone = filled($input['timezone'] ?? null)
            ? (string) $input['timezone']
            : $this->defaultTimezone($number);

        if ($timezone === null || $timezone === '') {
            throw ValidationException::withMessages([
                'timezone' => 'Choose a timezone for these hours.',
            ]);
        }

        $schedule = DB::transaction(function () use ($input, $connection, $number, $timezone) {
            $schedule = PhoneHoursSchedule::query()->create([
                'organization_id' => $this->tenant->idOrFail(),
                'connection_id' => $connection->id,
                'name' => $input['name'],
                'timezone' => $timezone,
                'weeks' => $input['weeks'],
                'exceptions' => $input['exceptions'] ?? null,
            ]);

            if ($number !== null) {
                $number->update(['hours_schedule_id' => $schedule->id]);
            }

            return $schedule;
        });

        return response()->json(['data' => $this->row($schedule)], Response::HTTP_CREATED);
    }

    /** GET /api/v1/phone/hours/{schedule} */
    public function show(PhoneHoursSchedule $schedule): JsonResponse
    {
        $this->authorize('view', $schedule);

        return response()->json(['data' => $this->row($schedule)]);
    }

    /** PATCH /api/v1/phone/hours/{schedule} */
    public function update(Request $request, PhoneHoursSchedule $schedule): JsonResponse
    {
        $this->authorize('update', $schedule);

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'timezone' => ['sometimes', 'string', 'timezone', 'max:64'],
            'weeks' => ['sometimes', 'array'],
            'exceptions' => ['sometimes', 'nullable', 'array'],
        ]);

        $schedule->fill($input)->save();

        return response()->json(['data' => $this->row($schedule->fresh())]);
    }

    /** DELETE /api/v1/phone/hours/{schedule} */
    public function destroy(PhoneHoursSchedule $schedule): JsonResponse
    {
        $this->authorize('delete', $schedule);

        $id = $schedule->id;

        DB::transaction(function () use ($schedule) {
            PhoneNumber::query()
                ->where('hours_schedule_id', $schedule->id)
                ->update(['hours_schedule_id' => null]);

            $schedule->delete();
        });

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    private function defaultTimezone(?PhoneNumber $number): ?string
    {
        if ($number === null || $number->scope_type !== PhoneNumber::SCOPE_FRANCHISE) {
            return null;
        }

        $franchise = Franchise::query()->find($number->scope_id);
        if ($franchise === null) {
            return null;
        }

        $tz = $franchise->timezone;

        return is_string($tz) && $tz !== '' ? $tz : 'America/New_York';
    }

    /** @return array<string, mixed> */
    private function row(PhoneHoursSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'organization_id' => $schedule->organization_id,
            'connection_id' => $schedule->connection_id,
            'name' => $schedule->name,
            'timezone' => $schedule->timezone,
            'weeks' => $schedule->weeks,
            'exceptions' => $schedule->exceptions,
        ];
    }
}
