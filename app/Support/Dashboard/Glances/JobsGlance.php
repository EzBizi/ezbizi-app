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

/**
 * Jobs box: status pie (snapshot) plus revenue and upcoming as sentences.
 *
 * Slices reuse JobsByStatus so the vocabulary stays the organization's own.
 * Revenue is never summed across currencies; unconfigured statuses stay a
 * prompt rather than a silent zero.
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
        return ['pie', 'bar', 'text'];
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

            $earned = array_values((array) ($options['earned_statuses'] ?? []));
            if ($earned === []) {
                $sentences[] = 'Choose which of your job statuses count as earned.';
            } else {
                $revenue = $this->revenue->resolve($user, [
                    'statuses' => $earned,
                    'since' => $period->startsOn,
                ]);
                // Stale or renamed statuses intersect to nothing — same prompt as
                // leaving the control empty; never ship an empty revenue key.
                if (! empty($revenue['needs_configuring'])) {
                    $sentences[] = 'Choose which of your job statuses count as earned.';
                } else {
                    $data['revenue'] = $revenue['rows'];
                    foreach ($revenue['rows'] as $row) {
                        // Display only — amount_minor stays on data.revenue for the client.
                        $amount = number_format(((int) $row['amount_minor']) / 100, 2);
                        $sentences[] = "{$amount} {$row['currency']} earned.";
                    }
                }
            }

            $upcoming = $this->upcomingCount($user);
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

    private function upcomingCount(User $user): int
    {
        return Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '>', now())
            ->count();
    }
}
