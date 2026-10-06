<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizClient;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizV2;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Give each mirrored Workiz client its v2 id (CL-…), which is the only way v2
 * reads a client's notes. v2 lists clients newest number first, so a run
 * stops at the first page whose clients all have their id already; `--full`
 * reads every page.
 */
class SyncWorkizClientIds extends Command
{
    protected $signature = 'ezbizi:sync-workiz-client-ids
        {--connection= : Only this Workiz connection}
        {--full : Read every client, not only the newest}
        {--pause=0 : Seconds between pages}';

    protected $description = 'Record each Workiz client\'s v2 id';

    public function handle(TenantContext $tenant): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get()->filter(fn (SocialConnection $c) => WorkizV2::uses($c));
        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())->get()->keyBy(fn ($o) => $o->getKey());
        $matched = 0;

        foreach ($connections as $connection) {
            $organization = $organizations[$connection->organization_id] ?? null;

            if ($organization === null) {
                continue;
            }

            $result = $tenant->run($organization, fn () => $this->sync($connection));
            $matched += $result['matched'];

            if ($result['failed'] !== null) {
                $line = 'Workiz client ids: '.($connection->display_name ?: $connection->getKey()).': '.$result['failed'];
                Log::warning($line);
                $this->warn($line);
            }
        }

        $this->info($matched.' Workiz client(s) given their v2 id.');

        return self::SUCCESS;
    }

    /**
     * v2 lists no further than row 10,000 of the client list: "page * pageSize
     * must be less than 10000. Narrow the results with filters" (New Jersey,
     * about 13,000 clients, 2026-10-06). So a full read walks one year of
     * clients at a time, by when Workiz created them.
     *
     * ponytail: a single year past 10,000 clients would need months instead.
     *
     * @return array{matched: int, failed: ?string}
     */
    private function sync(SocialConnection $connection): array
    {
        if (! $this->option('full')) {
            return $this->pages($connection, []);
        }

        $matched = 0;

        for ($year = 2014; $year <= now()->year; $year++) {
            $result = $this->pages($connection, ['dateProperty' => 'created', 'dateOperator' => 'between', 'date' => $year.'-01-01_'.$year.'-12-31']);
            $matched += $result['matched'];

            if ($result['failed'] !== null) {
                return ['matched' => $matched, 'failed' => $year.': '.$result['failed']];
            }
        }

        return ['matched' => $matched, 'failed' => null];
    }

    /**
     * @param  array<string, string>  $filter
     * @return array{matched: int, failed: ?string}
     */
    private function pages(SocialConnection $connection, array $filter): array
    {
        $matched = 0;

        for ($page = 1; ; $page++) {
            $read = WorkizV2::send($connection, 'GET', '/clients', ['page' => $page, 'pageSize' => 300, 'field' => 'serialId', 'order' => 'DESC'] + $filter, 60);

            if ($read['refused'] !== null) {
                return ['matched' => $matched, 'failed' => $read['refused']];
            }

            $ids = collect($read['data']['data'] ?? [])->filter(fn ($c) => is_array($c) && isset($c['id'], $c['serialId']))
                ->mapWithKeys(fn ($c) => [(string) $c['serialId'] => (string) $c['id']]);
            $held = WorkizClient::query()->where('franchise_id', $connection->scope_id)
                ->whereIn('client_id', $ids->keys())->get(['id', 'organization_id', 'client_id', 'v2_id']);
            $new = $held->filter(fn (WorkizClient $c) => $c->v2_id !== $ids[(string) $c->client_id]);

            foreach ($new as $client) {
                $client->forceFill(['v2_id' => $ids[(string) $client->client_id]])->save();
            }

            $matched += $new->count();

            // Newest first: a page with nothing new means the rest is done, and
            // an ordinary run reads five pages at most.
            if (! ($read['data']['hasMore'] ?? false) || (! $this->option('full') && (($held->isNotEmpty() && $new->isEmpty()) || $page >= 5))) {
                return ['matched' => $matched, 'failed' => null];
            }

            sleep(max(0, (int) $this->option('pause')));
        }
    }
}
