<?php

namespace App\Console\Commands;

use App\Models\Company;
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

    /** Brands by the start of the line the call reached (setting phone.ringcentral_companies). Anything else is no company. */
    private array $companies = [];

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

        // A call older than this is history, not news: no burst of the whole day after a deploy or an outage.
        $fresh = (int) $tenant->run($organization, fn () => app(\App\Support\Settings\SettingsResolver::class)->get('phone.missed_call_fresh_minutes', $organization));
        $this->companies = (array) $tenant->run($organization, fn () => app(\App\Support\Settings\SettingsResolver::class)->get('phone.ringcentral_companies', $organization));
        $now = CarbonImmutable::now((string) config('app.timezone'));
        // Every call on this account is its company's.
        $companyId = $tenant->run($organization, fn () => $log->companyId());
        $companyName = $companyId === null ? null : Company::query()->withoutGlobalScopes()->whereKey($companyId)->value('name');
        $names = null;
        $raised = 0;

        // From phone.missed_call_fresh_minutes ago, not from midnight: a call at 23:59 is still
        // read by the run at 00:01. Yesterday then comes from its day cache.
        foreach ($log->forPeriod($now->subMinutes($fresh), $now) as $call) {
            if ($call['answered_by'] !== null
                || ! in_array($call['result'], ['Missed', 'Voicemail'], true)
                || $call['id'] === null
                || CarbonImmutable::parse((string) $call['started_at'])->lt($now->subMinutes($fresh))
                // Atomic: the first run to see the call claims it.
                || ! Cache::add('missed-call-raised:'.$call['id'], 1, now()->addDays(2))) {
                continue;
            }

            $company = $this->company($call['queue']) ?? $companyName;
            // RingCentral's label for the number they dialled; the queue when it has none.
            $names ??= $log->numberNames();
            $line = $names[$call['to_number']] ?? $call['queue'];

            $tenant->run($organization, fn () => $events->emit('reports.call.missed', null, [
                'headline' => LeadNotificationText::missedCallHeadline($company),
                'details' => LeadNotificationText::missedCallDetails($call['from'], $line, voicemail: $call['result'] === 'Voicemail'),
                'call_id' => $call['id'],
                'started_at' => $call['started_at'],
                'from' => $call['from'],
                'to_number' => $call['to_number'],
                'to_name' => $line,
                'queue' => $call['queue'],
                'company' => $company,
                // Which company's line it was, for a per-person company filter (2026-10-23).
                'company_id' => $companyId,
                // The branch the line belongs to: its number's place (Leads › Manage › Phone lines), else its market code.
                'branch' => $tenant->run($organization, fn () => self::branchOf((string) $call['to_number'], (string) $line)),
                'result' => $call['result'],
            ]));

            $raised++;
        }

        $this->info("Raised {$raised} missed call(s).");

        return self::SUCCESS;
    }

    private function company(?string $line): ?string
    {
        foreach ($this->companies as $prefix => $company) {
            if ($line !== null && $prefix !== '' && str_starts_with($line, (string) $prefix)) {
                return (string) $company;
            }
        }

        return null;
    }

    /** The branch name a dialed line belongs to, or null. */
    public static function branchOf(string $number, string $line): ?string
    {
        $settings = \App\Support\Settings\SettingsResolver::class;
        $branches = \App\Models\Franchise::query()->get(['id', 'name', 'company_id']);
        [, $franchiseId] = \App\Support\Leads\RingCentralLeadCalls::placeOf((string) (((array) $settings::forOrganization('leads.line_numbers'))[$number] ?? ''), $branches);
        if ($franchiseId !== null) {
            return $branches->firstWhere('id', $franchiseId)?->name;
        }
        $markets = array_change_key_case((array) $settings::forOrganization('leads.line_markets'), CASE_UPPER);
        $brands = array_change_key_case((array) $settings::forOrganization('leads.line_brands'), CASE_UPPER);

        return $markets[\App\Support\Leads\RingCentralLeadCalls::market($line, $brands)] ?? null;
    }
}
