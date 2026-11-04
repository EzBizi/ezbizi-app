<?php

namespace App\Support\Scheduling;

use App\Models\FranchiseDailyLimit;
use App\Models\ServiceArea;
use App\Models\ServiceAreaDailyLimit;
use App\Models\ServiceAreaDateOverride;
use App\Models\ServiceAreaScheduledSlot;
use App\Models\ServiceAreaSlot;
use App\Models\WebBooking;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a service area can still take, window by window, day by day.
 *
 * THE ONE PLACE "taken" IS COMPUTED. A1 Tools learned this at cost: on
 * 2026-09-16 Connecticut showed free spots on a Friday booked 13 against 8,
 * because the rule lived in four places and the one the technician checker
 * used had drifted (A1 d5054fbc). Anything in EzBizi that needs "how full is
 * this area" — the Availability search, a Reports column, a booking gate —
 * calls this and nothing else.
 *
 * ── What counts as a booked seat ────────────────────────────────
 *
 * A job booked into the area on that day whose time falls in the window,
 * half-open [start, end): a job at exactly 11:00 is in 11–2, not 8–11. 11:00
 * and 14:00 are A-1's two most common start times, so that boundary carries
 * real weight.
 *
 * A seat is taken by a BOOKING, not an assignment — a job nobody has been sent
 * to yet still fills the slot (A1 once counted only accepted jobs and hid 63 of
 * Connecticut's 68 commitments).
 *
 * Statuses in scheduling.free_statuses hold no seat. Every other status DOES, including
 * one nobody has classified: an unknown status constrains booking rather than
 * freeing it, which errs toward protecting the schedule.
 *
 * Jobs tagged with a scheduling.excluded_tags tag hold no seat. A-1's New Jersey read
 * 31 of 30 on a Monday because three were DEPOSIT RECEIVED jobs, which a
 * different crew does. Tags are typed by hand, so both spellings are live and
 * matching is trimmed and case-folded.
 *
 * A job whose time falls in NO window — 00:00 means "no time set" in Workiz —
 * holds no window's seat, and is counted separately as `no_time` so the screen
 * can say so rather than silently dropping it. A1 has 24 such future jobs. It
 * DOES count against the day: the area takes what its windows add up to, and
 * every job booked that day spends it (see the day ceiling in days()).
 *
 * ── Where the jobs come from ───────────────────────────────────
 *
 * `jobs.service_area_id`: EzBizi's own record of which area a job is in. The
 * Workiz projection places every job it creates (Workiz's area name, else the
 * job's zip) and re-places it when Workiz changes either, unless a person
 * chose the area. Status and time come from the job; the tags that exclude a
 * job come from its Workiz payload, joined through `workiz_jobs.job_id`.
 *
 * PLUS mirrored Workiz jobs the projection has not reached yet
 * (`workiz_jobs.job_id` NULL), matched by Workiz's area name inside the area's
 * franchise, the way this counted before. The projection runs every five
 * minutes after the sweep; a booking made in that gap still holds its seat.
 * Measured at the switch, 2026-09-25: three such rows, all under 15 minutes
 * old. Dropping them would undercount, which is the optimistic direction, the
 * one A1's silent zero taught us nobody notices.
 *
 * Measured at the switch over the next 30 days: 614 seats by name, 615 by
 * area. Five areas were off by one: Austin and DFW gained a job the zip placed
 * (Workiz named no area / the retired "Fort Worth"); Indiana gained one whose
 * EzBizi status still says Pending while Workiz says Canceled; the New York
 * and Connecticut rows were two of the unprojected jobs above.
 *
 * PLUS appointments asked for on a website's booking widget (Jose,
 * 2026-10-08), pending or approved, until the lead has a job of its own: the
 * job holds the seat from then on, so the two are never counted together.
 * That hand-off rides `leads.job_id`, which the phone match sets. When it
 * misses (a second number, somebody booking for someone else) the booking and
 * the Workiz job both count until the booking's date passes: safe, showing
 * less room, never more (Scheduling, 2026-10-08). Leads do not soft-delete.
 *
 * ── The franchise's daily limit ─────────────────────────────────
 *
 * On top of each area's own capacity, a franchise can cap the whole day
 * (`franchise_daily_limits`, per weekday): its areas share technicians, so
 * DMV's three areas adding up to 19 does not mean DMV can do 19 (Jose,
 * 2026-09-25). Every seat-holding job of the franchise that day counts
 * against it — any area, no area, no time — by the same rules as a seat.
 * No window offers more than the franchise has left, so at the limit the day
 * is full in every one of its areas. It never raises an area's own numbers.
 *
 * The free statuses and excluded tags are org settings (scheduling.free_statuses,
 * scheduling.excluded_tags, 2026-11-04), POPULATED by default: an empty list
 * would make every cancelled job hold a seat (see the lessons spec).
 */
final class Openings
{
    /** @return list<string> lower-cased, trimmed: settings scheduling.free_statuses (2026-11-04) */
    public static function freeStatuses(): array
    {
        return self::listSetting('scheduling.free_statuses');
    }

    /** @return list<string> lower-cased, trimmed: settings scheduling.excluded_tags */
    public static function excludedTags(): array
    {
        return self::listSetting('scheduling.excluded_tags');
    }

    /** @return list<string> */
    private static function listSetting(string $key): array
    {
        $org = app(TenantContext::class)->get();
        $rows = $org === null ? app(SettingRegistry::class)->getOrFail($key)->default : app(SettingsResolver::class)->get($key, $org);

        return array_values(array_map(fn ($r) => mb_strtolower(trim((string) (is_array($r) ? ($r['value'] ?? '') : $r))), (array) $rows));
    }

    /** How far forward the search walks before saying it found nothing. */
    public const HORIZON_DAYS = 365;

    /**
     * Every day from $from (inclusive) for $days days, each with the area's
     * windows that day and how full they are. A day the area does not work
     * has no windows — CLOSED, which is different from a day that is full.
     *
     * @return array<int, array{date: string, windows: array<int, array<string, mixed>>, no_time: int}>
     */
    public function days(ServiceArea $area, CarbonImmutable $from, int $days): array
    {
        // A shared pool (Eve, 2026-09-29): an area that shares reads its lead's
        // week, dates and limits, and taken() counts every area of the pool.
        // So a member and its lead answer the same days.
        if ($area->shares_with_id !== null) {
            $area = ServiceArea::find($area->shares_with_id) ?? $area;
        }

        $slots = ServiceAreaSlot::query()
            ->where('service_area_id', $area->id)
            ->orderBy('start_time')
            ->get()
            ->groupBy('weekday');

        // Weekly patterns scheduled to take over from a date (Eve, 2026-09-29).
        // Each day uses the latest one that has started by then, or the
        // area's own pattern when none has. On its start date a version is
        // folded into service_area_slots by scheduling:apply-scheduled-changes,
        // so this only ever sees versions still ahead of that sweep.
        $versions = ServiceAreaScheduledSlot::query()
            ->where('service_area_id', $area->id)
            ->where('starts_on', '<', $from->addDays($days)->toDateString())
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn (ServiceAreaScheduledSlot $s) => $s->starts_on->toDateString())
            ->map(fn ($rows) => $rows->groupBy('weekday'))
            ->sortKeys();

        $taken = $this->taken($area, $from, $from->addDays($days));

        // Specific dates replace the weekly pattern outright; a row with no
        // window closes the whole day. See the migration
        // `add_date_overrides_and_horizon_to_service_areas`.
        $overrides = ServiceAreaDateOverride::query()
            ->where('service_area_id', $area->id)
            ->whereBetween('date', [$from->toDateString(), $from->addDays($days)->toDateString()])
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn (ServiceAreaDateOverride $o) => $o->date->toDateString());

        // Every day before this is CLOSED, not full. NULL is no floor.
        $floor = $area->available_from?->toDateString();

        $limits = FranchiseDailyLimit::query()
            ->where('franchise_id', $area->franchise_id)
            ->pluck('max_jobs', 'weekday');
        // The area's own daily limit: lowers its day below what its windows
        // add up to, never raises it.
        $areaLimits = ServiceAreaDailyLimit::query()
            ->where('service_area_id', $area->id)
            ->pluck('max_jobs', 'weekday');
        $franchiseBooked = $limits->isEmpty()
            ? []
            : $this->franchiseBooked($area, $from, $from->addDays($days));

        $out = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->addDays($i);
            $key = $date->toDateString();
            $booked = $taken[$key] ?? ['times' => [], 'no_time' => 0];

            $windows = [];
            $placed = 0;

            $limit = $limits[$date->dayOfWeek] ?? null;
            $franchiseLeft = $limit === null ? null : max(0, $limit - ($franchiseBooked[$key] ?? 0));

            $pattern = match (true) {
                $floor !== null && $key < $floor => collect(),
                $overrides->has($key) => $overrides[$key]->contains(fn ($o) => $o->start_time === null)
                    ? collect()
                    : $overrides[$key],
                default => $this->versionOn($versions, $key, $slots)->get($date->dayOfWeek, collect()),
            };

            // Why a day offers nothing, so Availability can say CLOSED rather
            // than skip it (Mia, 2026-10-02): before the area opens, a date
            // closed on its own (a holiday), or a weekday the week leaves shut.
            $closed = $pattern->isNotEmpty() ? null : match (true) {
                $floor !== null && $key < $floor => 'not_yet',
                $overrides->has($key) => 'date',
                default => 'weekly',
            };

            foreach ($pattern as $slot) {
                $start = substr((string) $slot->start_time, 0, 5);
                $end = substr((string) $slot->end_time, 0, 5);
                $in = count(array_filter($booked['times'], fn ($t) => $t >= $start && $t < $end));
                $placed += $in;

                $windows[] = [
                    'start_time' => $start,
                    'end_time' => $end,
                    'capacity' => (int) $slot->max_jobs,
                    'taken' => $in,
                    // Never negative: an over-booked window reports 0 left,
                    // and taken > capacity is what shows it is over.
                    'remaining' => max(0, (int) $slot->max_jobs - $in),
                ];
            }

            // THE DAY IS A CEILING TOO (A1 Tools' B3, the lesson it rates
            // most important). The area takes what its windows add up to, and
            // every job booked that day counts against it: one piled into a
            // window past its limit, and one with no time, which holds no
            // window's seat but still takes a crew. Cincinnati, 2026-09-30:
            // 6 of 6 booked, one window over by one, and a window still offered
            // a spot the day did not have.
            //
            // What is left — the area's day, and the franchise's when it has a
            // limit — is handed to the windows EARLIEST FIRST, so no two
            // windows advertise the same last spot. A1 distributes its day
            // headroom the same way.
            $dayCap = array_sum(array_column($windows, 'capacity'));
            if (isset($areaLimits[$date->dayOfWeek])) {
                $dayCap = min($dayCap, (int) $areaLimits[$date->dayOfWeek]);
            }
            $left = max(0, $dayCap - count($booked['times']) - $booked['no_time']);
            if ($franchiseLeft !== null) {
                $left = min($left, $franchiseLeft);
            }
            foreach ($windows as &$window) {
                $window['remaining'] = min($window['remaining'], $left);
                $left -= $window['remaining'];
            }
            unset($window);

            $out[] = [
                'date' => $key,
                'windows' => $windows,
                // Jobs that day in no window: no time set, or a time outside
                // every window.
                'no_time' => $booked['no_time'] + (count($booked['times']) - $placed),
                // The franchise's cap that day and how much of it is spoken
                // for; both null without a limit.
                'franchise_limit' => $limit,
                'area_limit' => isset($areaLimits[$date->dayOfWeek]) ? (int) $areaLimits[$date->dayOfWeek] : null,
                'franchise_booked' => $limit === null ? null : ($franchiseBooked[$key] ?? 0),
                'closed' => $closed,
            ];
        }

        return $out;
    }

    /**
     * The first day with a window that has room, and the next few after it.
     *
     * Walks forward from $from, up to a year. A1 capped this at three weeks and
     * answered "fully booked" past that; Jose: that is not an answer a
     * dispatcher can give a customer. A window counts as open only when it has
     * room — an unconfigured area offers nothing rather than everything.
     *
     * @return array{first: array<string, mixed>|null, after: array<int, array<string, mixed>>}
     */
    public function next(ServiceArea $area, CarbonImmutable $from, int $after = 5): array
    {
        $days = $this->days($area, $from, self::HORIZON_DAYS);

        $open = array_values(array_filter(
            $days,
            fn (array $day) => collect($day['windows'])->contains(fn ($w) => $w['remaining'] > 0),
        ));

        $shown = array_slice($open, 0, $after + 1);
        $last = $shown === [] ? null : end($shown)['date'];

        return [
            'first' => $open[0] ?? null,
            'after' => array_slice($open, 1, $after),
            /*
             * Dates closed on their own (a holiday) and days before the area
             * opens, up to the last day shown, so they read "Closed" in place
             * instead of silently missing (Mia, 2026-10-02). A weekday the
             * week always leaves shut is not listed: an area open only on
             * Mondays would otherwise list six closed days between each one.
             */
            'closed' => $last === null ? [] : array_values(array_map(
                fn (array $day) => ['date' => $day['date'], 'reason' => $day['closed']],
                array_filter(
                    $days,
                    fn (array $day) => in_array($day['closed'], ['date', 'not_yet'], true) && $day['date'] < $last,
                ),
            )),
            // Why there is no first day: every day in a year is closed, or
            // there are openings and every one is taken.
            'none_because' => $open !== [] ? null
                : (collect($days)->every(fn (array $day) => $day['closed'] !== null) ? 'closed' : 'full'),
        ];
    }

    /**
     * Booked job times per day for the area, seat-holding jobs only.
     *
     * @return array<string, array{times: string[], no_time: int}>
     */
    private function taken(ServiceArea $area, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDay = [];

        foreach ($this->seats($this->areaRows($area, $from, $to)) as $job) {
            $at = (string) $job->scheduled_for;
            $day = substr($at, 0, 10);
            $time = substr($at, 11, 5);

            $byDay[$day] ??= ['times' => [], 'no_time' => 0];

            // Midnight is Workiz's "no time set", not a real 12 AM job.
            if ($time === '00:00') {
                $byDay[$day]['no_time']++;
            } else {
                $byDay[$day]['times'][] = $time;
            }
        }

        return $byDay;
    }

    /**
     * The jobs holding a seat in the area's pool on one day: the very rows
     * days() counts, so a list of them can never disagree with the number
     * (Scheduling › Overbooking, opened on a row). A member reads its lead's.
     *
     * @return Collection<int, object> kind (job|workiz|booking), job_id,
     *                                 customer_id, remote_id, lead_id, scheduled_for, status, payload,
     *                                 address, city, postal_code, contact_name, phone, email, end_time
     */
    public function jobsOn(ServiceArea $area, CarbonImmutable $date): Collection
    {
        if ($area->shares_with_id !== null) {
            $area = ServiceArea::find($area->shares_with_id) ?? $area;
        }

        return $this->seats($this->areaRows($area, $date, $date->addDay()))->values();
    }

    /** The jobs holding a seat in the area's pool from $from up to $to (NearbySuggestion). */
    public function jobsBetween(ServiceArea $area, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($area->shares_with_id !== null) {
            $area = ServiceArea::find($area->shares_with_id) ?? $area;
        }

        return $this->seats($this->areaRows($area, $from, $to))->values();
    }

    /** The jobs holding a seat in a whole franchise on one day, as franchiseBooked() counts them. */
    public function franchiseJobsOn(string $organizationId, string $franchiseId, CarbonImmutable $date): Collection
    {
        return $this->seats($this->franchiseRows($organizationId, $franchiseId, $date, $date->addDay()))->values();
    }

    /** Every booking in the area's pool between two dates, seat-holding or not. */
    private function areaRows(ServiceArea $area, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $start = $from->toDateString().' 00:00:00';
        $end = $to->toDateString().' 00:00:00';

        // The pool: the area and every area sharing its availability.
        $pool = ServiceArea::query()->where('shares_with_id', $area->id)->get()->push($area);
        $names = $pool->map(fn (ServiceArea $a) => mb_strtolower(trim($a->name)))->unique()->values()->all();

        $placed = DB::table('jobs')
            ->leftJoin('workiz_jobs', 'workiz_jobs.job_id', '=', 'jobs.id')
            ->whereIn('jobs.service_area_id', $pool->pluck('id'))
            ->whereNull('jobs.deleted_at')
            ->where('jobs.scheduled_for', '>=', $start)
            ->where('jobs.scheduled_for', '<', $end)
            ->get(self::JOB_COLUMNS);

        // Mirrored, not projected yet — see "Where the jobs come from".
        $pending = DB::table('workiz_jobs')
            ->whereNull('job_id')
            ->where('organization_id', $area->organization_id)
            ->where('franchise_id', $area->franchise_id)
            ->whereIn(DB::raw('LOWER(TRIM(service_area))'), $names)
            ->where('scheduled_for', '>=', $start)
            ->where('scheduled_for', '<', $end)
            ->get(self::MIRROR_COLUMNS);

        $online = $this->bookings()
            ->whereIn('web_bookings.service_area_id', $pool->pluck('id'))
            ->where('web_bookings.date', '>=', $from->toDateString())
            ->where('web_bookings.date', '<', $to->toDateString())
            ->get();

        return $this->kinds($placed, $pending, $online);
    }

    /** What a placed job row carries, for counting and for showing it. */
    private const JOB_COLUMNS = [
        'jobs.id as job_id', 'workiz_jobs.id as workiz_job_id', 'jobs.customer_id', 'workiz_jobs.remote_id', 'jobs.scheduled_for', 'jobs.status',
        'workiz_jobs.payload', 'workiz_jobs.address', 'workiz_jobs.city', 'workiz_jobs.postal_code',
        'workiz_jobs.latitude', 'workiz_jobs.longitude',
    ];

    /** What a mirrored, not yet projected Workiz row carries. */
    private const MIRROR_COLUMNS = ['id as workiz_job_id', 'remote_id', 'scheduled_for', 'status', 'payload', 'address', 'city', 'postal_code', 'latitude', 'longitude'];

    private function kinds(Collection $placed, Collection $pending, Collection $online): Collection
    {
        $tag = fn (string $kind) => fn (object $row) => tap($row, fn ($r) => $r->kind = $kind);

        return $placed->map($tag('job'))->concat($pending->map($tag('workiz')))->concat($online->map($tag('booking')));
    }

    /**
     * Seat-holding jobs of the area's whole FRANCHISE per day, for its daily
     * limit: EzBizi's jobs of that franchise, placed in an area or not, plus
     * mirrored Workiz jobs not projected yet. Midnight counts: a job with no
     * time still takes a technician that day.
     *
     * @return array<string, int>
     */
    private function franchiseBooked(ServiceArea $area, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->seats($this->franchiseRows($area->organization_id, $area->franchise_id, $from, $to))
            ->countBy(fn ($job) => substr((string) $job->scheduled_for, 0, 10))
            ->all();
    }

    private function franchiseRows(string $organizationId, string $franchiseId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $start = $from->toDateString().' 00:00:00';
        $end = $to->toDateString().' 00:00:00';

        return $this->kinds(
            DB::table('jobs')
                ->leftJoin('workiz_jobs', 'workiz_jobs.job_id', '=', 'jobs.id')
                ->where('jobs.organization_id', $organizationId)
                ->where('jobs.franchise_id', $franchiseId)
                ->whereNull('jobs.deleted_at')
                ->where('jobs.scheduled_for', '>=', $start)
                ->where('jobs.scheduled_for', '<', $end)
                ->get(self::JOB_COLUMNS),
            DB::table('workiz_jobs')
                ->whereNull('job_id')
                ->where('organization_id', $organizationId)
                ->where('franchise_id', $franchiseId)
                ->where('scheduled_for', '>=', $start)
                ->where('scheduled_for', '<', $end)
                ->get(self::MIRROR_COLUMNS),
            $this->bookings()
                ->join('service_areas', 'service_areas.id', '=', 'web_bookings.service_area_id')
                ->where('service_areas.franchise_id', $franchiseId)
                ->where('web_bookings.date', '>=', $from->toDateString())
                ->where('web_bookings.date', '<', $to->toDateString())
                ->get(),
        );
    }

    /** Website bookings still holding a seat, shaped like a job row. */
    private function bookings(): Builder
    {
        return DB::table('web_bookings')
            ->join('leads', 'leads.id', '=', 'web_bookings.lead_id')
            ->whereIn('web_bookings.status', WebBooking::HOLDING)
            ->whereNull('leads.job_id')
            ->selectRaw("CONCAT(web_bookings.date, ' ', web_bookings.start_time) as scheduled_for, 'booking' as status, NULL as payload")
            // Who asked, for Scheduling › Overbooking's list of a day.
            ->addSelect(['web_bookings.lead_id', 'web_bookings.end_time', 'leads.contact_name', 'leads.phone', 'leads.email', 'leads.postal_code']);
    }

    /** The jobs that hold a seat: not a free status, not an excluded tag. */
    private function seats(Collection $jobs): Collection
    {
        $free = self::freeStatuses();
        $tags = self::excludedTags();

        return $jobs->reject(fn ($job) => in_array(mb_strtolower(trim((string) $job->status)), $free, true)
            || $this->excluded($job->payload, $tags));
    }

    /** @param  list<string>  $excluded */
    private function excluded(mixed $payload, array $excluded): bool
    {
        $data = is_string($payload) ? json_decode($payload, true) : (array) $payload;
        $tags = $data['Tags'] ?? [];

        if (! is_array($tags)) {
            $tags = [$tags];
        }

        foreach ($tags as $tag) {
            if (in_array(mb_strtolower(trim((string) $tag)), $excluded, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tomorrow in the organization's own clock. The box runs UTC; A1's bare
     * `new DateTime('tomorrow')` rolled over at 8 PM Eastern and a 9 PM search
     * skipped a whole working day, every evening (A1 2b0ba268). Today is never
     * offered — by the time anybody is on the phone, today is spoken for.
     */
    public static function tomorrow(string $timezone): CarbonImmutable
    {
        try {
            return CarbonImmutable::now($timezone)->startOfDay()->addDay();
        } catch (\Throwable) {
            return CarbonImmutable::now('America/New_York')->startOfDay()->addDay();
        }
    }

    /**
     * The weekly pattern in force on $date: the latest scheduled version that
     * has started, else the area's own.
     */
    private function versionOn(Collection $versions, string $date, Collection $base): Collection
    {
        $current = $base;
        foreach ($versions as $startsOn => $week) {
            if ($startsOn > $date) {
                break;
            }
            $current = $week;
        }

        return $current;
    }

    /** Whether anybody has stated any capacity for this area at all. */
    public static function hasCapacity(ServiceArea $area): bool
    {
        if ($area->shares_with_id !== null) {
            $area = ServiceArea::find($area->shares_with_id) ?? $area;
        }

        // A week SAVED with every day closed is set up - closed, not unknown.
        // Its rows were deleted (a closed day has none), so the save itself is
        // what records it (Mia, 2026-10-02: San Antonio read "not available").
        return $area->capacity_set_at !== null
            || ServiceAreaSlot::query()->where('service_area_id', $area->id)->exists()
            || ServiceAreaScheduledSlot::query()->where('service_area_id', $area->id)->exists()
            || ServiceAreaDateOverride::query()->where('service_area_id', $area->id)
                ->whereNotNull('start_time')->exists();
    }
}
