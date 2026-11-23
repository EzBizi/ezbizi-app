<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizWebSession;
use Illuminate\Console\Command;

/**
 * Each Workiz job's external company, read once through the web session
 * (GET /ajaxc/job/get/{uuid}/ `company_id`): Workiz's Sources report credits
 * a sub-contracted job to its company, and no API says which (A1 Leads
 * takeover row 8, 2026-10-29). Newest first, a bounded batch per run, so a
 * backfill catches up over a few hours without holding the login long.
 */
class SyncWorkizWebJobCompanies extends Command
{
    protected $signature = 'ezbizi:workiz-web-job-companies
        {--days=90 : Jobs created within this many days}
        {--limit=500 : Jobs read per run, across all accounts}';

    protected $description = 'Read each Workiz job\'s external company through the web session';

    public function handle(TenantContext $tenant): int
    {
        $read = WorkizWebSession::eachConnection(
            $tenant,
            fn (SocialConnection $connection, int $sofar) => $this->connection($connection, (int) $this->option('limit') - $sofar),
        );

        $this->info($read.' Workiz job(s) read for their external company.');

        return self::SUCCESS;
    }

    private function connection(SocialConnection $connection, int $budget): int
    {
        $count = 0;

        foreach (WorkizWebSession::accounts($connection) as $account => $name) {
            $franchise = WorkizWebSession::franchiseFor($connection, $account, $name);
            $share = max($budget - $count, 0);

            if ($franchise === null || $share === 0) {
                continue;
            }

            $jobs = WorkizJob::query()->where('franchise_id', $franchise->getKey())->whereNull('external_company_read_at')
                ->where('booked_at', '>=', now()->subDays((int) $this->option('days')))->orderByDesc('booked_at')
                ->limit(min($share, 100))->get(['id', 'organization_id', 'remote_id']);

            if ($jobs->isEmpty()) {
                continue;
            }

            $result = WorkizWebSession::inAccount($connection, $account, function () use ($connection, $jobs) {
                $n = 0;

                foreach ($jobs as $job) {
                    $got = WorkizWebSession::call($connection, '/ajaxc/job/get/'.rawurlencode($job->remote_id).'/');

                    if ($got['refused'] !== null) {
                        return ['refused' => $got['refused'], 'n' => $n];
                    }

                    $data = (array) ($got['data']['data'] ?? $got['data']);
                    $company = (string) ($data['company_id'] ?? '0');
                    $job->forceFill(['external_company_id' => $company === '0' || $company === '' ? null : $company, 'external_company_read_at' => now()])->save();
                    $n++;
                }

                return ['refused' => null, 'n' => $n];
            });

            $count += (int) ($result['n'] ?? 0);
            $refused = $result['refused'] ?? null;

            if ($refused !== null) {
                $this->warn($name.': '.$refused);

                if (in_array($refused, [WorkizWebSession::EXPIRED, WorkizWebSession::BUSY], true)) {
                    break;
                }
            }
        }

        return $count;
    }
}
