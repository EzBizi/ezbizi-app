<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Job;
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
 * No money, deliberately: Jose ruled on 2026-09-29 that the dashboard shows no
 * financial information, because anybody who can open Jobs — a dispatcher —
 * sees this box. Revenue used to be a list and a per-currency line here; the
 * widget that summed it went too, so no revenue total exists anywhere now --
 * only each job's own amount, and `earned_at` to total it by when somebody
 * asks. `line` left the presentations with it, since the revenue series was the
 * only thing it drew.
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
                    options: [],
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

            // NO MONEY ON THE DASHBOARD — Jose, 2026-09-29: "do not show any
            // financial info on the dashboard widgets, for example dispatchers
            // may have access to jobs so they would see the widget." The box
            // is operational: status, today, coming up. Revenue, its line and
            // the "earned" lines were removed rather than hidden behind a
            // permission, because the ruling names the surface, not a role.

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
                options: [],
                data: $data,
            );
        });
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
}
