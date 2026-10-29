<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizRecords;
use App\Support\Workiz\WorkizWebSession;
use Illuminate\Console\Command;

/**
 * The account lists no API carries, through each company's Workiz web
 * session (Jobs' Book panel, 2026-10-27): service areas with their ZIPs and
 * external companies. Measured 2026-10-27: Settings › Service Areas is
 * /ajaxc/metroAreas/index/ (ZIPs and boundary per area from
 * getBoundaryAndZip/{id}/), Settings › External Companies is /ajaxc/companies/.
 * Land as workiz_records `service_area` and `external_company` under each
 * account's branch.
 */
class SyncWorkizWebLists extends Command
{
    protected $signature = 'ezbizi:workiz-web-lists';

    protected $description = 'Read each Workiz account\'s service areas and external companies through the web session';

    public function handle(TenantContext $tenant): int
    {
        $landed = 0;

        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::WorkizWeb->value)->where('status', 'connected')->get();

        foreach ($connections as $connection) {
            $organization = Organization::query()->withoutGlobalScopes()->find($connection->organization_id);

            if ($organization !== null) {
                $landed += $tenant->run($organization, fn () => $this->connection($connection));
            }
        }

        $this->info($landed.' Workiz list row(s) read.');

        return self::SUCCESS;
    }

    private function connection(SocialConnection $connection): int
    {
        $count = 0;

        foreach (WorkizWebSession::accounts($connection) as $account => $name) {
            $franchise = WorkizWebSession::franchiseFor($connection, $account, $name);

            if ($franchise === null) {
                WorkizWebSession::note($connection, $account, 'lists', WorkizWebSession::UNMATCHED);

                continue;
            }

            $read = WorkizWebSession::inAccount($connection, $account, function () use ($connection) {
                $areas = WorkizWebSession::call($connection, '/ajaxc/metroAreas/index/');
                $companies = WorkizWebSession::call($connection, '/ajaxc/companies/');
                $refused = $areas['refused'] ?? $companies['refused'];

                if ($refused !== null) {
                    return ['refused' => $refused];
                }

                $zips = [];
                foreach ((array) ($areas['data']['data'] ?? []) as $area) {
                    $one = WorkizWebSession::call($connection, '/ajaxc/metroAreas/getBoundaryAndZip/'.rawurlencode((string) ($area['id'] ?? '')).'/');
                    $zips[(string) ($area['id'] ?? '')] = array_values(array_filter(preg_split('/[\s,;]+/', (string) ($one['data']['zipCode'] ?? '')) ?: []));
                }

                return ['refused' => null, 'areas' => (array) ($areas['data']['data'] ?? []), 'zips' => $zips, 'companies' => (array) ($companies['data']['data'] ?? [])];
            });

            $refused = $read['refused'] ?? null;
            WorkizWebSession::note($connection, $account, 'lists', $refused);

            if ($refused !== null) {
                $this->warn($name.': '.$refused);

                if (in_array($refused, [WorkizWebSession::EXPIRED, WorkizWebSession::BUSY], true)) {
                    break;
                }

                continue;
            }

            $shape = fn (string $prefix, array $rows, callable $payload) => array_values(array_map(fn (array $r) => [
                'remote_id' => $prefix.$r['id'], 'status' => isset($r['status']) ? (string) $r['status'] : null, 'payload' => $payload($r),
            ], array_filter($rows, fn ($r) => is_array($r) && isset($r['id']))));

            $count += WorkizRecords::landRows($connection, $franchise->getKey(), 'service_area',
                $shape('SA-', $read['areas'], fn (array $r) => ['name' => $r['metro_name'] ?? null, 'zips' => $read['zips'][(string) $r['id']] ?? [],
                    'index_zipcode' => $r['index_zipcode'] ?? null, 'distance_from_zipcode' => $r['distance_from_zipcode'] ?? null, 'is_default' => $r['is_default'] ?? null]));
            $count += WorkizRecords::landRows($connection, $franchise->getKey(), 'external_company',
                $shape('EC-', $read['companies'], fn (array $r) => array_diff_key($r, array_flip(WorkizRecords::UNCOPIED))));
        }

        return $count;
    }
}
