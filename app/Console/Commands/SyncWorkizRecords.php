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
use Illuminate\Support\Facades\DB;
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
        {--type=* : Only these types: invoice, payment, estimate, time_off, event, timesheet, custom_field, item}
        {--connection= : Only this Workiz connection}
        {--full : Read everything since 2023, not only what changed}
        {--pages=50 : Most pages per branch and type in one run}
        {--pause=0 : Seconds between pages}';

    protected $description = 'Mirror Workiz v2 invoices, payments, estimates, time off, events, timesheets, custom fields and items';

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
        $types = $this->option('type') ?: WorkizRecords::TYPES;

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
                $this->link($connection->scope_id);

                return ['landed' => $landed, 'failed' => $read['refused'], 'status' => $read['status']];
            }

            $rows = array_values(array_filter($read['data']['data'] ?? [], 'is_array'));

            // Timesheets come newest first with no date filter: stop at the cursor.
            $older = $type === 'timesheet'
                ? array_filter($rows, fn ($r) => (string) ($r['startDate'] ?? '') < $since)
                : [];
            $landed += $this->land($connection, $type, array_diff_key($rows, $older));

            if ($older !== [] || ! ($read['data']['hasMore'] ?? false)) {
                break;
            }

            sleep(max(0, (int) $this->option('pause')));
        }

        $this->link($connection->scope_id);

        return ['landed' => $landed, 'failed' => null, 'status' => 200];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function land(SocialConnection $connection, string $type, array $records): int
    {
        $now = now();
        $rows = [];

        foreach ($records as $record) {
            $row = WorkizRecords::row($type, $record);

            if ($row['remote_id'] === '') {
                continue;
            }

            $rows[] = [
                'id' => (new WorkizRecord)->newUniqueId(),
                'organization_id' => $connection->organization_id,
                'franchise_id' => $connection->scope_id,
                'connection_id' => $connection->getKey(),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
                'payload' => json_encode($row['payload']),
            ] + $row;
        }

        if ($rows !== []) {
            DB::table('workiz_records')->upsert($rows, ['franchise_id', 'type', 'remote_id'], [
                'connection_id', 'job_remote_id', 'user_remote_id', 'starts_at', 'ends_at', 'amount', 'amount_due',
                'status', 'remote_updated_at', 'payload', 'synced_at', 'updated_at',
            ]);
        }

        return count($rows);
    }

    /** Point records at the EzBizi job their Workiz job became, once it has one. */
    private function link(string $franchiseId): void
    {
        DB::table('workiz_records as r')
            ->join('workiz_jobs as j', fn ($join) => $join->on('j.franchise_id', '=', 'r.franchise_id')->on('j.v2_id', '=', 'r.job_remote_id'))
            ->where('r.franchise_id', $franchiseId)
            ->whereNull('r.job_id')
            ->whereNotNull('j.job_id')
            ->update(['r.job_id' => DB::raw('`j`.`job_id`')]);
    }
}
