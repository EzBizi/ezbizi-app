<?php

namespace App\Support\Dashboard\Glances;

use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\TimeClock;

/**
 * Work Hours box: who is on the clock right now, and the hours clocked in
 * the period (2026-09-24, when Work Hours became a sidebar module).
 *
 * The people are exactly the ones the Work Hours screen lists for this
 * reader — TimeClock::visiblePeople, so reach and `work_hours.all` answer
 * here the same way they answer there. A box that counted somebody the
 * screen does not show would be a number nobody could reconcile.
 */
final class WorkHoursGlance extends DescribedGlance
{
    protected const KEY = 'work_hours';

    protected const LABEL = 'Work Hours';

    protected const PERMISSION = 'work_hours.general.view';

    protected const PRESENTATIONS = ['bar', 'pie', 'text'];

    protected const DEFAULT_PRESENTATION = 'bar';

    protected const SNAPSHOT = true;

    public function __construct(private readonly TimeClock $timeClock) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $people = $this->timeClock->visiblePeople($user)->where('is_active', true)->pluck('id');

            $payload = fn (array $data) => new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: $data,
            );

            if ($people->isEmpty()) {
                // True of both causes it cannot tell apart — nobody on the
                // books, or a reader who reaches nobody — as HR's box says.
                return $payload(['empty' => true, 'sentence' => 'Nobody in this view.']);
            }

            $in = TimeClockShift::query()->open()->whereIn('user_id', $people)->distinct()->count('user_id');
            $out = $people->count() - $in;

            $hours = $this->hoursIn($people, $period);

            return $payload([
                'empty' => false,
                'slices' => [
                    ['label' => 'Clocked in', 'total' => $in],
                    ['label' => 'Not clocked in', 'total' => $out],
                ],
                'sentences' => [
                    $in === 1 ? '1 person is clocked in right now.' : "{$in} people are clocked in right now.",
                    $hours === 1
                        ? "1 hour clocked {$period->phrase()} in this view."
                        : "{$hours} hours clocked {$period->phrase()} in this view.",
                ],
                // On the clock is right now, so it has no earlier self. Hours
                // compare with the same elapsed span one period back.
                'figures' => [
                    GlancePayload::figure('on_the_clock', 'on the clock', $in),
                    GlancePayload::figure('hours', 'hours clocked '.$period->phrase(), $hours, $this->hoursIn($people, $period->previous())),
                ],
            ]);
        });
    }

    /**
     * Whole hours clocked inside a window by these people.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $people
     */
    private function hoursIn($people, DashboardPeriod $window): int
    {
        // Clocked time that falls in the period, an open shift counted to
        // now, as the Work Hours totals do. ->utc() for the reason every
        // sibling glance gives: the period is wall clock in the view zone.
        $from = $window->startsOn->copy()->utc();
        $to = $window->endsOn->copy()->utc();
        $now = now();
        $seconds = 0;

        foreach (TimeClockShift::query()
            ->whereIn('user_id', $people)
            ->where('clocked_in_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('clocked_out_at')->orWhere('clocked_out_at', '>', $from))
            ->get(['clocked_in_at', 'clocked_out_at']) as $shift) {
            $start = $shift->clocked_in_at->max($from);
            $end = ($shift->clocked_out_at ?? $now)->min($to)->min($now);
            $seconds += max(0, $end->getTimestamp() - $start->getTimestamp());
        }

        return intdiv($seconds, 3600);
    }
}
