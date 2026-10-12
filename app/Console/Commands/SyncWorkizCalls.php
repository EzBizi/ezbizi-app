<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizCall;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizV2;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirror each v2 branch's Workiz phone calls into workiz_calls, oldest first
 * from the newest held less two days, or from a year back on the first run.
 * v2 stops a list at row 10,000, so a big first read finishes over runs.
 */
class SyncWorkizCalls extends Command
{
    protected $signature = 'ezbizi:sync-workiz-calls
        {--connection= : Only this Workiz connection}
        {--pages=20 : Most pages of 300 per branch per run}';

    protected $description = 'Mirror Workiz phone calls and Workiz\'s scores for them';

    public function handle(TenantContext $tenant): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get()->filter(fn (SocialConnection $c) => WorkizV2::uses($c));
        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())->get()->keyBy(fn ($o) => $o->getKey());
        $landed = 0;

        foreach ($connections as $connection) {
            $organization = $organizations[$connection->organization_id] ?? null;

            if ($organization === null) {
                continue;
            }

            $result = $tenant->run($organization, fn () => $this->sync($connection));
            $landed += $result['landed'];

            if ($result['failed'] !== null) {
                $line = 'Workiz calls: '.($connection->display_name ?: $connection->getKey()).': '.$result['failed'];
                Log::warning($line);
                $this->warn($line);
            }
        }

        $this->info($landed.' Workiz call(s) mirrored.');

        return self::SUCCESS;
    }

    /** @return array{landed: int, failed: ?string} */
    private function sync(SocialConnection $connection): array
    {
        $newest = WorkizCall::query()->where('franchise_id', $connection->scope_id)->max('called_at');
        $since = $newest === null ? now()->subYear()->toDateString() : now()->parse($newest)->subDays(2)->toDateString();
        $landed = 0;

        for ($page = 1; $page <= (int) $this->option('pages') && $page * 300 < 10000; $page++) {
            $read = WorkizV2::send($connection, 'GET', '/calls', [
                'page' => $page, 'pageSize' => 300, 'field' => 'createdAt', 'order' => 'ASC',
                'dateProperty' => 'created', 'dateOperator' => 'next', 'date' => $since,
            ], 60);

            if ($read['refused'] !== null) {
                return ['landed' => $landed, 'failed' => $read['refused']];
            }

            $landed += $this->land($connection, array_values(array_filter($read['data']['data'] ?? [], 'is_array')));

            if (! ($read['data']['hasMore'] ?? false)) {
                break;
            }
        }

        return ['landed' => $landed, 'failed' => null];
    }

    /** @param  list<array<string, mixed>>  $calls */
    private function land(SocialConnection $connection, array $calls): int
    {
        $now = now();
        $text = fn ($v, int $max = 64) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $rows = [];

        foreach ($calls as $call) {
            if ($text($call['id'] ?? null) === null) {
                continue;
            }

            $insights = is_array($call['insights'] ?? null) ? $call['insights'] : [];
            $rows[] = [
                'id' => (new WorkizCall)->newUniqueId(),
                'organization_id' => $connection->organization_id,
                'franchise_id' => $connection->scope_id,
                'connection_id' => $connection->getKey(),
                'remote_id' => $text($call['id']),
                'direction' => $text($call['direction'] ?? null, 16),
                'from_number' => $text($call['from'] ?? null, 32),
                'to_number' => $text($call['to'] ?? null, 32),
                'duration' => is_numeric($call['duration'] ?? null) ? (int) $call['duration'] : null,
                'status' => $text($call['status'] ?? null, 32),
                'flow_name' => $text($call['flowName'] ?? null, 255),
                'client_remote_id' => $text($call['clientId'] ?? null),
                'job_remote_id' => $text($call['jobId'] ?? null),
                'lead_remote_id' => $text($call['leadId'] ?? null),
                'user_remote_id' => $text($call['answeredByUserId'] ?? null) ?? $text($call['calledByUserId'] ?? null),
                'user_name' => $text($call['answeredByUserName'] ?? null, 255) ?? $text($call['calledByUserName'] ?? null, 255),
                'sentiment' => $text($insights['sentiment'] ?? null, 16),
                'intent' => $text($insights['intent'] ?? null, 32),
                'dispatcher_score' => is_numeric($insights['dispatcherScore'] ?? null) ? max(0, min(255, (int) $insights['dispatcherScore'])) : null,
                'objection' => $text($insights['objection'] ?? null, 32),
                'pricing_discussed' => isset($insights['pricingDiscussed']) ? (bool) $insights['pricingDiscussed'] : null,
                'booked' => isset($insights['booked']) ? (bool) $insights['booked'] : null,
                'called_at' => is_string($call['createdAt'] ?? null) && preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d(:\d\d)?/', $call['createdAt'], $m) ? str_replace('T', ' ', $m[0]) : null,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('workiz_calls')->upsert($rows, ['franchise_id', 'remote_id'],
                array_values(array_diff(array_keys($rows[0]), ['id', 'organization_id', 'franchise_id', 'remote_id', 'created_at'])));
        }

        DB::table('workiz_calls as c')
            ->join('workiz_jobs as j', fn ($join) => $join->on('j.franchise_id', '=', 'c.franchise_id')->on('j.v2_id', '=', 'c.job_remote_id'))
            ->where('c.franchise_id', $connection->scope_id)->whereNull('c.job_id')->whereNotNull('j.job_id')
            ->update(['c.job_id' => DB::raw('`j`.`job_id`')]);

        return count($rows);
    }
}
