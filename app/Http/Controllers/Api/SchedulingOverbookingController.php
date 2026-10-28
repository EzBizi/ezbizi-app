<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\SchedulingWarnOverbooking;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\SchedulingRescheduleOffer;
use App\Models\ServiceArea;
use App\Support\Scheduling\Openings;
use App\Support\Scheduling\Overbooking;
use App\Support\Scheduling\RescheduleOffers;
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
    public const FULL_DAYS = Overbooking::FULL_DAYS;

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
     * GET /api/v1/scheduling/overbooking/fill[?date=Y-m-d]: every area's day,
     * booked against capacity (default today). The jobs behind a row are
     * `overbooking/jobs?date=&area_id=`.
     */
    public function fill(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $today = Openings::tomorrow((string) app(SettingsResolver::class)
            ->get('timezone', $request->user()->organization))->subDay();
        $date = isset($data['date']) ? CarbonImmutable::parse($data['date']) : $today;

        $areas = ServiceArea::query()
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->get();

        return response()->json(['data' => [
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'areas' => app(Overbooking::class)->fill($areas, $date),
        ]]);
    }

    /** POST /scheduling/overbooking/offers/preview {area_id, date}: who an earlier-opening text would reach */
    public function previewOffers(Request $request, RescheduleOffers $offers): JsonResponse
    {
        [$area, $date] = $this->offerTarget($request);

        return response()->json(['data' => $offers->preview($area, $date)]);
    }

    /** POST /scheduling/overbooking/offers/send {area_id, date, job_ids} (scheduling.edit) */
    public function sendOffers(Request $request, RescheduleOffers $offers): JsonResponse
    {
        abort_unless($request->user()->hasPermission('scheduling.edit'), 403, 'Sending offers needs the right to edit Scheduling.');
        [$area, $date] = $this->offerTarget($request);
        $ids = $request->validate(['job_ids' => ['required', 'array', 'min:1'], 'job_ids.*' => ['uuid']])['job_ids'];

        return response()->json(['data' => $offers->send($area, $date, $ids, $request->user())]);
    }

    /** GET /scheduling/reschedule-offers?lead_id= : the offer a lead's thread is about, if any */
    public function offerForLead(Request $request): JsonResponse
    {
        $lead = $request->validate(['lead_id' => ['required', 'uuid']])['lead_id'];
        $offer = SchedulingRescheduleOffer::query()->where('lead_id', $lead)
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())->latest()->first();

        return response()->json(['data' => $offer === null ? null : [
            'id' => $offer->id, 'job_id' => $offer->job_id, 'open_date' => $offer->open_date->toDateString(),
            'start_time' => $offer->start_time === null ? null : substr((string) $offer->start_time, 0, 5),
            'end_time' => $offer->end_time === null ? null : substr((string) $offer->end_time, 0, 5),
            'booked_for' => $offer->booked_for?->format('Y-m-d H:i'),
            'moved_at' => $offer->moved_at?->toIso8601String(),
            'can_move' => $request->user()->hasPermission('scheduling.edit') && $offer->moved_at === null,
        ]]);
    }

    /** POST /scheduling/reschedule-offers/{offer}/move (scheduling.edit): the customer said yes */
    public function moveOffer(Request $request, string $offer, RescheduleOffers $offers): JsonResponse
    {
        abort_unless($request->user()->hasPermission('scheduling.edit'), 403, 'Moving an appointment needs the right to edit Scheduling.');
        $row = SchedulingRescheduleOffer::query()->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())->findOrFail($offer);
        $job = $offers->move($row);

        return response()->json(['data' => ['job_id' => $job->id, 'scheduled_for' => $job->getRawOriginal('scheduled_for')]]);
    }

    /** @return array{0: ServiceArea, 1: CarbonImmutable} */
    private function offerTarget(Request $request): array
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d']]);
        $area = ServiceArea::query()->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())->findOrFail($data['area_id']);
        $this->authorize('view', $area);

        return [$area, CarbonImmutable::parse($data['date'])];
    }

    /**
     * GET /api/v1/scheduling/overbooking/jobs?date=Y-m-d&area_id= | &franchise_id=
     *
     * The jobs behind one overbooked row, each with its client and its
     * appointment (Bar, via Jose, 2026-10-12: "make everything clickable to
     * show its full information"). The rows are Openings' own, so the list is
     * exactly what the row counted: placed jobs, Workiz jobs not projected
     * yet, and website bookings still holding a seat.
     */
    public function jobs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'area_id' => ['required_without:franchise_id', 'nullable', 'uuid'],
            'franchise_id' => ['required_without:area_id', 'nullable', 'uuid'],
        ]);
        $inView = ViewScope::forUser($request->user())->franchiseIds();
        $date = CarbonImmutable::parse($data['date']);
        $openings = app(Openings::class);

        if (isset($data['area_id'])) {
            $area = ServiceArea::query()->whereIn('franchise_id', $inView)->findOrFail($data['area_id']);
            $rows = $openings->jobsOn($area, $date);
        } else {
            abort_unless(in_array($data['franchise_id'], $inView, true), 404);
            $rows = $openings->franchiseJobsOn($request->user()->organization_id, $data['franchise_id'], $date);
        }

        $customers = Customer::query()->whereIn('id', $rows->pluck('customer_id')->filter()->unique())->get()->keyBy('id');

        return response()->json(['data' => $rows
            ->map(fn (object $row) => $this->detail($row, $customers[$row->customer_id ?? ''] ?? null))
            ->sortBy(fn (array $j) => $j['time'] ?? '99:99')
            ->values()->all()]);
    }

    /** One job as the Overbooking list shows it: the client, then the appointment. */
    public static function detail(object $row, ?Customer $customer): array
    {
        $p = is_string($row->payload ?? null) ? (json_decode($row->payload, true) ?: []) : [];
        $hhmm = fn (?string $at) => $at === null || strlen($at) < 16 || substr($at, 11, 5) === '00:00' ? null : substr($at, 11, 5);
        $text = fn (mixed $v) => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $booking = $row->kind === 'booking';
        $workizName = trim(($p['FirstName'] ?? '').' '.($p['LastName'] ?? ''));

        $street = $text($row->address ?? null) ?? $text($p['Address'] ?? null) ?? $customer?->address_line1;
        $city = $text($row->city ?? null) ?? $text($p['City'] ?? null) ?? $customer?->city;
        $zip = $text($row->postal_code ?? null) ?? $text($p['PostalCode'] ?? null) ?? $customer?->postal_code;

        return [
            'kind' => $row->kind,
            'job_id' => $row->job_id ?? null,
            'lead_id' => $row->lead_id ?? null,
            'number' => $text($p['SerialId'] ?? null),
            'status' => $booking ? 'Online booking' : $row->status,
            'time' => $hhmm((string) $row->scheduled_for),
            'end_time' => $booking ? ($row->end_time ? substr((string) $row->end_time, 0, 5) : null) : $hhmm($p['JobEndDateTime'] ?? null),
            'service' => $text($p['JobType'] ?? null),
            'technicians' => array_values(array_filter(array_map(
                fn ($t) => is_array($t) ? $text($t['Name'] ?? null) : null,
                is_array($p['Team'] ?? null) ? $p['Team'] : [],
            ))),
            'notes' => $text(implode("\n", array_filter([$text($p['JobNotes'] ?? null), $text($p['Notes'] ?? null)]))),
            'customer' => [
                'name' => $customer?->name ?? ($workizName !== '' ? $workizName : $text($row->contact_name ?? null)),
                'phone' => $text($p['Phone'] ?? null) ?? $customer?->phone ?? $text($row->phone ?? null),
                'email' => $text($p['Email'] ?? null) ?? $customer?->email ?? $text($row->email ?? null),
                'address' => implode(', ', array_filter([$street, trim(($city ?? '').' '.($zip ?? ''))])) ?: null,
            ],
        ];
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
            'description' => 'Service areas with no spot left for the next '.self::FULL_DAYS.' days or more',
            'covers' => 'Every service area',
            'sent_automatically' => $rules->contains(fn (array $r) => $r['is_active'] && $r['disabled_at'] === null),
            'sent_by' => $request->user()->hasPermission('automations.view') ? $rules->all() : null,
        ]]]);
    }
}
