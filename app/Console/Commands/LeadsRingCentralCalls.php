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
        $start = $now->subDays(max(1, (int) $this->option('days')) - 1)->startOfDay();
        // Two minutes, not ten: measured 2026-10-16, calls landed 10-19 minutes
        // late and Jose saw Bar's call "after a while". The log lists a call
        // once it has ended; a Google copy that arrives later is withdrawn.
        $ready = $now->subMinutes(2);

        foreach ($organizations as $organization) {
            $tenant->run($organization, function () use ($log, $calls, $connections, $organization, $start, $now, $ready) {
                if (! $log->configured()) {
                    return;
                }
                $company = self::companyFor($organization, $connections->firstWhere('organization_id', $organization->getKey()));
                if ($company === null) {
                    $this->warn("{$organization->name}: which company its calls belong to is unknown; skipped.");

                    return;
                }

                $rows = array_values(array_filter($log->forPeriod($start, $now),
                    fn (array $c) => CarbonImmutable::parse((string) $c['started_at']) <= $ready));

                $filed = $calls->file($rows, $log->numberNames(), $company);
                $withdrawn = $calls->withdrawDuplicates($now->subDays(2));
                $words = $calls->fillTranscripts();

                $this->info("{$organization->name}: filed {$filed} call(s), withdrew {$withdrawn} duplicate(s), {$words} transcript(s) from Reports.");
            });
        }

        return self::SUCCESS;
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
        $chosen = (string) app(\App\Support\Settings\SettingsResolver::class)->get('leads.calls_company', $organization);
        if ($chosen !== '' && Company::query()->whereKey($chosen)->exists()) {
            return $chosen;
        }
        $companies = Company::query()->pluck('id');

        return $companies->count() === 1 ? (string) $companies->first() : null;
    }
}
