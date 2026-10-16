<?php

namespace App\Support\Reports\Airtable;

use App\Models\AirtableRecord;
use App\Models\AirtableRecordVersion;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Copies A-1's Airtable tables into airtable_records, keeping a version of
 * every row that changed (the A1 Leads takeover, 2026-10-16). The table list
 * is the set the A1 Leads reports were contracted to read (its
 * config/airtable-field-contracts.json, 36 tables) plus the split Refunds,
 * Disputes and Checks Returns tables. Debt & Loans is never read.
 *
 * Runs inside the tenant; the caller sets it.
 */
class AirtableSnapshot
{
    /** base id => [table id => what it is] */
    public const TABLES = [
        // Deposits: one table per company.
        'appadTMQltMGmGrse' => [
            'tbljbx2p8nsQ7adRL' => 'deposits', 'tbl81ZTowT57Ex9UD' => 'deposits', 'tblGzCgHbjzrH3gfO' => 'deposits',
            'tblMvDkzefX4qqq5W' => 'deposits', 'tblxLlYqi1uMsjEUc' => 'deposits', 'tblxmCTEwSXPahQyd' => 'deposits',
            'tblhykuZPesNQgy1i' => 'deposits', 'tblz2mqN2mssahf7j' => 'deposits', 'tblBi6eDJL6MDVRSK' => 'deposits',
        ],
        // Invoices System: Ace/Angi marketing, HQ expenses, weekly data, monthly bills, lease and reviews.
        'app6ZZgY9nHX8mMih' => [
            'tblqhXDymSAR6S3G6' => 'marketing', 'tblXCVvrqnN304ERY' => 'marketing', 'tblxgh8Ow0XpuKg5B' => 'marketing',
            'tblmV1sk6qs4QbUWU' => 'hq_expenses', 'tblDaxgxfcaUBeQPT' => 'office_per_job', 'tblzC1laiwxmLw50S' => 'weekly_data',
            'tblC2qB7ew6in1YTC' => 'companies', 'tbl0xxoIuToQWIYmN' => 'monthly_bills', 'tblQRWVs4Xz53H8Xf' => 'lease_reviews',
        ],
        // P&L: one table per company (MA's added after the contract, measured 2026-10-16).
        'appWhbwht0xgB5Yut' => [
            'tblDUxGZlTStCb2UH' => 'pnl', 'tblhqHFPpYAiW8WPw' => 'pnl', 'tbltOW4Ye5LqwKaLa' => 'pnl', 'tblpKJk2XEMlkX4lG' => 'pnl',
            'tblMZBfVXxXmNzNTd' => 'pnl', 'tbl1YNRbZeLgaJChN' => 'pnl', 'tblENNwJUcW7ySb8P' => 'pnl', 'tblifiOssYgLOaz3X' => 'pnl',
            'tbl06qeoIY5E7PfxH' => 'pnl', 'tblunO193lu8YMcIq' => 'pnl', 'tblhIqc5V5yH6m0H0' => 'pnl',
        ],
        // Repair Teams: one table per region.
        'appzD9I7nNkALmw4l' => [
            'tbliTYwmQl2KXzoxh' => 'repair', 'tblLaCAXBIFj2H5hu' => 'repair', 'tblDUxGZlTStCb2UH' => 'repair', 'tblhW7kHAtYzleqde' => 'repair',
            'tblM8GHLc5ssy12nj' => 'repair', 'tblhc4CJLOkMCNopv' => 'repair', 'tbl7GHGQpvlyWg4dG' => 'repair', 'tblo2IX58b5GWiU7E' => 'repair', 'tbl9IYzaoNxKAZJNx' => 'repair',
        ],
        // Refunds, Disputes and Checks Returns (never Debt & Loans).
        'appe1vSSRRvKToShq' => [
            'tblxCKagahNfmjaSQ' => 'refunds', 'tblxkLPCe759GoPzn' => 'disputes', 'tblpTKRRhUrirBRpy' => 'check_returns',
        ],
    ];

    private const API = 'https://api.airtable.com/v0';

    /**
     * @return array{tables: int, rows: int, changed: int, gone: int, failed: list<string>}
     */
    public function sync(string $organizationId, string $token): array
    {
        $done = ['tables' => 0, 'rows' => 0, 'changed' => 0, 'gone' => 0, 'failed' => []];
        $now = now();

        foreach (self::TABLES as $baseId => $tables) {
            $names = $this->tableNames($token, $baseId);

            foreach (array_keys($tables) as $tableId) {
                try {
                    $rows = $this->rows($token, $baseId, $tableId);
                } catch (RuntimeException $e) {
                    // One table failing keeps the others' copies fresh; the
                    // failed one keeps its last copy, never a partial one.
                    $done['failed'][] = "$baseId/$tableId: ".$e->getMessage();

                    continue;
                }

                $done['tables']++;
                $seen = [];
                $existing = AirtableRecord::query()->where('base_id', $baseId)->where('table_id', $tableId)->get()->keyBy('record_id');

                foreach ($rows as $row) {
                    $seen[] = $row['id'];
                    $fields = (array) ($row['fields'] ?? []);
                    ksort($fields);
                    $hash = hash('sha256', (string) json_encode($fields));
                    $current = $existing->get($row['id']);

                    if ($current !== null && $current->fields_hash === $hash && $current->gone_at === null) {
                        $done['rows']++;

                        continue;
                    }

                    if ($current !== null && $current->fields_hash !== $hash) {
                        AirtableRecordVersion::query()->create([
                            'organization_id' => $organizationId,
                            'airtable_record_id' => $current->id,
                            'fields' => $current->fields,
                            'seen_at' => $current->fetched_at,
                        ]);
                        $done['changed']++;
                    }

                    AirtableRecord::query()->updateOrCreate(
                        ['organization_id' => $organizationId, 'base_id' => $baseId, 'table_id' => $tableId, 'record_id' => $row['id']],
                        [
                            'table_name' => $names[$tableId] ?? $tableId,
                            'fields' => $fields,
                            'fields_hash' => $hash,
                            'record_created_at' => isset($row['createdTime']) ? now()->parse($row['createdTime']) : null,
                            'fetched_at' => $now,
                            'gone_at' => null,
                        ],
                    );
                    $done['rows']++;
                }

                // Unchanged rows were not rewritten; one update marks them seen.
                AirtableRecord::query()->where('base_id', $baseId)->where('table_id', $tableId)
                    ->whereIn('record_id', $seen)->update(['fetched_at' => $now]);

                $done['gone'] += AirtableRecord::query()->where('base_id', $baseId)->where('table_id', $tableId)
                    ->whereNull('gone_at')->whereNotIn('record_id', $seen)->update(['gone_at' => $now]);
            }
        }

        return $done;
    }

    /** @return array<string, string> table id => name */
    private function tableNames(string $token, string $baseId): array
    {
        $response = Http::withToken($token)->acceptJson()->get(self::API.'/meta/bases/'.$baseId.'/tables');

        return $response->successful()
            ? collect((array) $response->json('tables'))->mapWithKeys(fn ($t) => [(string) $t['id'] => (string) $t['name']])->all()
            : [];
    }

    /** @return list<array{id: string, createdTime?: string, fields?: array<string, mixed>}> */
    private function rows(string $token, string $baseId, string $tableId): array
    {
        $out = [];
        $offset = null;

        do {
            $response = Http::withToken($token)->acceptJson()
                ->get(self::API.'/'.$baseId.'/'.$tableId, array_filter(['pageSize' => 100, 'offset' => $offset]));

            if ($response->failed()) {
                throw new RuntimeException('Airtable refused the request ('.$response->status().').');
            }

            array_push($out, ...(array) $response->json('records'));
            $offset = $response->json('offset');
        } while ($offset !== null);

        return $out;
    }
}
