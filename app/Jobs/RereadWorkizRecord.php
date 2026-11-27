<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizRecords;
use App\Support\Workiz\WorkizV2;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Read one Workiz record again because a Workiz webhook said it changed, and
 * land what Workiz's API answers through the branch's own key -- never what
 * the webhook body said, which proves nothing about where it came from
 * (WorkizWebhookController). A record Workiz no longer has is left to the
 * sweeps, whose rule for a deletion needs two signals (SyncWorkizJobs::retire).
 */
final class RereadWorkizRecord implements ShouldQueue
{
    use Queueable;

    /** A real throw (a bug) still fails after three; 429 releases are not exceptions and do not count. */
    public int $maxExceptions = 3;

    /** Each 429 release spends an attempt, so a count ran out in one rate-limit burst (11-21); a time budget does not. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public array $backoff = [60, 300];

    private const PATHS = ['job' => '/jobs/', 'invoice' => '/invoices/', 'estimate' => '/estimates/'];

    /** @param  string  $kind  job, invoice, estimate or lead */
    public function __construct(
        public readonly string $connectionId,
        public readonly string $kind,
        public readonly string $remoteId,
    ) {}

    public function handle(TenantContext $tenant): void
    {
        $connection = SocialConnection::query()->withoutGlobalScopes()->find($this->connectionId);
        $organization = $connection === null ? null : Organization::query()->withoutGlobalScopes()->find($connection->organization_id);

        if ($organization === null || ! WorkizV2::uses($connection)) {
            return;
        }

        // Leads have their own import, a call or two a branch: run it now.
        if ($this->kind === 'lead') {
            Artisan::call('ezbizi:import-workiz-leads', ['--connection' => $connection->getKey()]);

            return;
        }

        $read = WorkizV2::send($connection, 'GET', self::PATHS[$this->kind].$this->remoteId);

        if ($read['missing']) {
            return;
        }

        if ($read['refused'] !== null) {
            // The key's limit or an outage: worth another try later.
            if (in_array($read['status'], [0, 429], true) || $read['status'] >= 500) {
                $this->release($this->backoff[min($this->attempts() - 1, 1)]);

                return;
            }

            Log::warning('Workiz webhook: '.$connection->display_name.': '.$this->kind.' '.$this->remoteId.': '.$read['refused']);

            return;
        }

        $record = $read['data']['data'] ?? $read['data'];
        $record = is_array($record) && array_is_list($record) ? ($record[0] ?? null) : $record;

        if (! is_array($record) || ($record['id'] ?? null) !== $this->remoteId) {
            return;
        }

        $tenant->run($organization, fn () => $this->kind === 'job'
            ? WorkizJob::land($connection, WorkizV2::job($record))
            : WorkizRecords::land($connection, $this->kind, [$record]));
    }
}
