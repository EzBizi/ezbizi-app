<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\SchedulingWarnOverbooking;
use App\Models\Automation;
use App\Models\ServiceArea;
use App\Support\Scheduling\Openings;
use App\Support\Scheduling\Overbooking;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scheduling › Overbooking (Jose, 2026-09-29: "a new tab on scheduling that
 * warns me about all the overbookings"). Days booked past what an area or a
 * franchise takes, from today forward, and the areas with no spot left in the
 * next seven days. See App\Support\Scheduling\Overbooking for the rules.
 */
class SchedulingOverbookingController extends Controller
{
    /** How far ahead the fully-booked check looks — the warning email's window. */
    public const FULL_DAYS = 7;

    /**
     * GET /api/v1/scheduling/overbooking[?days=30 | ?date=2026-10-05]
     *
     * `date` is one day on its own (Jose, 2026-09-29: "pick a date and it
     * shows me the overbooking for that specific date so that way i can fix
     * it").
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $days = isset($data['date']) ? 1 : (int) ($data['days'] ?? 30);

        $areas = ServiceArea::query()
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->get();

        // Today counts: an overbooked today is still a problem somebody has to
        // solve. The fully-booked check is about what can still be offered,
        // which starts tomorrow, as the search does.
        $tomorrow = Openings::tomorrow((string) app(SettingsResolver::class)
            ->get('timezone', $request->user()->organization));
        $today = $tomorrow->subDay();
        $from = isset($data['date']) ? CarbonImmutable::parse($data['date']) : $today;
        $check = app(Overbooking::class);

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'days' => $days,
                'overbooked' => $check->overbooked($areas, $from, $days),
                'full_from' => $tomorrow->toDateString(),
                'full_days' => self::FULL_DAYS,
                'fully_booked' => $check->fullyBooked($areas, $tomorrow, self::FULL_DAYS),
            ],
        ]);
    }

    /**
     * GET /api/v1/scheduling/reports — the Overbooking warning as a row in
     * Reports' library (Jose, 2026-10-01: "it's not showing up on the reports
     * tab, it should show there"). Scheduling builds the row, Reports draws it.
     *
     * Its senders are the rules on `scheduling.overbooking_found`, in the shape
     * of ReportSenders' rows. Rule names are Automations' data: without
     * automations.view `sent_by` is null and only `sent_automatically` says
     * whether one goes out.
     */
    public function reports(Request $request): JsonResponse
    {
        $rules = Automation::query()
            ->where('trigger_key', SchedulingWarnOverbooking::TRIGGER)
            ->orderBy('name')
            ->get()
            ->map(fn (Automation $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'schedule' => $rule->schedule,
                'next_run_at' => $rule->next_run_at?->toIso8601String(),
                'is_active' => (bool) $rule->is_active,
                'disabled_at' => $rule->disabled_at?->toIso8601String(),
            ]);

        return response()->json(['data' => [[
            'id' => 'overbooking',
            'name' => 'Overbooking warning',
            'description' => 'Service areas overbooked or fully booked in the next '.self::FULL_DAYS.' days',
            'covers' => 'Every service area',
            'sent_automatically' => $rules->contains(fn (array $r) => $r['is_active'] && $r['disabled_at'] === null),
            'sent_by' => $request->user()->hasPermission('automations.view') ? $rules->all() : null,
        ]]]);
    }
}
