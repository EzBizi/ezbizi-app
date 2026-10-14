<?php

namespace App\Support\Scheduling;

use App\Models\ServiceArea;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;

/**
 * Booked jobs that would sit better on another day (Jose, 2026-10-13: "a tab
 * with Suggested Reschedules ... what to replace them for, to better fit a
 * comprehensive route for the technicians").
 *
 * A job is a candidate when nothing else booked that day is within the
 * suggestion radius of it: a technician drives out for it alone. It is moved,
 * on paper, to the open window NearbySuggestion would offer a new customer at
 * its address, so the two features can never disagree. Online bookings are
 * left alone (the customer picked that window themselves seconds ago).
 *
 * ponytail: one job at a time, so two lone jobs that could pair on a third
 * day are not proposed together, and two suggestions may name the same last
 * seat (the dispatcher who books first takes it; the list refreshes). Plan
 * them jointly if the list runs dry or seats collide in practice.
 */
final class Reschedules
{
    /*
     * Worth a phone call (Grok's reviews of the live list, 2026-10-14). Each
     * limit is a Setting (Jose, 2026-10-14: "i want to be able to change all
     * the settings in the app").
     */
    /** Jobs this soon are not moved: the customer has planned for them. */
    public const SETTLED_DAYS = 'scheduling.reschedule_settled_days';

    /** At most this many days earlier or later. */
    public const MAX_SHIFT_DAYS = 'scheduling.reschedule_max_shift_days';

    /**
     * The nearest job after the move is at most this far. (No "half of today"
     * rule: a listed job has nothing within the radius today, so this alone
     * already more than halves it.)
     */
    public const MAX_AFTER_MILES = 'scheduling.reschedule_max_after_miles';

    public function __construct(
        private readonly NearbySuggestion $nearby,
        private readonly SettingsResolver $settings,
    ) {}

    /**
     * Open spots on a day before and after the move (Jose, 2026-10-14: "the
     * scheduling before and after for the area too, as in the amount of spots
     * before and after"), window by window, in Availability's numbers. The
     * day a job leaves gets its seat back unless that window was over its
     * limit anyway; the day it joins loses one.
     *
     * @return array{window_index: ?int, spots: ?array}
     */
    private static function spots(?array $day, ?int $window, int $change): array
    {
        if ($day === null) {
            return ['window_index' => $window, 'spots' => null];
        }

        $windows = array_map(function (array $w, int $i) use ($window, $change) {
            $after = $w['remaining'];
            if ($i === $window) {
                $after = $change > 0
                    ? ($w['taken'] <= $w['capacity'] ? min($w['capacity'], $w['remaining'] + 1) : $w['remaining'])
                    : max(0, $w['remaining'] - 1);
            }

            return ['start_time' => $w['start_time'], 'end_time' => $w['end_time'], 'before' => $w['remaining'], 'after' => $after];
        }, $day['windows'], array_keys($day['windows']));

        return ['window_index' => $window, 'spots' => [
            'before' => array_sum(array_column($windows, 'before')),
            'after' => array_sum(array_column($windows, 'after')),
            'windows' => $windows,
        ]];
    }

    /** The window a start time falls in, or null. */
    private static function windowAt(?array $day, string $time): ?int
    {
        foreach ($day['windows'] ?? [] as $i => $w) {
            if ($time !== '00:00' && $time >= $w['start_time'] && $time < $w['end_time']) {
                return $i;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function for(ServiceArea $area, CarbonImmutable $from): array
    {
        $look = $this->nearby->load($area, $from);
        $radius = $look['radius'];
        $limit = fn (string $key) => (int) $this->settings->get($key, $look['franchise']);
        [$maxShift, $maxAfter] = [$limit(self::MAX_SHIFT_DAYS), $limit(self::MAX_AFTER_MILES)];
        $settled = $from->addDays($limit(self::SETTLED_DAYS) - 1)->toDateString();
        $out = [];

        foreach ($look['jobs'] as $date => $onDay) {
            foreach ($onDay as $job) {
                if ($job->kind === 'booking' || $date <= $settled) {
                    continue;
                }
                $at = ['latitude' => (float) $job->latitude, 'longitude' => (float) $job->longitude];
                $others = $look['jobs']->map(fn ($d) => $d->reject(fn ($j) => $j === $job));
                $today = $this->nearby->placed($others[$date], [], $at, $radius);

                if ($today->contains('nearby', true)) {
                    continue;
                }

                $now = $today->min('miles');
                $best = NearbySuggestion::best(array_filter(array_map(
                    fn (array $c) => NearbySuggestion::windowLocal($c, $look['gap']),
                    $this->nearby->candidates($look['days']->reject(fn (array $d) => $d['date'] === $date), $others, $at, $radius),
                ), fn (array $c) => $c['nearby'] > 0
                    && abs(CarbonImmutable::parse($c['date'])->diffInDays(CarbonImmutable::parse($date))) <= $maxShift
                    && $c['nearest_miles'] <= $maxAfter));

                if ($best !== null) {
                    $day = $look['all_days'][$date] ?? null;
                    $out[] = [
                        'row' => $job,
                        'current' => ['date' => $date, 'nearest_miles' => $now, 'day' => $day]
                            + self::spots($day, self::windowAt($day, substr((string) $job->scheduled_for, 11, 5)), +1),
                        'suggested' => $this->nearby->shown($best) + self::spots($best['day'], $best['window_index'], -1),
                        // The jobs it would join: they stay where they are.
                        'anchors' => $others[$best['date']]->filter(fn ($j) => NearbySuggestion::miles(
                            $at['latitude'], $at['longitude'], (float) $j->latitude, (float) $j->longitude) <= $radius)->all(),
                    ];
                }
            }
        }

        // The biggest saving first: alone and far today, close on the new day.
        // A job another suggestion moves next to is not itself moved, or two
        // lone jobs would each be sent to the other's day.
        $anchored = [];

        return collect($out)
            ->sortBy(fn ($r) => [$r['suggested']['nearest_miles'] - ($r['current']['nearest_miles'] ?? 999), $r['current']['date']])
            ->reject(function (array $r) use (&$anchored) {
                if (in_array($r['row'], $anchored, true)) {
                    return true;
                }
                array_push($anchored, ...array_values($r['anchors']));

                return false;
            })
            ->map(fn (array $r) => array_diff_key($r, ['anchors' => 0]))
            ->values()->all();
    }
}
