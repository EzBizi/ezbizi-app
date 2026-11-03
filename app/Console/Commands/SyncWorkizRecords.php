<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizRecord;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizRecords;
use App\Support\Workiz\WorkizV2;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mirror each v2 branch's invoices, payments, estimates, time off, calendar
 * events, timesheets, custom fields and price book into `workiz_records`.
 *
 * Each run reads what changed since the newest record of that type, less two
 * days (WorkizRecords::cursor), so a run cut short resumes where the data
 * stands rather than where a saved page number says. `--full` reads from 2023.
 * A type Workiz refuses is named and the rest go on; a 429 stops that branch
 * for this run, because the limit is the key's.
 */
class SyncWorkizRecords extends Command
{
    protected $signature = 'ezbizi:sync-workiz-records
        {--type=* : Only these types: invoice, payment, estimate, time_off, event, timesheet, custom_field, item, job_type, ad_group, tag, sub_status}
        {--connection= : Only this Workiz connection}
        {--full : Read everything since 2023, not only what changed}
        {--pages=50 : Most pages per branch and type in one run}
        {--pause=0 : Seconds between pages}';

    protected $description = 'Mirror Workiz v2 invoices, payments, estimates, time off, events, timesheets, custom fields, items and each account’s own lists';

    /** The column a type's cursor is read from; none for a type read whole. */
    private const CURSOR = [
        'invoice' => 'remote_updated_at',
        'estimate' => 'remote_updated_at',
        'item' => 'remote_updated_at',
        'payment' => 'starts_at',
        'timesheet' => 'starts_at',
    ];

    public function handle(TenantContext $tenant): int
    {
        // Account lists rarely change: read them once an hour, not every ten
        // minutes (they were most of the rate-limit refusals, 11-03).
        $types = $this->option('type') ?: (now()->minute < 10 ? WorkizRecords::TYPES
            : array_values(array_diff(WorkizRecords::TYPES, WorkizRecords::LISTS)));

        if ($unknown = array_diff($types, WorkizRecords::TYPES)) {
            $this->error('Unknown type: '.implode(', ', $unknown).'.');

            return self::FAILURE;
        }

        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get()
            ->filter(fn (SocialConnection $c) => WorkizV2::uses($c));
        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())->get()->keyBy(fn ($o) => $o->getKey());

        $landed = 0;

        foreach ($connections as $connection) {
            $organization = $organizations[$connection->organization_id] ?? null;

            if ($organization === null) {
                continue;
            }

            foreach ($types as $type) {
                $result = $tenant->run($organization, fn () => $this->sync($connection, $type));
                $landed += $result['landed'];

                if ($result['failed'] !== null) {
                    $line = 'Workiz records: '.($connection->display_name ?: $connection->getKey()).' ('.$type.'): '.$result['failed'];
                    Log::warning($line);
                    $this->warn($line);

                    if ($result['status'] === 429) {
                        break;
                    }
                }
            }
        }

        $this->info($landed.' Workiz records landed.');

        return self::SUCCESS;
    }

    /**
     * @return array{landed: int, failed: ?string, status: int}
     */
    private function sync(SocialConnection $connection, string $type): array
    {
        $column = self::CURSOR[$type] ?? null;
        $newest = $column === null || $this->option('full') ? null
            : WorkizRecord::query()->where('franchise_id', $connection->scope_id)->where('type', $type)->max($column);
        $since = WorkizRecords::cursor($newest);
        $landed = 0;

        for ($page = 1; $page <= max(1, (int) $this->option('pages')); $page++) {
            $read = WorkizV2::send($connection, 'GET', WorkizRecords::path($type), WorkizRecords::query($type, $since, $page), 60);

            if ($read['refused'] !== null) {
                return ['landed' => $landed, 'failed' => $read['refused'], 'status' => $read['status']];
            }

            $rows = array_values(array_filter($read['data']['data'] ?? [], 'is_array'));

            // Timesheets come newest first with no date filter: stop at the cursor.
            $older = $type === 'timesheet'
                ? array_filter($rows, fn ($r) => (string) ($r['startDate'] ?? '') < $since)
                : [];
            $fresh = $type === 'payment' ? self::unheld($connection, array_column($rows, 'id')) : [];
            $landed += WorkizRecords::land($connection, $type, array_diff_key($rows, $older));
            self::announce($connection, $fresh);

            if ($older !== [] || ! ($read['data']['hasMore'] ?? false)) {
                break;
            }

            sleep(max(0, (int) $this->option('pause')));
        }

        return ['landed' => $landed, 'failed' => null, 'status' => 200];
    }

    /** Which of these payment ids the mirror does not hold yet. @return list<string> */
    private static function unheld(SocialConnection $connection, array $ids): array
    {
        $held = WorkizRecord::query()->where('franchise_id', $connection->scope_id)->where('type', 'payment')
            ->whereIn('remote_id', $ids ?: ['-'])->pluck('remote_id')->all();

        return array_values(array_diff(array_map('strval', $ids), $held));
    }

    /**
     * `job.payment_received`, once per payment new to the mirror, on its
     * EzBizi job (Automations, 2026-10-21: Jose's Project Management rules).
     * A payment dated more than two days back is history arriving (a first
     * read, a full sweep) and is not announced.
     */
    private static function announce(SocialConnection $connection, array $fresh): void
    {
        if ($fresh === []) {
            return;
        }

        $payments = WorkizRecord::query()->where('franchise_id', $connection->scope_id)->where('type', 'payment')
            ->whereIn('remote_id', $fresh)->whereNotNull('job_id')->where('status', 'succeeded')
            ->where('starts_at', '>=', now()->subDays(2))->get();

        foreach ($payments as $payment) {
            $job = \App\Models\Job::query()->find($payment->job_id);
            $mirror = \App\Models\WorkizJob::query()->where('job_id', $payment->job_id)->first();

            if ($job === null) {
                continue;
            }

            // Succeeded charges less refunds (Jobs, 2026-10-21); Workiz writes a refund's amount unsigned.
            $paidMinor = WorkizRecord::query()->where('job_id', $payment->job_id)->where('type', 'payment')->where('status', 'succeeded')->get()
                ->sum(fn (WorkizRecord $r) => (self::kind($r) === 'refund' ? -1 : 1) * (int) round(abs((float) $r->amount) * 100));
            $paid = $paidMinor / 100;
            $total = (float) ($mirror?->payload['JobTotalPrice'] ?? 0);
            $collector = $payment->user_remote_id === null ? null : \App\Models\WorkizTeamMember::query()
                ->where('franchise_id', $payment->franchise_id)->where('remote_id', $payment->user_remote_id)->value('name');
            app(\App\Support\Automations\EventEmitter::class)->emit('job.payment_received', $job, ['after' => [
                'amount' => (float) $payment->amount, 'paid_total' => $paid, 'job_total' => $total,
                'fully_paid' => $total > 0 && $paid >= $total, 'status' => $mirror?->status,
                'amount_minor' => (int) round(abs((float) $payment->amount) * 100), 'kind' => self::kind($payment),
                'paid_on' => $payment->starts_at?->toDateString(), 'collected_by' => $collector, 'paid_total_minor' => $paidMinor,
            ]]);
        }
    }

    private static function kind(WorkizRecord $payment): string
    {
        return strtolower((string) ($payment->payload['type'] ?? '')) === 'refund' ? 'refund' : 'charge';
    }
}
