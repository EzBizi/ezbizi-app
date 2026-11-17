<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Organization;
use App\Support\ProjectBoard\ClientMessages;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sends Project Management's client texts that are switched on (all ship
 * OFF; Jose approves the wording first): the project link when a job reaches
 * the board, the date when it is scheduled, a reminder the day before, and a
 * thank-you when it is complete. Only for a job whose moment came after the
 * text was switched on (`since`), so switching one on never texts the
 * backlog. Each goes once (ClientMessages).
 */
class ProjectBoardClientMessages extends Command
{
    protected $signature = 'ezbizi:project-board-client-messages';

    protected $description = 'Send the Project Management client texts that are switched on';

    public function handle(TenantContext $tenant, SettingsResolver $settings, ClientMessages $messages): int
    {
        $sent = 0;
        foreach (Organization::query()->get() as $org) {
            $sent += $tenant->run($org, function () use ($settings, $messages) {
                $n = 0;
                $jobs = Job::query()->where('tags', 'like', '%DEPOSIT RECEIVED%')
                    ->where('updated_at', '>=', now()->subDays(120))->with(['customer:id,name,phone', 'franchise.company'])->get();
                foreach ($jobs as $job) {
                    $all = (array) $settings->get('project_board.client_messages', $job->franchise?->company ?? $job->organization);
                    $since = fn (string $kind) => ($all[$kind]['on'] ?? false) === true && filled($all[$kind]['since'] ?? null)
                        ? Carbon::parse($all[$kind]['since']) : null;
                    $zone = (string) ($job->franchise?->timezone ?? 'America/New_York');
                    $due = [
                        'project_link' => ($s = $since('project_link')) !== null && $job->created_at?->gte($s),
                        'scheduled' => ($s = $since('scheduled')) !== null && $job->scheduled_for?->isFuture() && $job->updated_at?->gte($s),
                        'day_before' => $since('day_before') !== null && $job->scheduled_for !== null
                            && $job->scheduled_for->copy()->setTimezone($zone)->isSameDay(now($zone)->addDay()),
                        'complete' => ($s = $since('complete')) !== null && $job->updated_at?->gte($s)
                            && in_array(strtolower((string) $job->status), ['done', 'completed', 'complete'], true),
                    ];
                    foreach (array_keys(array_filter($due)) as $kind) {
                        $n += $messages->send($job, $kind) === null ? 1 : 0;
                    }
                }

                return $n;
            });
        }
        $this->info("Checked; {$sent} client texts handled.");

        return self::SUCCESS;
    }
}
