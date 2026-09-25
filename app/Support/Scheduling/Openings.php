<?php

namespace App\Support\Scheduling;

use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use Carbon\CarbonImmutable;
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
 * Statuses in FREE_STATUSES hold no seat. Every other status DOES, including
 * one nobody has classified: an unknown status constrains booking rather than
 * freeing it, which errs toward protecting the schedule.
 *
 * Jobs tagged with an EXCLUDED_TAGS tag hold no seat. A-1's New Jersey read
 * 31 of 30 on a Monday because three were DEPOSIT RECEIVED jobs, which a
 * different crew does. Tags are typed by hand, so both spellings are live and
 * matching is trimmed and case-folded.
 *
 * A job whose time falls in NO window — 00:00 means "no time set" in Workiz —
 * holds no window's seat, and is counted separately as `no_time` so the screen
 * can say so rather than silently dropping it. A1 has 24 such future jobs.
 *
 * ── Where the jobs come from, for now ───────────────────────────
 *
 * ponytail: reads `workiz_jobs` by the area's NAME inside the area's franchise,
 * because every A-1 job arrives from Workiz and Jose ruled the areas mirror
 * Workiz's names. When the Jobs module ships `jobs.service_area_id` (agreed
 * 2026-09-25), this is the one function that switches source. The franchise
 * filter is what keeps a Nationwide call-centre job tagged "New York" off the
 * New York franchise's area.
 *
 * ponytail: FREE_STATUSES and EXCLUDED_TAGS are constants, not settings. They
 * hold A-1's vocabulary; the day another organization's words differ, they
 * move into SettingRegistry as org-scoped lists — POPULATED by default, not
 * empty, because an empty "which statuses are free" would make every
 * cancelled job hold a seat (see the lessons spec).
 */
final class Openings
{
    /** Lower-cased, trimmed. */
    public const FREE_STATUSES = ['canceled', 'cancelled', 'done', 'done pending approval'];

    /** Lower-cased, trimmed. */
    public const EXCLUDED_TAGS = ['deposit received', 'deposit recieved'];

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
        $slots = ServiceAreaSlot::query()
            ->where('service_area_id', $area->id)
            ->orderBy('start_time')
            ->get()
            ->groupBy('weekday');

        $taken = $this->taken($area, $from, $from->addDays($days));

        $out = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->addDays($i);
            $key = $date->toDateString();
            $booked = $taken[$key] ?? ['times' => [], 'no_time' => 0];

            $windows = [];
            $placed = 0;

            foreach ($slots->get($date->dayOfWeek, collect()) as $slot) {
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

            $out[] = [
                'date' => $key,
                'windows' => $windows,
                // Jobs that day in no window: no time set, or a time outside
                // every window.
                'no_time' => $booked['no_time'] + (count($booked['times']) - $placed),
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
        $open = array_values(array_filter(
            $this->days($area, $from, self::HORIZON_DAYS),
            fn (array $day) => collect($day['windows'])->contains(fn ($w) => $w['remaining'] > 0),
        ));

        return [
            'first' => $open[0] ?? null,
            'after' => array_slice($open, 1, $after),
        ];
    }

    /**
     * Booked job times per day for the area, seat-holding jobs only.
     *
     * @return array<string, array{times: string[], no_time: int}>
     */
    private function taken(ServiceArea $area, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $jobs = DB::table('workiz_jobs')
            ->where('organization_id', $area->organization_id)
            ->where('franchise_id', $area->franchise_id)
            ->whereRaw('LOWER(TRIM(service_area)) = ?', [mb_strtolower(trim($area->name))])
            ->where('scheduled_for', '>=', $from->toDateString().' 00:00:00')
            ->where('scheduled_for', '<', $to->toDateString().' 00:00:00')
            ->get(['scheduled_for', 'status', 'payload']);

        $byDay = [];

        foreach ($jobs as $job) {
            if (in_array(mb_strtolower(trim((string) $job->status)), self::FREE_STATUSES, true)) {
                continue;
            }

            if ($this->excluded($job->payload)) {
                continue;
            }

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

    private function excluded(mixed $payload): bool
    {
        $data = is_string($payload) ? json_decode($payload, true) : (array) $payload;
        $tags = $data['Tags'] ?? [];

        if (! is_array($tags)) {
            $tags = [$tags];
        }

        foreach ($tags as $tag) {
            if (in_array(mb_strtolower(trim((string) $tag)), self::EXCLUDED_TAGS, true)) {
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

    /** Whether anybody has stated any capacity for this area at all. */
    public static function hasCapacity(ServiceArea $area): bool
    {
        return ServiceAreaSlot::query()->where('service_area_id', $area->id)->exists();
    }
}
