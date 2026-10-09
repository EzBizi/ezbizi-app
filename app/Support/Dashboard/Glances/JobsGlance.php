<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Job;
use App\Models\ServiceArea;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Dashboard\Widgets\JobsByStatus;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;

/**
 * Jobs box: status pie (snapshot), with today and upcoming as sentences.
 *
 * Slices reuse JobsByStatus so the vocabulary stays the organization's own.
 *
 * Money only for a reader whose role holds `jobs.financials` (Jose,
 * 2026-10-05: "as for the money remember its a role y permission things where
 * ( can see financials ) should be toggled", replacing his 2026-09-29 rule of
 * no money on the dashboard at all). Then the box adds what was earned in the
 * period as figures, by `earned_at`; for anybody else the payload carries no
 * amount at all.
 */
final class JobsGlance extends DescribedGlance
{
    protected const KEY = 'jobs';
    protected const LABEL = 'Jobs';
    protected const PERMISSION = 'jobs.view';
    protected const PRESENTATIONS = ['pie', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'pie';
    protected const SNAPSHOT = true;

    public function __construct(
        private readonly JobsByStatus $jobsByStatus,
    ) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        // Widgets and Job reads are tenant-scoped; callers (including tests)
        // may resolve outside a request the same way DashboardPeriod::for does.
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            // Finished work belongs on the pie: the full vocabulary shows once
            // any job exists.
            $byStatus = $this->jobsByStatus->resolve($user, []);
            $total = (int) ($byStatus['total'] ?? 0);

            if ($total === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: [],
                    data: [
                        'empty' => true,
                        'sentence' => 'No jobs to show.',
                    ],
                );
            }

            $slices = array_map(fn (array $row) => [
                'label' => $row['status'],
                'total' => (int) $row['total'],
            ], $byStatus['rows']);

            $data = [
                'empty' => false,
                'slices' => $slices,
            ];
            $sentences = [];

            $today = 0;
            $yesterday = 0;
            $upcoming = 0;
            foreach ($this->scheduledInReach($user) as $job) {
                if ($this->isToday($job)) {
                    $today++;
                }
                if ($this->isToday($job, daysAgo: 1)) {
                    $yesterday++;
                }
                if ($this->isUpcoming($job)) {
                    $upcoming++;
                }
            }

            if ($today === 1) {
                $sentences[] = '1 job today.';
            } elseif ($today > 1) {
                $sentences[] = "{$today} jobs today.";
            }

            if ($upcoming === 1) {
                $sentences[] = '1 job coming up.';
            } elseif ($upcoming > 1) {
                $sentences[] = "{$upcoming} jobs coming up.";
            }

            if ($sentences !== []) {
                $data['sentences'] = $sentences;
            }

            // Today against yesterday whatever the chips say: the pie is a
            // snapshot, and "jobs this quarter so far" is not a figure anybody
            // dispatches by. Coming up has no earlier self to compare with.
            $data['figures'] = [
                GlancePayload::figure('today', 'today', $today, $yesterday),
                GlancePayload::figure('upcoming', 'coming up', $upcoming),
            ];

            $reach = ViewScope::forUser($user)->franchiseIds();

            // After the counts, so money is never the headline a Small box
            // shows a room. See the class docblock for who gets it.
            if ($user->hasPermission('jobs.financials')) {
                array_push($data['figures'], ...$this->earned($reach, $period));
            }

            // What needs somebody (Dashboard, 2026-10-05): counts, never money,
            // and only rows with something in them.
            $attention = array_values(array_filter([
                $this->neverStarted($user, $reach),
                $this->unplaced($reach),
            ]));
            if ($attention !== []) {
                $data['attention'] = $attention;
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: $data,
            );
        });
    }

    /**
     * What was earned in the period, against the same span before it: by
     * `earned_at`, the moment a job first reached an earned status, so a job
     * booked in June and finished in September counts in September. One
     * figure per currency, never summed across them. UTC bounds, because
     * `earned_at` is an instant, unlike `scheduled_for`.
     *
     * @param  list<string>  $reach
     * @return list<array{key: string, label: string, value: int, previous: int|null, currency: string}>
     */
    private function earned(array $reach, DashboardPeriod $period): array
    {
        $sum = fn (DashboardPeriod $p) => Job::query()
            ->whereIn('franchise_id', $reach)
            ->whereBetween('earned_at', [$p->startsOn->copy()->utc(), $p->endsOn->copy()->utc()])
            ->selectRaw('currency, SUM(amount_minor) AS total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $now = $sum($period);
        $before = $sum($period->previous());

        return $now->keys()->merge($before->keys())->unique()->sort()->values()
            ->map(fn (string $currency) => GlancePayload::money(
                'earned_'.strtolower($currency),
                'earned '.$period->phrase(),
                (int) ($now[$currency] ?? 0),
                (int) ($before[$currency] ?? 0),
                $currency,
            ))->all();
    }

    /**
     * Jobs dated in the past week, more than a day ago, still in the first
     * status of the organization's own list: booked and never started. Said in
     * the organization's word, so the row states what was counted rather than
     * a meaning EzBizi guessed for the status. Today and yesterday are grace,
     * for late runners; the week keeps never-closed history from drowning it
     * (A1, 2026-10-05: 53 Submitted within the week, 167 older).
     *
     * By whole dates, because `scheduled_for` is a naive wall time in the
     * franchise's zone (FranchiseWallTime): set against a UTC instant it is off
     * by the zone's offset, and a date boundary absorbs that.
     *
     * @param  list<string>  $reach
     * @return array{tone: string, text: string}|null
     */
    private function neverStarted(User $user, array $reach): ?array
    {
        $first = Job::statusEntries($user->organization)[0] ?? null;

        if ($first === null) {
            return null;
        }

        $n = Job::query()
            ->whereIn('franchise_id', $reach)
            ->where('status', $first['value'])
            ->where('scheduled_for', '>=', now()->subWeek()->startOfDay())
            ->where('scheduled_for', '<', now()->subDay()->startOfDay())
            ->count();

        return $n === 0 ? null : GlancePayload::attention('bad', $n === 1
            ? "1 job from the past week is still {$first['label']}."
            : "{$n} jobs from the past week are still {$first['label']}.");
    }

    /**
     * Jobs from today on with no service area: Scheduling counts a day's work
     * by area, so these are missing from it. From the start of today, a date
     * rather than now(), for the wall-time reason given above. Only where
     * service areas exist at all; an organization without them has nothing to
     * place a job into.
     *
     * @param  list<string>  $reach
     * @return array{tone: string, text: string}|null
     */
    private function unplaced(array $reach): ?array
    {
        if (! ServiceArea::query()->whereIn('franchise_id', $reach)->exists()) {
            return null;
        }

        $n = Job::query()
            ->whereIn('franchise_id', $reach)
            ->where('scheduled_for', '>=', now()->startOfDay())
            ->whereNull('service_area_id')
            ->count();

        return $n === 0 ? null : GlancePayload::attention('caution', $n === 1
            ? '1 job from today on has no service area.'
            : "{$n} jobs from today on have no service area.");
    }

    /**
     * Reachable jobs that have a wall time, with franchise TZ for today /
     * upcoming. Same load for both so a job later today can count twice.
     *
     * From the start of the UTC date two days back. `scheduled_for` is a naive
     * wall time (FranchiseWallTime), and a franchise's date is at most one day
     * behind UTC's, so that date holds all of its yesterday in any zone.
     * Unbounded, this loaded every dated job ever: about 49,000 rows for A1 on
     * every dashboard view.
     *
     * @return \Illuminate\Support\Collection<int, Job>
     */
    private function scheduledInReach(User $user)
    {
        return Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->where('scheduled_for', '>=', now()->subDays(2)->startOfDay())
            ->with(['franchise:id,timezone'])
            ->orderBy('scheduled_for')
            ->get();
    }

    private function isToday(Job $job, int $daysAgo = 0): bool
    {
        $tz = $job->franchise?->timezone ?: 'UTC';
        $wall = $job->scheduled_for;

        // Convert into the franchise TZ before taking the calendar date.
        // toDateString() alone is the date in $wall's own timezone.
        return $wall !== null
            && $wall->copy()->timezone($tz)->toDateString() === now($tz)->subDays($daysAgo)->toDateString();
    }

    private function isUpcoming(Job $job): bool
    {
        $tz = $job->franchise?->timezone ?: 'UTC';
        $wall = $job->scheduled_for;

        return $wall !== null && $wall->gte(now($tz));
    }
}
