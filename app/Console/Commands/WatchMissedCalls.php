<?php

namespace App\Console\Commands;

use App\Support\Automations\EventEmitter;
use App\Support\Notifications\LeadNotificationText;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Raises reports.call.missed for each new call the call report counts as
 * missed (Bar, 2026-10-07: a notification "whenever we're receiving a missed
 * call from the report"). Jose: "Yes, include voicemail"; blocked and rejected
 * calls, and spam that sat in the menu, are not missed calls.
 *
 * Who hears about it, and in what words, is the automation rule's
 * (Notifications); this only says that it happened, once per call.
 */
class WatchMissedCalls extends Command
{
    protected $signature = 'ezbizi:watch-missed-calls
        {--organization= : whose calls; defaults to the one organization with Workiz connected}';

    protected $description = 'Raise reports.call.missed for each new missed call in RingCentral';

    /** A call older than this is history, not news: no burst of the whole day after a deploy or an outage. */
    private const FRESH_MINUTES = 30;

    /** Brands by the start of the line the call reached. Anything else, a person's own line included, is no company. */
    private const COMPANIES = [
        'A1' => 'A-1 Chimney',
        'Speedy Chimney Sweep' => 'Speedy Chimney Sweep',
        'Ace Fireplace' => 'Ace Fireplace',
        'TX Chimney & Chimney Services Pro' => 'TX Chimney & Chimney Services Pro',
    ];

    public function handle(RingCentralCallLog $log, EventEmitter $events, TenantContext $tenant): int
    {
        if (! $log->configured()) {
            return self::SUCCESS;
        }

        $organization = TranscribeReportCalls::reportOrganization($this->option('organization'));

        if ($organization === null) {
            $this->warn('Which organization? Pass --organization.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now((string) config('app.timezone'));
        $raised = 0;

        foreach ($log->forPeriod($now->startOfDay(), $now) as $call) {
            if ($call['answered_by'] !== null
                || ! in_array($call['result'], ['Missed', 'Voicemail'], true)
                || $call['id'] === null
                || CarbonImmutable::parse((string) $call['started_at'])->lt($now->subMinutes(self::FRESH_MINUTES))
                // Atomic: the first run to see the call claims it.
                || ! Cache::add('missed-call-raised:'.$call['id'], 1, now()->addDays(2))) {
                continue;
            }

            $company = $this->company($call['queue']);

            $tenant->run($organization, fn () => $events->emit('reports.call.missed', null, [
                'headline' => LeadNotificationText::missedCallHeadline($company),
                'details' => LeadNotificationText::missedCallDetails($call['from'], $call['queue'], voicemail: $call['result'] === 'Voicemail'),
                'call_id' => $call['id'],
                'started_at' => $call['started_at'],
                'from' => $call['from'],
                'to_number' => $call['to_number'],
                // ponytail: the queue stands in for our number's name until
                // the RingCentral app is granted ReadAccounts.
                'to_name' => $call['queue'],
                'queue' => $call['queue'],
                'company' => $company,
                'result' => $call['result'],
            ]));

            $raised++;
        }

        $this->info("Raised {$raised} missed call(s).");

        return self::SUCCESS;
    }

    private function company(?string $line): ?string
    {
        foreach (self::COMPANIES as $prefix => $company) {
            if ($line !== null && str_starts_with($line, $prefix)) {
                return $company;
            }
        }

        return null;
    }
}
