<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizRecords;
use App\Support\Workiz\WorkizWebSession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Workiz's own Payments report, per account, read through a signed-in web
 * session on the box: the deposit figures v2 cannot give (40% of v2's
 * payments name a collector it never returns). Lands as workiz_records of
 * type `web_payment`, under the franchise the tenant mapped each account to;
 * an unmapped account is named and skipped.
 */
class SyncWorkizWebPayments extends Command
{
    protected $signature = 'ezbizi:workiz-web-payments
        {--from= : First day (Y-m-d), default yesterday}
        {--to= : Last day (Y-m-d), default today}';

    protected $description = 'Read Workiz\'s Payments report through each tenant\'s Workiz web session';

    public const TYPE = 'web_payment';

    public function handle(TenantContext $tenant): int
    {
        $from = Carbon::parse($this->option('from') ?? now()->subDay()->toDateString());
        $to = Carbon::parse($this->option('to') ?? now()->toDateString());
        $range = $from->format('j.n.y').'_'.$to->format('j.n.y');
        $landed = 0;

        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::WorkizWeb->value)->where('status', 'connected')->get();

        foreach ($connections as $connection) {
            $organization = Organization::query()->withoutGlobalScopes()->find($connection->organization_id);

            if ($organization === null) {
                continue;
            }

            $landed += $tenant->run($organization, function () use ($connection, $range) {
                $count = 0;

                foreach (WorkizWebSession::accounts($connection) as $account => $name) {
                    $franchise = WorkizWebSession::franchiseFor($connection, $account, $name);

                    if ($franchise === null) {
                        $this->line('Workiz web payments: '.$name.' is not mapped to a branch; skipped.');
                        WorkizWebSession::note($connection, $account, 'payments', WorkizWebSession::UNMATCHED);

                        continue;
                    }

                    $read = WorkizWebSession::inAccount($connection, $account, fn () => WorkizWebSession::call($connection, '/ajaxc/payments/getPaymentsReport/', [
                        'page' => 0, 'pageSize' => 20000, 'sorted' => [], 'filtered' => [], 'sSearch' => '', 'search_query' => '',
                        'date_query' => $range, 'final_q' => $range, 'timeQueryChanged' => true, 'pickerParams' => (object) [],
                        'react' => true, 'withCsv' => false, 'pickerOn' => true, 'filters' => ['payment_type' => []],
                    ]));

                    WorkizWebSession::note($connection, $account, 'payments', $read['refused']);

                    if ($read['refused'] !== null) {
                        Log::warning('Workiz web payments: '.$name.': '.$read['refused']);
                        $this->warn($name.': '.$read['refused']);

                        if ($read['refused'] === WorkizWebSession::EXPIRED) {
                            return $count;
                        }

                        continue;
                    }

                    $rows = array_values(array_filter((array) ($read['data']['aaData'] ?? []),
                        fn ($row) => is_array($row) && ($row['isTotalsRow'] ?? false) !== true && isset($row['id'])));

                    $count += WorkizRecords::landRows($connection, $franchise->getKey(), self::TYPE, array_map(fn (array $row) => [
                        'remote_id' => 'WP-'.$row['id'],
                        'job_remote_id' => null,
                        'user_remote_id' => null,
                        'starts_at' => self::moment($row['date'] ?? $row['payment_date'] ?? null),
                        'amount' => is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : null,
                        'status' => isset($row['status']) ? mb_substr((string) $row['status'], 0, 64) : null,
                        'payload' => array_diff_key($row, array_flip(WorkizRecords::UNCOPIED)),
                    ], $rows));
                }

                return $count;
            });
        }

        $this->info($landed.' Workiz payment row(s) read.');

        return self::SUCCESS;
    }

    private static function moment(mixed $value): ?string
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value)->format('Y-m-d H:i:s') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
