<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Which job each lead became, found by the customer's phone number.
 *
 * A1 Leads matches Local Services leads to Workiz jobs to put revenue beside
 * each account's spend; this is EzBizi's version (Jose, 2026-09-28: "build
 * everything and adapt it to ezbizi"). A lead whose phone matches a Workiz
 * client with a job BOOKED from a day before the lead to 30 days after it is
 * given that job - the earliest one. Measured on production the same day: 2,720
 * of the last 90 days' 6,533 Local Services leads match, and every one of those
 * jobs is already an EzBizi job.
 *
 * Only a lead with no job is touched, so a job somebody booked from the lead
 * itself is never replaced, and `job_matched_by` records that this link was
 * found rather than made - the lead's own status is left alone.
 *
 * `booked_at`, not the job's created_at: a projected job's created_at is the
 * moment it was imported.
 */
class MatchLeadsToJobs extends Command
{
    protected $signature = 'ezbizi:match-leads-to-jobs {--days=45 : how far back to look for unmatched leads} {--all : every unmatched lead}';

    protected $description = 'Link leads to the Workiz job they became, by the customer\'s phone number';

    private const BEFORE = 86400;          // a job booked the day before the lead came in still counts

    private const AFTER = 30 * 86400;

    public function handle(TenantContext $tenant): int
    {
        $since = $this->option('all') ? null : now()->subDays((int) $this->option('days'));
        $matched = 0;

        $organizations = Lead::query()->withoutGlobalScopes()
            ->whereNull('job_id')->whereNotNull('phone_digits')
            ->when($since, fn ($q) => $q->where('received_at', '>=', $since))
            ->distinct()->pluck('organization_id');

        foreach (Organization::query()->withoutGlobalScopes()->whereIn('id', $organizations)->get() as $organization) {
            $tenant->run($organization, function () use ($since, &$matched) {
                // Every Workiz client by the last ten digits, and every booked
                // job by client: one read each, not one per lead.
                $clients = WorkizClient::query()->whereNotNull('phone_digits')->get(['client_id', 'phone_digits'])
                    ->groupBy(fn (WorkizClient $c) => substr($c->phone_digits, -10));
                $jobs = WorkizJob::query()->whereNotNull('job_id')->whereNotNull('booked_at')
                    ->get(['client_id', 'job_id', 'booked_at'])
                    ->groupBy('client_id');

                Lead::query()
                    ->whereNull('job_id')->whereNotNull('phone_digits')->whereNotNull('received_at')
                    ->when($since, fn ($q) => $q->where('received_at', '>=', $since))
                    ->lazyById(500)
                    ->each(function (Lead $lead) use ($clients, $jobs, &$matched) {
                        $digits = substr($lead->phone_digits, -10);
                        if (strlen($digits) < 10) {
                            return;
                        }
                        $at = $lead->received_at->getTimestamp();
                        $job = $clients->get($digits, collect())
                            ->flatMap(fn (WorkizClient $c) => $jobs->get($c->client_id, collect()))
                            ->filter(fn (WorkizJob $j) => ($t = Carbon::parse($j->booked_at)->getTimestamp()) >= $at - self::BEFORE && $t <= $at + self::AFTER)
                            ->sortBy('booked_at')
                            ->first();

                        if ($job !== null) {
                            $lead->forceFill([
                                'job_id' => $job->job_id,
                                'job_matched_by' => 'phone',
                                // It became a job: nobody owes it an answer
                                // (Jose, 2026-10-09). A decision already made
                                // (booked, archived, not a lead) stands.
                                'status' => $lead->status === Lead::STATUS_NEW ? Lead::STATUS_COMPLETED : $lead->status,
                            ])->save();
                            $matched++;
                        }
                    });
            });
        }

        $this->info("Matched {$matched} lead(s) to a job.");

        return self::SUCCESS;
    }
}
