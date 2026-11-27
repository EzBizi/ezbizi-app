<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\SchedulingSeedCompanyAvailability;
use App\Models\Franchise;
use App\Models\FranchiseDailyLimit;
use App\Models\ServiceArea;
use App\Models\ServiceAreaDailyLimit;
use App\Models\ServiceAreaDateOverride;
use App\Models\ServiceAreaScheduledSlot;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Models\ServiceAreaZipRelease;
use App\Support\Scheduling\Openings;
use App\Support\Scheduling\SlotShape;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service areas, their zip codes, and their capacity grid
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md).
 *
 * Draws the Settings tab: design docs/previews/scheduling-layouts.html #7.
 */
class SchedulingAreasController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/scheduling/areas
     *
     * The rail and the Overview pane in one answer, because they are one
     * screen and a second round trip would let them disagree about which
     * areas exist.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        // A switched-off branch's areas are not listed (Jose, 2026-11-10);
        // they stay, and reachable() still opens them.
        $areas = $this->reachable($request)
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->switchedOnFranchiseIds())
            ->with(['slots', 'zips'])
            ->orderBy('name')
            ->get();

        $shapes = $areas->mapWithKeys(
            fn (ServiceArea $a) => [$a->id => SlotShape::of($a->slots)]
        );

        $pattern = SlotShape::commonest($shapes->values()->all());

        $areaLimits = ServiceAreaDailyLimit::query()
            ->whereIn('service_area_id', $areas->pluck('id'))
            ->get()
            ->groupBy('service_area_id');

        return response()->json([
            'data' => $areas->map(fn (ServiceArea $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'color' => $a->color,
                'franchise_id' => $a->franchise_id,
                'shares_with_id' => $a->shares_with_id,
                'zip_count' => $a->zips->count(),
                // Derived, never stored. An area "differs" because its rows
                // differ, so the two can never disagree — see the spec on why
                // there is no pattern table.
                'follows_pattern' => $pattern !== null
                    && $shapes[$a->id] === $pattern,
                // Every weekday, so the Overview can show what each area takes
                // without a request per area. Costs nothing extra: `slots` is
                // already eager-loaded above for the shape comparison.
                //
                // A day with no rows is CLOSED and comes back as null, not 0.
                // Zero would be a day that is open and takes nothing, which
                // the storage deliberately cannot express — the whole module
                // rests on that distinction.
                'week' => $this->week($a->slots),
                // Set up, even when every day is closed (Mia, 2026-10-02): the
                // Overview says "Not set up" only for an area nobody saved.
                'configured' => $a->capacity_set_at !== null || $a->slots->isNotEmpty(),
                // The area's own daily limit, weekday => jobs; the Overview's
                // totals take the lower of this and the week above.
                'daily_limits' => (object) ($areaLimits[$a->id] ?? collect())->pluck('max_jobs', 'weekday')->all(),
            ])->all(),
            // Each franchise's daily limit, weekday => jobs, for the franchise
            // Overview. A franchise with none is absent; a weekday with none is
            // absent. Objects, not lists — see week().
            'franchise_limits' => (object) FranchiseDailyLimit::query()
                ->whereIn('franchise_id', $areas->pluck('franchise_id')->unique())
                ->get()
                ->groupBy('franchise_id')
                ->map(fn ($rows) => (object) $rows->pluck('max_jobs', 'weekday')->all())
                ->all(),
            'pattern' => $pattern === null ? null : [
                'slots' => SlotShape::describe($pattern),
                'followed_by' => $shapes->filter(fn ($s) => $s === $pattern)->count(),
                'of' => $areas->count(),
            ],
        ]);
    }

    /**
     * One area's week as `weekday => jobs|null`, all seven days present.
     *
     * Null for a closed day rather than 0, and all seven present rather than
     * only the open ones: the caller has to be able to tell "we do not work
     * Sundays" from "Sunday takes nothing", and it cannot do that from a key
     * that is simply missing.
     *
     * @param  Collection<int, ServiceAreaSlot>  $slots
     */
    private function week($slots): \stdClass
    {
        $byDay = $slots->groupBy('weekday');

        $out = [];

        foreach (range(0, 6) as $weekday) {
            $day = $byDay->get($weekday);

            $out[$weekday] = $day === null
                ? null
                : (int) $day->sum('max_jobs');
        }

        // CAST, and it is the whole bug this method once shipped. Keys 0..6 in
        // order make this a PHP LIST, and json_encode writes a list as a JSON
        // ARRAY — `[null,2,2,2,2,2,1]`, not `{"0":null,"1":2,...}`. The client
        // reads it as a map keyed by weekday, so every area threw a cast error
        // and the whole Settings pane rendered grey.
        //
        // Neither test could see it. PHP treats `$week['1']` and `$week[1]` as
        // the same key, so a decoded array satisfied the server assertion; and
        // the widget fixture was written to the shape I MEANT rather than the
        // one the server sent, so it agreed with itself. The test below now
        // asserts this is not a list, which is the one thing that separates
        // them.
        return (object) $out;
    }

    /** POST /api/v1/scheduling/areas */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'franchise_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:191'],
        ]);

        $franchise = Franchise::where('organization_id', $this->tenant->idOrFail())
            ->findOrFail($data['franchise_id']);

        $this->authorize('create', [ServiceArea::class, $franchise]);

        $taken = ServiceArea::where('franchise_id', $franchise->id)
            ->where('name', $data['name'])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "This franchise already has a service area called {$data['name']}.",
            ]);
        }

        // A branch seeded with one catch-all (scheduling:seed-company-availability)
        // gets its first real area BY NAMING the catch-all: its capacity and
        // jobs stay, and no fake "All areas" row sits beside the real one
        // (Jose, 2026-10-28, A1 Garage Doors).
        $areas = ServiceArea::where('franchise_id', $franchise->id)->get();
        $catchAll = $areas->count() === 1 && $areas[0]->covers_all_zips && $areas[0]->name === SchedulingSeedCompanyAvailability::NAME && ! $areas[0]->zips()->exists() ? $areas[0] : null;
        if ($catchAll !== null) {
            $catchAll->update(['name' => $data['name']]);
            $area = $catchAll;
        } else {
            $area = ServiceArea::create([
                'franchise_id' => $franchise->id,
                'name' => $data['name'],
            ]);
        }

        return response()->json(['data' => $this->detail($area->fresh(['slots', 'zips']))], 201);
    }

    /** GET /api/v1/scheduling/areas/{area} */
    public function show(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->with(['slots', 'zips'])->findOrFail($area);

        $this->authorize('view', $row);

        return response()->json(['data' => $this->detail($row)]);
    }

    /** PATCH /api/v1/scheduling/areas/{area} */
    public function update(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);

        $this->authorize('update', $row);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            // Its own map colour (Jose, 2026-10-27); null goes back to the default.
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // "Recommended area": a centre ZIP and a radius (Jose, 2026-10-27).
            'center_zip' => ['sometimes', 'nullable', 'regex:/^\d{5}$/'],
            'radius_miles' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $data['name'] ??= $row->name;

        $taken = ServiceArea::where('franchise_id', $row->franchise_id)
            ->where('name', $data['name'])
            ->whereKeyNot($row->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "This franchise already has a service area called {$data['name']}.",
            ]);
        }

        $row->update($data);

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /**
     * DELETE /api/v1/scheduling/areas/{area}[?move_zips_to={area}]
     *
     * Its ZIPs go with it unless `move_zips_to` names another area this person
     * can edit; the answer says which happened (Jose, 2026-10-21: removed
     * areas' zip codes "don't move over").
     */
    public function destroy(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);

        $this->authorize('delete', $row);

        $to = null;
        if ($request->filled('move_zips_to')) {
            $to = $this->reachable($request)->whereKeyNot($row->id)->findOrFail((string) $request->input('move_zips_to'));
            // ZIPs are a company's own; they move only within it (2026-10-28).
            abort_unless(Franchise::query()->whereKey($to->franchise_id)->value('company_id')
                === Franchise::query()->whereKey($row->franchise_id)->value('company_id'), 422, 'ZIPs can move only to an area of the same company.');
            $this->authorize('update', $to);
        }

        $result = DB::transaction(function () use ($row, $to) {
            $count = ServiceAreaZip::where('service_area_id', $row->id)->count();
            if ($to === null) {
                ServiceAreaZipRelease::release(ServiceAreaZip::where('service_area_id', $row->id)->pluck('zip'),
                    Franchise::query()->whereKey($row->franchise_id)->value('company_id'));
            } else {
                ServiceAreaZip::where('service_area_id', $row->id)
                    ->update(['service_area_id' => $to->id, 'franchise_id' => $to->franchise_id]);
            }
            $row->delete();

            return $to === null
                ? ['moved' => 0, 'unassigned' => $count, 'moved_to' => null]
                : ['moved' => $count, 'unassigned' => 0, 'moved_to' => ['id' => $to->id, 'name' => $to->name]];
        });

        return response()->json(['data' => $result]);
    }

    /**
     * PUT /api/v1/scheduling/areas/{area}/slots
     *
     * Replaces the whole grid. A weekday with no slots in the payload is a
     * CLOSED day — the rows are deleted rather than zeroed, which is what
     * keeps "we do not work Sundays" distinguishable from "we work Sundays
     * and are full". A zero here is refused for the same reason: it is the
     * one write that would collapse the two.
     */
    public function slots(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);

        $this->authorize('update', $row);

        $data = $request->validate([
            'slots' => ['present', 'array'],
            'slots.*.weekday' => ['required', 'integer', 'between:0,6'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
            'slots.*.max_jobs' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        $this->assertSlotsAreSane($data['slots']);

        DB::transaction(function () use ($row, $data) {
            ServiceAreaSlot::where('service_area_id', $row->id)->delete();
            // Saved, even with every day closed: see Openings::hasCapacity.
            $row->forceFill(['capacity_set_at' => now()])->save();

            foreach ($data['slots'] as $slot) {
                ServiceAreaSlot::create([
                    'service_area_id' => $row->id,
                    'weekday' => $slot['weekday'],
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'max_jobs' => $slot['max_jobs'],
                ]);
            }
        });

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /**
     * PUT /api/v1/scheduling/areas/{area}/dates/{date}
     *
     * One specific date, replacing the weekly pattern for that day: either
     * `closed: true` (the whole day), or the windows that day with how many
     * jobs each takes. Replaces whatever that date had. A window is never sent
     * with a zero — leaving it out is how a date does not offer it, the same
     * rule as the weekly pattern.
     */
    public function setDate(Request $request, string $area, string $date): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);
        $day = $this->day($date);

        $data = $request->validate([
            'closed' => ['sometimes', 'boolean'],
            'windows' => ['required_unless:closed,true', 'array'],
            'windows.*.start_time' => ['required', 'date_format:H:i'],
            'windows.*.end_time' => ['required', 'date_format:H:i'],
            'windows.*.max_jobs' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        $closed = (bool) ($data['closed'] ?? false);
        $windows = $closed ? [] : $data['windows'];

        if (! $closed && $windows === []) {
            throw ValidationException::withMessages([
                'windows' => 'Give that date at least one window, or mark it closed.',
            ]);
        }

        foreach ($windows as $w) {
            if ($w['end_time'] <= $w['start_time']) {
                throw ValidationException::withMessages([
                    'windows' => 'A window has to end after it starts.',
                ]);
            }
        }

        DB::transaction(function () use ($row, $day, $closed, $windows) {
            ServiceAreaDateOverride::where('service_area_id', $row->id)->where('date', $day)->delete();

            if ($closed) {
                ServiceAreaDateOverride::create(['service_area_id' => $row->id, 'date' => $day]);

                return;
            }

            foreach ($windows as $w) {
                ServiceAreaDateOverride::create([
                    'service_area_id' => $row->id,
                    'date' => $day,
                    'start_time' => $w['start_time'],
                    'end_time' => $w['end_time'],
                    'max_jobs' => $w['max_jobs'],
                ]);
            }
        });

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /** DELETE /api/v1/scheduling/areas/{area}/dates/{date} — back to the weekly pattern. */
    public function clearDate(Request $request, string $area, string $date): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);

        ServiceAreaDateOverride::where('service_area_id', $row->id)->where('date', $this->day($date))->delete();

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /**
     * PUT /api/v1/scheduling/areas/{area}/available-from
     *
     * "We are full until the 7th." Every day before the date is closed; null
     * clears it.
     */
    public function latestWindows(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);
        // Weekday => latest window start; null follows the branch setting (2026-11-17).
        $data = $request->validate([
            'latest_windows' => ['present', 'nullable', 'array'],
            'latest_windows.*' => ['date_format:H:i'],
        ]);
        $row->update(['latest_windows' => $data['latest_windows']]);

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    public function availableFrom(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);

        $data = $request->validate(['available_from' => ['present', 'nullable', 'date_format:Y-m-d']]);

        $row->update(['available_from' => $data['available_from']]);

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /**
     * PUT /api/v1/scheduling/areas/{area}/shares-with {shares_with_id: uuid|null}
     *
     * Books this area into another area's availability pool (Eve, 2026-09-29:
     * "give 2 service areas, the same availability pool"); null gives it its
     * own week back. Same franchise only, one level only: the lead does not
     * share, and an area others share into cannot share itself.
     */
    public function sharesWith(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);

        $data = $request->validate(['shares_with_id' => ['present', 'nullable', 'uuid']]);
        $leadId = $data['shares_with_id'];

        if ($leadId !== null) {
            $lead = $this->reachable($request)->find($leadId);

            $refusal = match (true) {
                $lead === null || $lead->franchise_id !== $row->franchise_id => 'Pick an area of the same franchise.',
                $lead->id === $row->id => 'An area cannot share with itself.',
                $lead->shares_with_id !== null => "{$lead->name} already shares another area's availability. Pick that area instead.",
                ServiceArea::query()->where('shares_with_id', $row->id)->exists() => "Other areas share {$row->name}'s availability. Stop them sharing first.",
                default => null,
            };

            if ($refusal !== null) {
                throw ValidationException::withMessages(['shares_with_id' => $refusal]);
            }
        }

        $row->update(['shares_with_id' => $leadId]);

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /**
     * PUT /api/v1/scheduling/areas/{area}/scheduled/{date} {slots: [...]}
     *
     * A weekly pattern that takes over from {date} (Eve, 2026-09-29: "change
     * automatically starting from October 30, going forward"). Replaces any
     * version already scheduled for that date. The date must be tomorrow or
     * later in the organization's clock: today's pattern is edited directly.
     * The slots are validated exactly like the weekly pattern's.
     */
    public function scheduleChange(Request $request, string $area, string $date): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);
        $day = $this->day($date);

        $tomorrow = Openings::tomorrow((string) app(SettingsResolver::class)
            ->get('timezone', $request->user()->organization))->toDateString();
        if ($day < $tomorrow) {
            throw ValidationException::withMessages([
                'date' => 'A scheduled change starts tomorrow or later. Change the current week directly.',
            ]);
        }

        $data = $request->validate([
            'slots' => ['required', 'array', 'min:1'],
            'slots.*.weekday' => ['required', 'integer', 'between:0,6'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
            'slots.*.max_jobs' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        $this->assertSlotsAreSane($data['slots']);

        DB::transaction(function () use ($row, $day, $data) {
            ServiceAreaScheduledSlot::where('service_area_id', $row->id)->where('starts_on', $day)->delete();

            foreach ($data['slots'] as $slot) {
                ServiceAreaScheduledSlot::create([
                    'service_area_id' => $row->id,
                    'starts_on' => $day,
                    'weekday' => $slot['weekday'],
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'max_jobs' => $slot['max_jobs'],
                ]);
            }
        });

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /** DELETE /api/v1/scheduling/areas/{area}/scheduled/{date} */
    public function cancelChange(Request $request, string $area, string $date): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);

        ServiceAreaScheduledSlot::where('service_area_id', $row->id)->where('starts_on', $this->day($date))->delete();

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    private function day(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        abort_if($parsed === false || $parsed->format('Y-m-d') !== $date, 422, 'Dates are YYYY-MM-DD.');

        return $date;
    }

    /**
     * PUT /api/v1/scheduling/areas/{area}/zips
     *
     * Replaces the set. A zip another area in this ORGANIZATION already holds
     * is refused BY NAME rather than silently moved — the unique index would
     * refuse it anyway, but as a 500 that tells nobody which area to go and
     * look at.
     *
     * Organization-wide since 2026-09-25, not per franchise: a zip on two
     * branches' areas gave a search two answers (see the migration
     * `one_zip_one_area_per_organization`). The clashing area may belong to a
     * branch this person cannot open, and it is still named — it is the same
     * company, and a refusal that will not say where the zip lives leaves them
     * nothing to do but ring round asking.
     */
    public function zips(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);

        $this->authorize('update', $row);

        $data = $request->validate([
            'zips' => ['present', 'array'],
            // Two accepted shapes on purpose. An entry is either a bare zip
            // string or `{zip, city}` — the desktop build already shipped
            // sending strings, and a client older than this deploy must not
            // start failing the moment the server learns about cities.
            'zips.*' => ['required'],
            'zips.*.zip' => ['sometimes', 'string', 'max:16'],
            'zips.*.city' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $wanted = $this->readZips($data['zips']);

        $company = Franchise::query()->whereKey($row->franchise_id)->value('company_id');
        $clash = ServiceAreaZip::query()
            ->where('company_id', $company)
            ->whereIn('zip', $wanted->keys())
            ->where('service_area_id', '!=', $row->id)
            ->with('serviceArea')
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'zips' => "{$clash->zip} is already in {$clash->serviceArea?->name}. "
                    .'A zip belongs to one service area, so that a search by zip has one answer.',
            ]);
        }

        DB::transaction(function () use ($row, $wanted, $company) {
            $before = ServiceAreaZip::where('service_area_id', $row->id)->pluck('zip');
            ServiceAreaZipRelease::release($before->diff($wanted->keys()), $company);
            ServiceAreaZipRelease::claim($wanted->keys(), $company);
            ServiceAreaZip::where('service_area_id', $row->id)->delete();

            foreach ($wanted as $zip => $city) {
                ServiceAreaZip::create([
                    'franchise_id' => $row->franchise_id,
                    'service_area_id' => $row->id,
                    'zip' => (string) $zip,
                    'city' => $city,
                ]);
            }
        });

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    /**
     * The posted zip list as `zip => city|null`, keyed so a duplicate zip in
     * one payload collapses rather than hitting the unique index.
     *
     * Accepts a bare string or `{zip, city}` per entry — see the validation
     * above for why both.
     *
     * @param  array<int, mixed>  $entries
     * @return Collection<string, string|null>
     */
    private function readZips(array $entries): Collection
    {
        $out = collect();

        foreach ($entries as $entry) {
            $zip = trim((string) (is_array($entry) ? ($entry['zip'] ?? '') : $entry));

            if ($zip === '') {
                continue;
            }

            $this->assertLooksLikeAZip($zip);

            $city = is_array($entry) ? trim((string) ($entry['city'] ?? '')) : '';

            // Last one wins, so a re-send that adds a city to a zip already
            // stored without one updates it rather than being ignored.
            $out[$zip] = $city === '' ? null : $city;
        }

        return $out;
    }

    /**
     * A postal code, and not a fragment of something else.
     *
     * ── The corruption this closes ───────────────────────────────────
     *
     * `zips` used to be checked for nothing but `max:16`, which let ANY short
     * string be stored as a zip. That was harmless while the only client sent
     * zips — and stopped being harmless the moment the read shape changed from
     * `["75201"]` to `[{zip, city}]`, because a client older than that change
     * renders the objects into its text field with `join(', ')`, giving
     *
     *     {zip: 75201, city: Dallas}
     *
     * and then splits THAT on commas and spaces when somebody hits Save. Four
     * strings — `{zip:`, `75201`, `city:`, `Dallas}` — all under sixteen
     * characters, all accepted, and the area's real coverage replaced by
     * fragments of its own serialisation. Nothing would have errored.
     *
     * So the tolerance for bare strings, which exists to keep an older client
     * WRITING successfully, was the very thing that let an older client write
     * rubbish. Both halves are needed: accept the old shape, and refuse a
     * value that cannot be a postal code.
     *
     * Deliberately permissive about FORM — letters, digits, hyphens and single
     * inner spaces, so "SW1A 1AA" and "75201-1234" both pass — and strict
     * about the characters that appear in serialised data rather than on
     * envelopes: braces, colons, commas, quotes.
     */
    private function assertLooksLikeAZip(string $zip): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*( [A-Za-z0-9-]+)?$/', $zip) !== 1
            || mb_strlen($zip) > 16) {
            throw ValidationException::withMessages([
                'zips' => "\"{$zip}\" is not a zip code, so nothing was changed. "
                    .'If a whole list arrived here as one line, the app that sent it '
                    .'is out of date — update it and try again.',
            ]);
        }
    }

    /**
     * Two slots overlapping on the same day is a configuration nobody meant,
     * and it would make a booking ambiguous about which slot it filled.
     * Checked here rather than by a constraint because "overlap" is not
     * something a unique index can express.
     *
     * @param  array<int, array{weekday:int, start_time:string, end_time:string}>  $slots
     */
    private function assertSlotsAreSane(array $slots): void
    {
        foreach ($slots as $i => $slot) {
            if ($slot['end_time'] <= $slot['start_time']) {
                throw ValidationException::withMessages([
                    "slots.{$i}.end_time" => 'A slot has to end after it starts.',
                ]);
            }
        }

        $byDay = [];

        foreach ($slots as $i => $slot) {
            foreach ($byDay[$slot['weekday']] ?? [] as $seen) {
                if ($slot['start_time'] < $seen['end_time'] && $seen['start_time'] < $slot['end_time']) {
                    throw ValidationException::withMessages([
                        "slots.{$i}.start_time" => 'Two slots on the same day overlap. '
                            .'A job booked into the overlap would belong to both.',
                    ]);
                }
            }

            $byDay[$slot['weekday']][] = $slot;
        }
    }

    /**
     * Areas this user can reach, filtered by the franchises in view.
     *
     * `franchiseIds()` and not `companyIds()`: it intersects reach in every
     * arm, and Phone shipped a cross-company leak on exactly that difference.
     */
    private function reachable(Request $request): Builder
    {
        $scope = ViewScope::forUser($request->user());

        return ServiceArea::query()->whereIn('franchise_id', $scope->franchiseIds());
    }

    /** @return array<string, mixed> */
    /**
     * PUT /api/v1/scheduling/areas/{area}/limits {limits: {"0".."6": int|null}}
     *
     * The area's own daily limit per weekday (Jose, 2026-09-28). Blank is no
     * limit; a zero is refused — a day the area does not work is closed by its
     * time slots, not by a limit of nothing.
     */
    public function limits(Request $request, string $area): JsonResponse
    {
        $row = $this->reachable($request)->findOrFail($area);
        $this->authorize('update', $row);

        $data = $request->validate([
            'limits' => ['present', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $limits = [];
        foreach ($data['limits'] as $weekday => $max) {
            abort_unless(in_array((string) $weekday, ['0', '1', '2', '3', '4', '5', '6'], true), 422, 'A weekday is 0 (Sunday) to 6.');
            if ($max !== null) {
                $limits[(int) $weekday] = (int) $max;
            }
        }

        DB::transaction(function () use ($row, $limits) {
            ServiceAreaDailyLimit::where('service_area_id', $row->id)->delete();
            foreach ($limits as $weekday => $max) {
                ServiceAreaDailyLimit::create(['service_area_id' => $row->id, 'weekday' => $weekday, 'max_jobs' => $max]);
            }
        });

        return response()->json(['data' => $this->detail($row->fresh(['slots', 'zips']))]);
    }

    private function detail(ServiceArea $area): array
    {
        return [
            'id' => $area->id,
            'name' => $area->name,
            'color' => $area->color,
            'center_zip' => $area->center_zip,
            'radius_miles' => $area->radius_miles,
            'franchise_id' => $area->franchise_id,
            'available_from' => $area->available_from?->toDateString(),
            'latest_windows' => $area->latest_windows === null ? null : (object) $area->latest_windows,
            'shares_with_id' => $area->shares_with_id,
            // weekday => jobs; absent is no limit. An object, not a list.
            'daily_limits' => (object) ServiceAreaDailyLimit::query()
                ->where('service_area_id', $area->id)
                ->pluck('max_jobs', 'weekday')
                ->all(),
            // Upcoming specific dates only — a past one changes nothing, and
            // the editor lists what is still ahead.
            'dates' => ServiceAreaDateOverride::query()
                ->where('service_area_id', $area->id)
                ->where('date', '>=', now()->toDateString())
                ->orderBy('date')->orderBy('start_time')
                ->get()
                ->groupBy(fn (ServiceAreaDateOverride $o) => $o->date->toDateString())
                ->map(fn ($rows, $date) => [
                    'date' => $date,
                    'closed' => $rows->contains(fn ($o) => $o->start_time === null),
                    'windows' => $rows->whereNotNull('start_time')->map(fn ($o) => [
                        'start_time' => substr((string) $o->start_time, 0, 5),
                        'end_time' => substr((string) $o->end_time, 0, 5),
                        'max_jobs' => $o->max_jobs,
                    ])->values()->all(),
                ])->values()->all(),
            // Weekly patterns scheduled to take over, soonest first.
            'scheduled' => ServiceAreaScheduledSlot::query()
                ->where('service_area_id', $area->id)
                ->orderBy('starts_on')->orderBy('weekday')->orderBy('start_time')
                ->get()
                ->groupBy(fn (ServiceAreaScheduledSlot $s) => $s->starts_on->toDateString())
                ->map(fn ($rows, $startsOn) => [
                    'starts_on' => $startsOn,
                    'slots' => $rows->map(fn (ServiceAreaScheduledSlot $s) => [
                        'weekday' => $s->weekday,
                        'start_time' => substr((string) $s->start_time, 0, 5),
                        'end_time' => substr((string) $s->end_time, 0, 5),
                        'max_jobs' => $s->max_jobs,
                    ])->values()->all(),
                ])->values()->all(),
            'zips' => $area->zips
                ->sortBy('zip')
                ->map(fn (ServiceAreaZip $z) => ['zip' => $z->zip, 'city' => $z->city])
                ->values()
                ->all(),
            'slots' => $area->slots
                ->sortBy([['weekday', 'asc'], ['start_time', 'asc']])
                ->map(fn (ServiceAreaSlot $s) => [
                    'id' => $s->id,
                    'weekday' => $s->weekday,
                    'start_time' => substr((string) $s->start_time, 0, 5),
                    'end_time' => substr((string) $s->end_time, 0, 5),
                    'max_jobs' => $s->max_jobs,
                ])->values()->all(),
        ];
    }
}
