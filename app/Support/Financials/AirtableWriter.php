<?php

namespace App\Support\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialField;
use App\Models\FinancialRecord;
use App\Models\FinancialTable;
use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * The EzBizi → Airtable half of a mirrored table (Jose, 2026-10-21: "two way
 * sync ... remember that the idea is to replace airtable"). An edit is written
 * to Airtable first; only what Airtable accepted is kept here. Airtable →
 * EzBizi keeps coming in with the hourly copy.
 *
 * Conflicts are per cell: before writing, the record is read live, and a cell
 * that changed in Airtable since our copy is not overwritten — it is reported
 * with both values and our copy takes Airtable's. Other cells still save.
 * Fields Airtable computes (formulas, rollups, lookups, counts, created/modified
 * times, buttons, linked records, files) are never written.
 */
final class AirtableWriter
{
    private const API = 'https://api.airtable.com/v0';

    public const COMPUTED = [
        'formula', 'rollup', 'lookup', 'multipleLookupValues', 'count', 'autoNumber',
        'createdTime', 'lastModifiedTime', 'createdBy', 'lastModifiedBy', 'button',
        'multipleRecordLinks', 'multipleAttachments', 'externalSyncSource', 'aiText',
    ];

    /** Airtable's name for each column it computes, for this table. @return list<string> */
    public static function computedFields(FinancialTable $table): array
    {
        $key = "airtable-computed:{$table->source_base_id}:{$table->source_table_id}";
        if (is_array($known = Cache::get($key))) {
            return $known;
        }
        // Not connected or unreachable: nothing known to be computed, and not
        // remembered, so the next look tries again. A write would be refused
        // by the connection check anyway.
        try {
            $schema = self::http($table)->get(self::API."/meta/bases/{$table->source_base_id}/tables");
        } catch (ValidationException|ConnectionException) {
            return [];
        }
        if ($schema->failed()) {
            return [];
        }
        $mine = collect((array) $schema->json('tables'))->firstWhere('id', $table->source_table_id) ?? [];
        $computed = collect($mine['fields'] ?? [])->filter(fn ($f) => in_array($f['type'] ?? '', self::COMPUTED, true))->pluck('name')->values()->all();
        Cache::put($key, $computed, 3600);

        return $computed;
    }

    /**
     * Writes cell changes on existing records.
     *
     * @param  array<string, array<string, mixed>>  $changes  record id => [field id => clean value]
     * @return list<array{record: string, field: string, theirs: mixed, yours: mixed}> the cells not written because Airtable changed them first
     */
    public static function update(FinancialTable $table, array $changes): array
    {
        $fields = $table->fields()->get()->keyBy('id');
        $computed = self::computedFields($table);
        $records = FinancialRecord::query()->whereIn('id', array_keys($changes))->get()->keyBy('id');
        $copies = AirtableRecord::query()->where('base_id', $table->source_base_id)->where('table_id', $table->source_table_id)
            ->whereIn('record_id', $records->pluck('source_record_id'))->get()->keyBy('record_id');
        $live = self::live($table, $records->pluck('source_record_id')->all());

        $conflicts = [];
        $payload = [];
        foreach ($changes as $recordId => $cells) {
            $record = $records[$recordId];
            $out = [];
            foreach ($cells as $fieldId => $value) {
                $field = $fields[$fieldId];
                self::refuseComputed($field, $computed);
                $name = (string) $field->source_name;
                $ours = $copies[$record->source_record_id]->fields[$name] ?? null;
                $theirs = $live[$record->source_record_id][$name] ?? null;
                if ($ours != $theirs) {
                    $conflicts[] = ['record' => $recordId, 'field' => $field->name, 'theirs' => $theirs, 'yours' => $value];

                    continue;
                }
                $out[$name] = self::toAirtable($field, $value);
            }
            if ($out !== []) {
                $payload[] = ['id' => $record->source_record_id, 'fields' => $out];
            }
        }

        foreach (array_chunk($payload, 10) as $chunk) {
            $response = self::send($table, 'patch', ['records' => $chunk, 'typecast' => true]);
            self::keep($table, (array) $response['records']);
        }
        // Conflicting cells: our copy takes Airtable's live record.
        if ($conflicts !== []) {
            $written = array_column($payload, 'id');
            $losers = $records->whereIn('id', array_column($conflicts, 'record'))->pluck('source_record_id')
                ->reject(fn ($id) => in_array($id, $written, true));
            self::keep($table, $losers->map(fn ($id) => ['id' => $id, 'fields' => $live[$id] ?? []])->values()->all());
        }

        return $conflicts;
    }

    /** @param array<string, mixed> $cells field id => clean value */
    public static function create(FinancialTable $table, array $cells): FinancialRecord
    {
        $fields = $table->fields()->get()->keyBy('id');
        $computed = self::computedFields($table);
        $out = [];
        foreach ($cells as $fieldId => $value) {
            self::refuseComputed($fields[$fieldId], $computed);
            if ($fields[$fieldId]->source_name !== null && $value !== null) {
                $out[$fields[$fieldId]->source_name] = self::toAirtable($fields[$fieldId], $value);
            }
        }
        $response = self::send($table, 'post', ['records' => [['fields' => (object) $out]], 'typecast' => true]);
        $made = (array) ($response['records'][0] ?? []);
        self::keep($table, [$made]);

        return FinancialRecord::query()->where('table_id', $table->id)->where('source_record_id', $made['id'])->firstOrFail();
    }

    public static function delete(FinancialTable $table, FinancialRecord $record): void
    {
        self::send($table, 'delete', [], '?records[]='.$record->source_record_id);
        AirtableRecord::query()->where('base_id', $table->source_base_id)->where('table_id', $table->source_table_id)
            ->where('record_id', $record->source_record_id)->update(['gone_at' => now()]);
        $record->delete();
    }

    private static function refuseComputed(FinancialField $field, array $computed): void
    {
        if ($field->source_name === null || in_array($field->source_name, $computed, true) || $field->type === 'attachment') {
            throw ValidationException::withMessages(['values' => "{$field->name} is worked out by Airtable and cannot be typed into."]);
        }
    }

    /** What Airtable expects for a clean EzBizi value. */
    private static function toAirtable(FinancialField $field, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $field->type === 'checkbox' => (bool) $value,
            default => $value,
        };
    }

    /** The records as Airtable holds them now. @return array<string, array<string, mixed>> */
    private static function live(FinancialTable $table, array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_filter($ids)), 50) as $chunk) {
            $formula = 'OR('.implode(',', array_map(fn ($id) => "RECORD_ID()='{$id}'", $chunk)).')';
            $response = self::http($table)->get(self::API."/{$table->source_base_id}/{$table->source_table_id}", ['filterByFormula' => $formula, 'pageSize' => 100]);
            self::refuse($response);
            foreach ((array) $response->json('records') as $r) {
                $out[$r['id']] = (array) ($r['fields'] ?? []);
            }
        }

        return $out;
    }

    /** Puts Airtable's answer into the copy and the mirror. */
    private static function keep(FinancialTable $table, array $records): void
    {
        foreach ($records as $r) {
            if (! isset($r['id'])) {
                continue;
            }
            $fields = (array) ($r['fields'] ?? []);
            ksort($fields);
            AirtableRecord::query()->updateOrCreate(
                ['organization_id' => $table->organization_id, 'base_id' => $table->source_base_id, 'table_id' => $table->source_table_id, 'record_id' => $r['id']],
                ['table_name' => $table->name, 'fields' => $fields, 'fields_hash' => hash('sha256', (string) json_encode($fields)),
                    'record_created_at' => isset($r['createdTime']) ? now()->parse($r['createdTime']) : null, 'fetched_at' => now(), 'gone_at' => null],
            );
        }
        AirtableMirror::refresh($table);
    }

    private static function send(FinancialTable $table, string $method, array $body, string $query = ''): array
    {
        $response = self::http($table)->{$method}(self::API."/{$table->source_base_id}/{$table->source_table_id}{$query}", $body);
        self::refuse($response);

        return (array) $response->json();
    }

    private static function refuse($response): void
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw ValidationException::withMessages(['values' => 'Airtable refused the change: the connected token cannot write to this base. Give it data.records:write in Airtable.']);
        }
        if ($response->failed()) {
            $why = $response->json('error.message') ?? $response->json('error.type') ?? 'error '.$response->status();
            throw ValidationException::withMessages(['values' => "Airtable refused the change ({$why}). Nothing was changed."]);
        }
    }

    private static function http(FinancialTable $table)
    {
        $token = (string) (SocialConnection::query()->where('provider', 'airtable')->where('remote_id', $table->source_base_id)->first()?->credentials['api_token'] ?? '');
        if ($token === '') {
            throw ValidationException::withMessages(['values' => 'This base is not connected to Airtable any more, so nothing can be written to it.']);
        }

        return Http::withToken($token)->acceptJson()->timeout(30)->retry(2, 1000, fn ($e) => $e instanceof ConnectionException || ($e->response?->status() === 429), throw: false);
    }
}
