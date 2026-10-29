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

/**
 * Workiz's own Sales report, per account, through the web session: each job's
 * total, cost, profit, labour, card fees, technician parts and paid amount
 * (A1 Leads takeover row 3, 2026-10-29). No API carries profit or costs.
 * Measured 2026-10-29 on Nationwide September: 110 jobs, profit 116,924.33.
 * Lands as workiz_records `web_sale`, one per job (remote_id WS-<job id>),
 * under the account's branch; a later read replaces it.
 *
 * Commissions: Workiz's /crm/commissions/ answers an empty list for A-1 (no
 * Performance Pay configured), so there is nothing to read there.
 */
class SyncWorkizWebSales extends Command
{
    protected $signature = 'ezbizi:workiz-web-sales
        {--from= : First day (Y-m-d), default 7 days ago}
        {--to= : Last day (Y-m-d), default today}';

    protected $description = 'Read Workiz\'s Sales report (profit and costs per job) through each company\'s web session';

    public const TYPE = 'web_sale';

    /** Kept: money and the job's own labels. Client names and contacts are not. */
    private const KEEP = ['job_id', 'job_uuid', 'job_serial', 'status', 'sub_status', 'total_price', 'sub_total', 'due', 'cost', 'tax_amount', 'profit',
        'tech_parts', 'tip_amount', 'paid_amount', 'labour_cost', 'card_expenses', 'invoice_serial', 'metro', 'job_date', 'job_end_date', 'job_created',
        'job_type', 'ad_group'];

    public function handle(TenantContext $tenant): int
    {
        $from = Carbon::parse($this->option('from') ?? now()->subDays(7)->toDateString());
        $to = Carbon::parse($this->option('to') ?? now()->toDateString());
        $range = $from->format('j.n.y').'_'.$to->format('j.n.y');
        $landed = 0;

        foreach (SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::WorkizWeb->value)->where('status', 'connected')->get() as $connection) {
            $organization = Organization::query()->withoutGlobalScopes()->find($connection->organization_id);

            if ($organization !== null) {
                $landed += $tenant->run($organization, fn () => $this->connection($connection, $range));
            }
        }

        $this->info($landed.' Workiz sale row(s) read.');

        return self::SUCCESS;
    }

    private function connection(SocialConnection $connection, string $range): int
    {
        $count = 0;

        foreach (WorkizWebSession::accounts($connection) as $account => $name) {
            $franchise = WorkizWebSession::franchiseFor($connection, $account, $name);

            if ($franchise === null) {
                continue;
            }

            $read = WorkizWebSession::inAccount($connection, $account, fn () => WorkizWebSession::call($connection, '/ajaxc/sales/reportTableData/', [
                'page' => 0, 'pageSize' => 20000, 'sorted' => [], 'filtered' => [], 'sSearch' => '', 'search_query' => '',
                'date_query' => $range, 'final_q' => $range, 'timeQueryChanged' => true, 'pickerParams' => (object) [],
                'react' => true, 'withCsv' => false, 'pickerOn' => true,
            ]));

            WorkizWebSession::note($connection, $account, 'sales', $read['refused']);

            if ($read['refused'] !== null) {
                $this->warn($name.': '.$read['refused']);

                if (in_array($read['refused'], [WorkizWebSession::EXPIRED, WorkizWebSession::BUSY], true)) {
                    break;
                }

                continue;
            }

            $rows = array_values(array_filter((array) ($read['data']['aaData'] ?? []),
                fn ($row) => is_array($row) && ($row['isTotalsRow'] ?? false) !== true && isset($row['job_id'])));

            $count += WorkizRecords::landRows($connection, $franchise->getKey(), self::TYPE, array_map(fn (array $row) => [
                'remote_id' => 'WS-'.$row['job_id'],
                'starts_at' => self::moment($row['job_date'] ?? null),
                'amount' => is_numeric($row['total_price'] ?? null) ? (float) $row['total_price'] : null,
                'status' => isset($row['status']) ? mb_substr((string) $row['status'], 0, 64) : null,
                'payload' => array_intersect_key($row, array_flip(self::KEEP)),
            ], $rows));

            // Each sale onto the EzBizi job its Workiz job became (the mirror keys a job by its UUID).
            \Illuminate\Support\Facades\DB::table('workiz_records as r')
                ->join('workiz_jobs as j', fn ($join) => $join->on('j.franchise_id', '=', 'r.franchise_id')
                    ->whereRaw("j.remote_id = JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.job_uuid'))"))
                ->where('r.franchise_id', $franchise->getKey())->where('r.type', self::TYPE)->whereNull('r.job_id')->whereNotNull('j.job_id')
                ->update(['r.job_id' => \Illuminate\Support\Facades\DB::raw('`j`.`job_id`')]);
        }

        return $count;
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
