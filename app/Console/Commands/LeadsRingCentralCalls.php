<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Leads\RingCentralLeadCalls;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * RingCentral calls into Leads (Jose, 2026-10-15). See RingCentralLeadCalls.
 *
 * Once per organization with RingCentral: its own connection (Settings ›
 * Integrations), or A1-Group's env credential, which RingCentralCallLog reads
 * only inside that organization (8a640f19). Everything runs inside the
 * organization, so its call log is its own.
 *
 * Only calls at least ten minutes old: Google's copy of a Local Services call
 * lands within its own sync, and a call it beats is withdrawn on a later run.
 */
class LeadsRingCentralCalls extends Command
{
    protected $signature = 'ezbizi:leads-ringcentral {--days=1 : days back to read, including today}';

    protected $description = 'File RingCentral calls to the business lines as leads, once per call';

    public function handle(RingCentralCallLog $log, RingCentralLeadCalls $calls, TenantContext $tenant): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::RingCentral->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->get();
        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id'))
            ->orWhere('slug', RingCentralCallLog::ENV_ORGANIZATION_SLUG)
            ->get();

        $zone = (string) config('app.timezone');
        $now = CarbonImmutable::now($zone);
        // At least the last three hours, across midnight too: a call filed In
        // progress or Fetching at 23:30 is re-read until it settles (desk,
        // 2026-10-19).
        $start = $now->subDays(max(1, (int) $this->option('days')) - 1)->startOfDay()->min($now->subHours(3));
        // Two minutes, not ten: measured 2026-10-16, calls landed 10-19 minutes
        // late and Jose saw Bar's call "after a while". The log lists a call
        // once it has ended; a Google copy that arrives later is withdrawn.
        // Desk, 2026-11-18: 30 seconds after the call ENDED, not two minutes
        // after it started; missed-call alerts were taking about 6 minutes.
        $ready = $now->subSeconds(30);

        foreach ($organizations as $organization) {
            $tenant->run($organization, function () use ($log, $calls, $connections, $organization, $start, $now, $ready) {
                // Every account it holds, not the first (Triplex: A1 Chimney's and
                // EverDoors', desk audit 2026-11-12).
                $filed = 0;
                foreach ($log->accounts() as $account) {
                    $log->only($account);
                    if (! $log->configured()) {
                        continue;
                    }
                    $company = self::companyFor($organization, $account === null
                        ? $connections->firstWhere('organization_id', $organization->getKey())
                        : $connections->firstWhere('id', $account));
                    if ($company === null) {
                        $this->warn("{$organization->name}: which company its calls belong to is unknown; skipped.");

                        continue;
                    }

                    // Inbound and outbound (Jose, 2026-10-16: "all calls should show up").
                    // The routine run reads its window fresh: forPeriod keeps past
                    // days cached, and yesterday's last calls are still settling.
                    $read = (int) $this->option('days') <= 1
                        ? $log->forDate($start->toIso8601String(), $now->toIso8601String())
                        : $log->forPeriod($start, $now, outbound: true);
                    $rows = array_values(array_filter($read, fn (array $c) => self::endedAt($c) <= $ready));
                    // Live calls, which the call log shows as "Accepted": In progress,
                    // re-read when they end. No list: the last three hours wait for
                    // the next run - guessing would tag live calls Missed and alert
                    // on them - and older calls, long ended, are filed (desk,
                    // 2026-10-20: a 30-day catch-up stopped on a 429).
                    try {
                        $live = $log->activeCallIds();
                    } catch (\RuntimeException $e) {
                        $recent = $now->subHours(3);
                        $rows = array_values(array_filter($rows, fn (array $c) => CarbonImmutable::parse((string) $c['started_at']) < $recent));
                        $this->warn("{$organization->name}: {$e->getMessage()} Calls of the last three hours skipped this run.");
                        $live = [];
                    }
                    // RingCentral keeps a call on its active list for minutes after it
                    // ends (measured 2026-11-18: 9 of 10 listed had stopped growing),
                    // which held every missed call "In progress" for a pass or two.
                    // Live: listed AND its length still reaching now (or no length yet).
                    $rows = array_map(fn (array $c) => $c + ['live' => isset($live[(string) $c['id']]) && ((int) ($c['duration'] ?? 0) === 0 || self::endedAt($c) >= $now->subSeconds(20))], $rows);

                    $filed += $calls->file($rows, $log->numberNames(), $company);
                    // New or existing, from evidence rather than the key pressed (2026-10-16).
                    app(\App\Support\Leads\CallTypeCorrections::class)->decide($rows);
                }
                $log->only(null);
                $withdrawn = $calls->withdrawDuplicates($now->subDays(2));
                $words = $calls->fillTranscripts();

                $this->info("{$organization->name}: filed {$filed} call(s), withdrew {$withdrawn} duplicate(s), {$words} transcript(s) from Reports.");
            });
        }

        return self::SUCCESS;
    }

    /** When the call ended: its start plus RingCentral's length of it, so far for a live one. */
    private static function endedAt(array $call): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $call['started_at'])->addSeconds((int) ($call['duration'] ?? 0));
    }

    /**
     * Whose lines: the company the connection is scoped to; the business's
     * own choice in Settings (leads.calls_company); otherwise its only company.
     */
    private static function companyFor(Organization $organization, ?SocialConnection $connection): ?string
    {
        if ($connection?->scope_type === SocialConnection::SCOPE_COMPANY) {
            return (string) $connection->scope_id;
        }
        // A branch's account is its company's (Jose, 2026-11-17: EverDoors' own
        // RingCentral, scoped to Everdoors Connecticut, was filed as A-1's).
        if ($connection?->scope_type === SocialConnection::SCOPE_FRANCHISE) {
            return \App\Models\Franchise::query()->whereKey($connection->scope_id)->value('company_id');
        }
        $chosen = (string) (app(\App\Support\Settings\SettingsResolver::class)->get('leads.calls_company', $organization) ?? '');
        if ($chosen !== '' && Company::query()->whereKey($chosen)->exists()) {
            return $chosen;
        }
        $companies = Company::query()->pluck('id');

        return $companies->count() === 1 ? (string) $companies->first() : null;
    }
}
