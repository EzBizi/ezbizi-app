<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use Closure;
use DateTimeZone;
use App\Support\Phone\PhoneScope;
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
            ->tap(fn ($q) => PhoneScope::apply($q, $request->user()))
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
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64', self::usableTimezone()],
            'weeks' => ['required', 'array', self::weekOfTimes()],
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

        // Resolved OUTSIDE the transaction: it reads the caller's view, which
        // is a property of the request rather than of the write.
        [$scopeType, $scopeId] = PhoneScope::filing($request->user());

        $schedule = DB::transaction(function () use ($input, $connection, $number, $timezone, $scopeType, $scopeId) {

            $schedule = PhoneHoursSchedule::query()->create([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
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
            'timezone' => ['sometimes', 'string', 'max:64', self::usableTimezone()],
            'weeks' => ['sometimes', 'array', self::weekOfTimes()],
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
            // The calendar date where the phone is, so the list can say
            // whether this schedule is open TODAY and which closed day comes
            // next (Mia, 2026-10-02). The client has no timezone database,
            // and its own date is a day off for half the evening on a coast.
            'today' => self::localDate($schedule->timezone),
        ];
    }

    /** Today's Y-m-d on the schedule's own wall clock; UTC if it has none. */
    private static function localDate(mixed $timezone): string
    {
        try {
            $zone = new DateTimeZone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');
        } catch (\Exception) {
            $zone = new DateTimeZone('UTC');
        }

        return now()->setTimezone($zone)->toDateString();
    }

    /**
     * A timezone PHP can actually use, which is not what Laravel's rule asks.
     *
     * The screen offers whole-hour offsets rather than a city list, so it
     * sends `Etc/GMT+5` — a real zone, constructible, and the value the rest
     * of the module already stores. Laravel's `timezone` rule refuses it:
     * that rule checks the CANONICAL identifier group, and the Etc zones live
     * in the backwards-compatible set. `timezone:all` refuses it too.
     *
     * So saving any hours at all failed with "The timezone field must be a
     * valid timezone" against a timezone that was perfectly valid. The rule
     * was asking a narrower question than the one that matters, which is
     * simply: can this be used to tell the time.
     */
    private static function usableTimezone(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (! in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                $fail('That is not a clock EzBizi can read hours against.');
            }
        };
    }

    /**
     * Every open period is a real HH:MM pair, in order.
     *
     * The times were free text and nothing checked them, so "08:00 sd asdasd"
     * saved happily and then HoursClock compared it as a string — a schedule
     * that looks set and answers wrongly for every call, which is worse than
     * one that refuses to save.
     */
    private static function weekOfTimes(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            foreach ($value as $day => $periods) {
                if (! is_array($periods)) {
                    $fail('The hours for '.$day.' are not a list of times.');

                    return;
                }

                foreach ($periods as $period) {
                    if (! is_array($period) || count($period) !== 2) {
                        $fail('Each period on '.$day.' needs an opening and a closing time.');

                        return;
                    }

                    [$from, $to] = array_values($period);

                    foreach ([$from, $to] as $time) {
                        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                            $fail('"'.$time.'" on '.$day.' is not a time. Use 24-hour HH:MM, like 08:00.');

                            return;
                        }
                    }

                    // Equal is not a period either: it opens and shuts in the
                    // same instant, which reads as open and behaves as closed.
                    if (strcmp((string) $from, (string) $to) >= 0) {
                        $fail('On '.$day.', '.$from.' is not before '.$to.'.');

                        return;
                    }
                }
            }
        };
    }
}
