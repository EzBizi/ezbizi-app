<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Job;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Dashboard\Widgets\JobsByStatus;
use App\Support\Dashboard\Widgets\Revenue;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Jobs box: status pie (snapshot) plus a per-currency revenue line.
 *
 * Slices reuse JobsByStatus so the vocabulary stays the organization's own.
 * Revenue is never summed across currencies and never divided; unconfigured
 * statuses stay a prompt rather than a silent zero. Today and upcoming are
 * sentences on the same box.
 */
final class JobsGlance implements Glance
{
    public function __construct(
        private readonly JobsByStatus $jobsByStatus,
        private readonly Revenue $revenue,
    ) {}

    public function key(): string
    {
        return 'jobs';
    }

    public function label(): string
    {
        return 'Jobs';
    }

    public function permission(): string
    {
        return 'jobs.view';
    }

    public function allowedPresentations(): array
    {
        return ['pie', 'bar', 'line', 'text'];
    }

    public function defaultPresentation(): string
    {
        return 'pie';
    }

    public function snapshot(): bool
    {
        return true;
    }

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        // Widgets and Job reads are tenant-scoped; callers (including tests)
        // may resolve outside a request the same way DashboardPeriod::for does.
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period, $options) {
            // Finished work belongs on the pie — the JobsByStatus option defaults
            // off for the old widget board, but this glance always shows the full
            // vocabulary once any job exists.
            $byStatus = $this->jobsByStatus->resolve($user, ['include_finished' => true]);
            $total = (int) ($byStatus['total'] ?? 0);

            $statusChoices = array_column($byStatus['rows'], 'status');

            if ($total === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: $this->wireOptions($options),
                    data: [
                        'empty' => true,
                        'sentence' => 'No jobs in reach.',
                        'status_choices' => $statusChoices,
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
                'status_choices' => $statusChoices,
            ];
            $sentences = [];

            $earned = array_values((array) ($options['earned_statuses'] ?? []));
            if ($earned === []) {
                $sentences[] = 'Choose which of your job statuses count as earned.';
            } else {
                // startsOn is a view-TZ wall; created_at is UTC. Same bind as
                // revenueSeries — a naive clock string would count jobs still
                // in the previous calendar day at the franchise.
                $revenue = $this->revenue->resolve($user, [
                    'statuses' => $earned,
                    'since' => $period->startsOn->copy()->utc(),
                ]);
                // Stale or renamed statuses intersect to nothing — same prompt as
                // leaving the control empty; never ship an empty revenue key.
                if (! empty($revenue['needs_configuring'])) {
                    $sentences[] = 'Choose which of your job statuses count as earned.';
                } else {
                    // amount_minor stays on data.revenue only — never ÷100 into a
                    // sentence (JPY and other non-cent currencies would lie).
                    $data['revenue'] = $revenue['rows'];
                    foreach ($revenue['rows'] as $row) {
                        $jobs = (int) $row['jobs'];
                        $currency = $row['currency'];
                        $sentences[] = $jobs === 1
                            ? "1 job earned in {$currency}."
                            : "{$jobs} jobs earned in {$currency}.";
                    }
                    // Line reads series; pie/bar keep the status slices above.
                    $data['series'] = $this->revenueSeries($user, $period, $earned);
                }
            }

            $today = 0;
            $upcoming = 0;
            foreach ($this->scheduledInReach($user) as $job) {
                if ($this->isToday($job)) {
                    $today++;
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

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: $this->wireOptions($options),
                data: $data,
            );
        });
    }

    /** @param  array<string, mixed>  $options */
    private function wireOptions(array $options): array
    {
        $earned = array_values((array) ($options['earned_statuses'] ?? []));

        return $earned === [] ? [] : ['earned_statuses' => $earned];
    }

    /**
     * Reachable jobs that have a wall time, with franchise TZ for today /
     * upcoming. Same load for both so a job later today can count twice.
     *
     * @return \Illuminate\Support\Collection<int, Job>
     */
    private function scheduledInReach(User $user)
    {
        return Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->whereNotNull('scheduled_for')
            ->with(['franchise:id,timezone'])
            ->orderBy('scheduled_for')
            ->get();
    }

    private function isToday(Job $job): bool
    {
        $tz = $job->franchise?->timezone ?: 'UTC';
        $wall = $job->scheduled_for;

        // Convert into the franchise TZ before taking the calendar date.
        // toDateString() alone is the date in $wall's own timezone.
        return $wall !== null
            && $wall->copy()->timezone($tz)->toDateString() === now($tz)->toDateString();
    }

    private function isUpcoming(Job $job): bool
    {
        $tz = $job->franchise?->timezone ?: 'UTC';
        $wall = $job->scheduled_for;

        return $wall !== null && $wall->gte(now($tz));
    }

    /**
     * One series per currency; value is the sum of amount_minor in each
     * DashboardPeriod bucket. Currencies are never added together.
     *
     * @param  list<string>  $statuses
     * @return list<array{label: string, points: list<array{at: string, value: int}>}>
     */
    private function revenueSeries(User $user, DashboardPeriod $period, array $statuses): array
    {
        // Period bounds are wall times in the view timezone; created_at is
        // stored UTC. Bind UTC instants so "now" is not compared as a naive
        // clock string earlier than the row.
        $from = $period->startsOn->copy()->utc();
        $to = $period->endsOn->copy()->utc();

        $jobs = Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->whereIn('status', $statuses)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['created_at', 'currency', 'amount_minor']);

        $empty = $this->emptyBuckets($period);
        $byCurrency = [];

        foreach ($jobs as $job) {
            $at = $job->created_at;
            if (! $at instanceof Carbon) {
                continue;
            }
            $currency = (string) $job->currency;
            if (! isset($byCurrency[$currency])) {
                $byCurrency[$currency] = $empty;
            }
            $bucket = $period->bucket($at);
            $byCurrency[$currency][$bucket] = ($byCurrency[$currency][$bucket] ?? 0)
                + (int) $job->amount_minor;
        }

        ksort($byCurrency);

        $series = [];
        foreach ($byCurrency as $currency => $buckets) {
            $points = [];
            foreach ($buckets as $at => $value) {
                $points[] = ['at' => $at, 'value' => $value];
            }
            $series[] = [
                'label' => $currency,
                'points' => $points,
            ];
        }

        return $series;
    }

    /**
     * Every axis key from startsOn through endsOn, zeros included so the line
     * does not skip quiet days / weeks / months.
     *
     * @return array<string, int>
     */
    private function emptyBuckets(DashboardPeriod $period): array
    {
        $out = [];
        $cursor = $period->startsOn->copy()->startOfDay();
        $end = $period->endsOn->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $out[$period->bucket($cursor)] = 0;
            $cursor->addDay();
        }

        return $out;
    }
}
