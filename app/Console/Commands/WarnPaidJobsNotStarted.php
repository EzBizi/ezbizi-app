<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\WorkizRecord;
use App\Support\Jobs\ProjectStages;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * The paid-but-not-started warning (Jose, 2026-10-22): a job paid
 * `jobs.pm_warn_days` days ago or more that is still waiting to start goes in
 * one email a day to its company's `jobs.pm_warning_email`. Empty sends nothing.
 */
class WarnPaidJobsNotStarted extends Command
{
    protected $signature = 'jobs:warn-paid-not-started {--dry-run : Print, send nothing}';

    protected $description = 'Email each company the paid jobs that have not started in time';

    public function handle(TenantContext $tenant, SettingsResolver $settings): int
    {
        $sent = 0;
        foreach (Organization::query()->get() as $org) {
            $tenant->run($org, function () use ($org, $settings, &$sent) {
                // Each branch by its own company's stages: a company maps its own (Jose, 2026-10-22).
                $franchises = Franchise::query()->get();
                $rules = ProjectStages::forFranchises($franchises, $settings);
                // Only jobs Project Management shows (its scope settings), as its own lists (audit, 2026-10-23).
                $waiting = ProjectStages::anyOf(ProjectStages::inScope(Job::query(), $franchises, $settings), $rules, ProjectStages::NOT_STARTED)->pluck('id');
                if ($waiting->isEmpty()) {
                    return;
                }
                $paid = WorkizRecord::query()->whereIn('job_id', $waiting)->where('type', 'payment')->where('status', 'succeeded')
                    ->groupBy('job_id')->selectRaw('job_id, MIN(starts_at) paid_at')->pluck('paid_at', 'job_id');

                $byRecipient = [];
                Job::query()->whereIn('id', $paid->keys())->with(['customer:id,name', 'franchise'])->get()
                    ->each(function (Job $j) use ($settings, $paid, &$byRecipient) {
                        $franchise = $j->franchise;
                        $to = trim((string) $settings->get('jobs.pm_warning_email', $franchise));
                        $days = (int) Carbon::parse($paid[$j->id])->startOfDay()->diffInDays(now()->startOfDay());
                        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && $days >= (int) $settings->get('jobs.pm_warn_days', $franchise)) {
                            $byRecipient[$to][] = "{$j->number} · {$j->customer?->name} · {$franchise?->name} · paid ".Carbon::parse($paid[$j->id])->toDateString()." ({$days} days ago)";
                        }
                    });

                foreach ($byRecipient as $to => $lines) {
                    $body = count($lines)." paid job(s) have not started yet:\n\n".implode("\n", $lines)."\n\nOpen Project Management in EzBizi to follow up.";
                    if ($this->option('dry-run')) {
                        $this->line("{$to}:\n{$body}");
                    } else {
                        Mail::raw($body, fn ($m) => $m->to($to)->subject('Paid jobs not started: '.count($lines)));
                    }
                    $sent++;
                }
            });
        }
        $this->info(($this->option('dry-run') ? 'Would send ' : 'Sent ').$sent.' warning email(s).');

        return self::SUCCESS;
    }
}
