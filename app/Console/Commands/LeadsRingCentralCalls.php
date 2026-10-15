<?php

namespace App\Console\Commands;

use App\Support\Leads\RingCentralLeadCalls;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * RingCentral calls into Leads (Jose, 2026-10-15). See RingCentralLeadCalls.
 *
 * Only calls at least ten minutes old: Google's copy of a Local Services call
 * lands within its own sync, and a call it beats is withdrawn on a later run.
 * Calls from LabelReportCalls::aiSince() on, as the AI reading is.
 */
class LeadsRingCentralCalls extends Command
{
    protected $signature = 'ezbizi:leads-ringcentral {--days=1 : days back to read, including today}';

    protected $description = 'File RingCentral calls to the business lines as leads, once per call';

    /** Whose calls: the one RingCentral account belongs to A-1 Chimney's organization. */
    public const COMPANY = '019fd712-75d5-71ed-a0e2-1d5ff11bd803';

    public function handle(RingCentralCallLog $log, RingCentralLeadCalls $calls, TenantContext $tenant): int
    {
        if (! $log->configured()) {
            $this->info('RingCentral is not configured.');

            return self::SUCCESS;
        }

        $organization = TranscribeReportCalls::reportOrganization(null);
        if ($organization === null) {
            $this->warn('No single organization has Workiz connected; whose calls these are is unknown.');

            return self::FAILURE;
        }

        $zone = (string) config('app.timezone');
        $now = CarbonImmutable::now($zone);
        $start = $now->subDays(max(1, (int) $this->option('days')) - 1)->startOfDay();
        $ready = $now->subMinutes(10);

        $rows = array_values(array_filter($log->forPeriod($start, $now),
            fn (array $c) => CarbonImmutable::parse((string) $c['started_at']) <= $ready));

        [$filed, $withdrawn, $words] = $tenant->run($organization, fn () => [
            $calls->file($rows, $log->numberNames(), self::COMPANY),
            $calls->withdrawDuplicates($now->subDays(2)),
            $calls->fillTranscripts(),
        ]);

        $this->info("Filed {$filed} call(s), withdrew {$withdrawn} duplicate(s), {$words} transcript(s) from Reports.");

        return self::SUCCESS;
    }
}
