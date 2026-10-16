<?php

namespace App\Support\Reports\Airtable;

use App\Models\AirtableRecord;
use App\Models\AirtableRecordVersion;
use App\Models\ReportSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Copies the Airtable tables an organization mapped into its reports
 * (report_sources) into airtable_records, keeping a version of every row that
 * changed and marking a row Airtable no longer has as gone (2026-10-16). Each
 * tenant's own mapping says what is copied; nothing here names a tenant.
 *
 * Runs inside the tenant; the caller sets it.
 */
class AirtableSnapshot
{
    private const API = 'https://api.airtable.com/v0';

    /**
     * @return array{tables: int, rows: int, changed: int, gone: int, failed: list<string>}
     */
    public function sync(string $organizationId, string $token): array
    {
        $done = ['tables' => 0, 'rows' => 0, 'changed' => 0, 'gone' => 0, 'failed' => []];
        $now = now();

        $mapped = ReportSource::query()->get(['base_id', 'table_id'])->groupBy('base_id')
            ->map(fn ($rows) => $rows->pluck('table_id')->unique()->values()->all());

        foreach ($mapped as $baseId => $tables) {
            $names = $this->tableNames($token, $baseId);

            foreach ($tables as $tableId) {
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
