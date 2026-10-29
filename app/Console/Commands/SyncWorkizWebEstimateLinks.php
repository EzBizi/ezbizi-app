<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizRecord;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizWebSession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The client-portal links Workiz makes for each estimate: the page where the
 * client approves and pays the deposit (Jose, 2026-10-21). No API carries
 * them; the web app's own estimate read does. Measured 2026-10-21 on
 * Nationwide: estimateReport lists estimates (sortable by status_updated_at),
 * getEstimateById/{id} carries estimate_portal_url (the wrkz.me link Workiz's
 * own email and text send), estimate_link (clientPortal/estimate/ES-<v2 id>:
 * <client hash>) and proposal_portal_url. The links exist before an estimate
 * is sent. Stored on the v2 mirror's estimate row, matched by its ES- id.
 */
class SyncWorkizWebEstimateLinks extends Command
{
    protected $signature = 'ezbizi:workiz-web-estimate-links
        {--days=2 : Estimates whose status changed within this many days}
        {--pages=20 : At most this many pages of 100 per account}';

    protected $description = 'Read Workiz\'s client-portal estimate links through each company\'s Workiz web session';

    public function handle(TenantContext $tenant): int
    {
        $since = now()->subDays((int) $this->option('days'));
        $read = 0;

        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::WorkizWeb->value)->where('status', 'connected')->get();

        foreach ($connections as $connection) {
            $organization = Organization::query()->withoutGlobalScopes()->find($connection->organization_id);

            if ($organization !== null) {
                $read += $tenant->run($organization, fn () => $this->connection($connection, $since));
            }
        }

        $this->info($read.' Workiz estimate link(s) read.');

        return self::SUCCESS;
    }

    private function connection(SocialConnection $connection, Carbon $since): int
    {
        $count = 0;

        foreach (WorkizWebSession::accounts($connection) as $account => $name) {
            $franchise = WorkizWebSession::franchiseFor($connection, $account, $name);

            if ($franchise === null) {
                WorkizWebSession::note($connection, $account, 'estimates', WorkizWebSession::UNMATCHED);

                continue;
            }

            $refused = WorkizWebSession::inAccount($connection, $account, function () use ($connection, $franchise, $since, &$count) {
                for ($page = 0; $page < (int) $this->option('pages'); $page++) {
                    $list = WorkizWebSession::call($connection, '/ajaxc/estimate/estimateReport/', [
                        'page' => $page, 'pageSize' => 100, 'sorted' => [['id' => 'status_updated_at', 'desc' => true]], 'filtered' => [], 'react' => true,
                    ]);

                    if ($list['refused'] !== null) {
                        return $list['refused'];
                    }

                    $rows = (array) ($list['data']['aaData'] ?? []);

                    foreach ($rows as $row) {
                        if (Carbon::parse((string) ($row['status_updated_at'] ?? '2000-01-01'))->lt($since)) {
                            return null;
                        }

                        $one = WorkizWebSession::call($connection, '/ajaxc/estimate/getEstimateById/'.rawurlencode((string) $row['id']).'/', []);
                        $links = self::links(is_string($one['data']) ? $one['data'] : (string) json_encode($one['data']));

                        if ($one['refused'] === null && $links !== null && preg_match('#/estimate/(ES-[A-Za-z0-9]+):#', $links['estimate'] ?? '', $es)) {
                            $count += WorkizRecord::query()->where('franchise_id', $franchise->getKey())->where('type', 'estimate')
                                ->where('remote_id', $es[1])->update(['links' => json_encode($links), 'links_read_at' => now()]);
                        }
                    }

                    if (count($rows) < 100) {
                        return null;
                    }
                }

                return null;
            });

            // inAccount answers its own refusal to open the account as a call result.
            $refused = is_array($refused) ? $refused['refused'] : $refused;
            WorkizWebSession::note($connection, $account, 'estimates', $refused);

            if ($refused !== null) {
                $this->warn($name.': '.$refused);

                if (in_array($refused, [WorkizWebSession::EXPIRED, WorkizWebSession::BUSY], true)) {
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * The three links, found by key anywhere in the answer (its nesting is
     * Workiz's to change).
     *
     * @return array{portal: ?string, estimate: ?string, proposal: ?string}|null
     */
    public static function links(string $body): ?array
    {
        $find = fn (string $key) => preg_match('/"'.$key.'"\s*:\s*"(https:[^"]+)"/', $body, $m) ? stripslashes($m[1]) : null;
        $links = ['portal' => $find('estimate_portal_url'), 'estimate' => $find('estimate_link'), 'proposal' => $find('proposal_portal_url')];

        return array_filter($links) === [] ? null : $links;
    }
}
