<?php

namespace App\Support\Dashboard\Widgets;

use App\Models\Job;
use App\Models\User;
use App\Support\Dashboard\Widget;

/**
 * What is happening next.
 *
 * The one widget on this list somebody would actually open the app for. Ordered
 * by the scheduled time and limited, because a dashboard that renders four
 * hundred rows is a list screen with extra steps.
 */
class UpcomingJobs implements Widget
{
    use ScopesToReach;

    public function key(): string { return 'jobs.upcoming'; }

    public function label(): string { return 'Coming up'; }

    public function permission(): string { return 'jobs.view'; }

    public function options(User $user): array
    {
        return [
            'limit' => [
                'label'   => 'How many',
                'type'    => 'enum',
                'options' => [5, 10, 20],
                'default' => 5,
            ],
        ];
    }

    public function resolve(User $user, array $options): array
    {
        $limit = (int) ($options['limit'] ?? 5);

        $jobs = $this->reachable(Job::query(), $user)
            ->whereNotNull('scheduled_for')
            ->with(['customer:id,name', 'franchise:id,name,timezone'])
            ->orderBy('scheduled_for')
            ->limit(50)
            ->get()
            ->filter(function (Job $job) {
                $tz = $job->franchise?->timezone ?: 'UTC';
                $wall = $job->scheduled_for;
                return $wall !== null && $wall->gte(now($tz));
            })
            ->take(max(1, min($limit, 50)))
            ->values();

        return [
            'rows' => $jobs->map(fn (Job $job) => [
                'id'            => $job->id,
                'number'        => $job->number,
                'title'         => $job->title,
                'status'        => $job->status,
                'scheduled_for' => $job->scheduled_for?->toIso8601String(),
                'customer'      => $job->customer?->name,
                'franchise'     => $job->franchise?->name,
            ])->all(),
        ];
    }
}
