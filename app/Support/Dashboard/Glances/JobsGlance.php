<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Job;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
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
final class JobsGlance extends DescribedGlance
{
    protected const KEY = 'jobs';
    protected const LABEL = 'Jobs';
    protected const PERMISSION = 'jobs.view';
    protected const PRESENTATIONS = ['pie', 'bar', 'line', 'text'];
    protected const DEFAULT_PRESENTATION = 'pie';
    protected const SNAPSHOT = true;

    public function __construct(
        private readonly JobsByStatus $jobsByStatus,
        private readonly Revenue $revenue,
    ) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        // Widgets and Job reads are tenant-scoped; callers (including tests)
        // may resolve outside a request the same way DashboardPeriod::for does.
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            // Finished work belongs on the pie — the JobsByStatus option defaults
            // off for the old widget board, but this glance always shows the full
            // vocabulary once any job exists.
            $byStatus = $this->jobsByStatus->resolve($user, ['include_finished' => true]);
            $total = (int) ($byStatus['total'] ?? 0);

            // One read, used by the prompt, the series and the wire. The
            // $options argument is no longer consulted for it at all — which is
            // why it is not even captured by this closure. See the call site
            // below for why.
            $earned = $this->revenue->earnedStatuses($user);

            $statusChoices = array_column($byStatus['rows'], 'status');

            if ($total === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: $this->wireOptions($earned),
                    data: [
                        'empty' => true,
                        'sentence' => 'No jobs to show.',
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

            // $earned is the ORGANIZATION's list, resolved above, not
            // $options['earned_statuses'] — see Revenue's docblock. The
            // dashboard layout still carries that key and it is deliberately
            // not read: a personal choice cannot decide a company figure, and
            // `earned_at` was stamped from this list, so any second source
            // disagrees with the history it is reporting.
            if ($earned === []) {
                $sentences[] = 'Choose which of your job statuses count as earned.';
            } else {
                // startsOn is a view-TZ wall; earned_at is UTC. Same bind as
                // revenueSeries — a naive clock string would count jobs still
                // in the previous calendar day at the franchise.
                $revenue = $this->revenue->resolve($user, [
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
                    $data['series'] = $this->revenueSeries($user, $period);
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
                options: $this->wireOptions($earned),
                data: $data,
            );
        });
    }

    /**
     * What the payload says is selected: the ORGANIZATION's list.
     *
     * Not whatever the reader's saved layout holds. Echoing the layout after
     * the server stopped obeying it would draw a selection that decides
     * nothing.
     *
     * **MEASURED 2026-09-23, AND IT DOES NOT REACH THE CLIENT.**
     * `DashboardController:69` does `$payload['options'] = $options` after
     * resolving, so a glance's own options are always discarded in favour of
     * the saved layout's copy. Nothing is wrong today — with the chip strip
     * deleted (`e55c99a4`) nothing writes a per-user earned list, so the loop
     * is inert rather than lossy — but this method cannot ship.
     *
     * **AND THE OBVIOUS FIX IS THE §8.2 MISTAKE AGAIN, FROM THE OTHER SIDE.**
     * Dashboard measured it and wrote it at their line, `445765e7`; I had
     * assumed dropping :69 was all it needed and that is wrong. The client
     * echoes `options` straight back on the next layout PUT (`_entryFor`) and
     * `saveLayout` stores what it is sent — so letting the organization's value
     * through here would write it into EVERY user's stored preference, per
     * person, chosen by nobody. That is the same authority failure that made
     * the chips a deletion rather than a repoint.
     *
     * One key is carrying two facts: what the BOX is configured with, and what
     * this READER chose. The fix is two keys, which is a wire change and wants
     * deciding rather than patching. Until then this stays correct and
     * unreachable — do not "simplify" it away for being unobservable, and do
     * not reach for the one-line version of the fix.
     *
     * (Provenance, because `git log` on this file will not tell you: the
     * paragraphs above landed inside `498dd905`, whose subject is about a
     * customer duplicates panel. A peer's `git commit --amend` ran while my
     * commit was HEAD and replaced it, keeping the tree. Nothing is wrong with
     * the content; the SHA that carries it simply does not say so.)
     *
     * @param  list<string>  $earned
     */
    private function wireOptions(array $earned): array
    {
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
     * Reads `earned_at` for the same reason `Revenue` does, and it matters MORE
     * here: this is the line chart, so a job in the wrong bucket is not a wrong
     * total, it is a wrong SHAPE — a peak drawn in the month the work was sold
     * rather than the month it was done. It carried the identical
     * created_at-plus-current-status defect until 2026-09-23 and was missed by
     * the plan, because the total and the series are two queries and only one
     * of them was named.
     *
     * No status argument: `earned_at` is stamped from the organization's list
     * at the moment of the transition, so filtering by today's status as well
     * would drop a job whose status was corrected afterwards — revenue the
     * business really earned, vanishing from a closed month.
     *
     * @return list<array{label: string, points: list<array{at: string, value: int}>}>
     */
    private function revenueSeries(User $user, DashboardPeriod $period): array
    {
        // Period bounds are wall times in the view timezone; earned_at is
        // stored UTC. Bind UTC instants so "now" is not compared as a naive
        // clock string earlier than the row.
        $from = $period->startsOn->copy()->utc();
        $to = $period->endsOn->copy()->utc();

        $jobs = Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->whereNotNull('earned_at')
            ->where('earned_at', '>=', $from)
            ->where('earned_at', '<=', $to)
            ->get(['earned_at', 'currency', 'amount_minor']);

        $empty = $this->emptyBuckets($period);
        $byCurrency = [];

        foreach ($jobs as $job) {
            $at = $job->earned_at;
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
