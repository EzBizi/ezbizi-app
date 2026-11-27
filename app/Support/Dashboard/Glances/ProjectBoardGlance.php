<?php

namespace App\Support\Dashboard\Glances;

use App\Http\Controllers\Api\ProjectBoardController;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;

/** Project Management on the dashboard: jobs on each sub-tab, counted as the board counts them. */
final class ProjectBoardGlance extends DescribedGlance
{
    protected const KEY = 'project_board';

    protected const LABEL = 'Project Management';

    protected const PERMISSION = 'project_board.view';

    protected const PRESENTATIONS = ['bar', 'text'];

    protected const DEFAULT_PRESENTATION = 'bar';

    protected const SNAPSHOT = true;

    private const NAMES = [
        'unscheduled' => 'Unscheduled', 'ready_to_schedule' => 'Ready to Schedule', 'scheduled' => 'Scheduled',
        'in_progress' => 'In Progress', 'done_waiting_payment' => 'Done Waiting Payment', 'complete' => 'Complete',
    ];

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $counts = app(ProjectBoardController::class)->countsFor($user, app(SettingsResolver::class));
            $open = array_sum($counts) - $counts['complete'];

            return $this->payload([
                'empty' => array_sum($counts) === 0,
                'sentence' => $open === 0 ? 'No open jobs in this view.' : ($open === 1 ? '1 open job.' : "{$open} open jobs."),
                'figures' => collect($counts)->map(fn (int $n, string $t) => GlancePayload::figure($t, self::NAMES[$t], $n))->values()->all(),
            ]);
        });
    }
}
