<?php

namespace App\Support\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialBase;
use App\Models\FinancialField;
use App\Models\FinancialRecord;
use App\Models\FinancialTable;
use App\Models\SocialConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors one table of the Airtable copy (airtable_records, refreshed hourly
 * by Reports) into a Financials table. Columns are matched by their Airtable
 * name, so renaming a column here survives the next refresh; a column that
 * appears in Airtable later is added. Rows Airtable no longer has are removed.
 * Must run inside the tenant's context.
 */
final class AirtableMirror
{
    /** @return list<array{base_id:string, table_id:string, table_name:string, rows:int}> */
    public static function available(): array
    {
        return AirtableRecord::query()
            ->whereNull('gone_at')
            ->selectRaw('base_id, table_id, MAX(table_name) as table_name, COUNT(*) as `rows`')
            ->groupBy('base_id', 'table_id')
            ->orderBy('table_name')
            ->get()
            ->map(fn ($r) => ['base_id' => $r->base_id, 'table_id' => $r->table_id, 'table_name' => $r->table_name, 'rows' => (int) $r->rows])
            ->all();
    }

    /**
     * Every table in the Airtable copy gets its Financials mirror, in the
     * Financials base named after its Airtable base (made at the organization
     * level the first time). Jose, 2026-10-21: "it should be automatically
     * mirroring them all". Returns the tables it linked.
     *
     * @return list<FinancialTable>
     */
    public static function linkAll(?string $createdBy = null): array
    {
        $mirrored = FinancialTable::query()->whereNotNull('source_table_id')->get()
            ->map(fn ($t) => $t->source_base_id.'/'.$t->source_table_id)->all();
        $linked = [];
        foreach (self::available() as $t) {
            if (in_array($t['base_id'].'/'.$t['table_id'], $mirrored, true)) {
                continue;
            }
            $linked[] = self::link(self::baseFor($t['base_id'], $createdBy), $t['base_id'], $t['table_id']);
        }

        return $linked;
    }

    /** The Financials base named after this Airtable base, made if missing. */
    public static function baseFor(string $airtableBaseId, ?string $createdBy = null): FinancialBase
    {
        $name = SocialConnection::query()->where('provider', 'airtable')
            ->where('remote_id', $airtableBaseId)->value('display_name') ?? 'Airtable';

        return FinancialBase::query()->whereNull('company_id')->where('name', $name)->first()
            ?? FinancialBase::create(['name' => $name, 'created_by_user_id' => $createdBy]);
    }

    public static function link(FinancialBase $base, string $baseId, string $tableId): FinancialTable
    {
        $name = AirtableRecord::query()->where('base_id', $baseId)->where('table_id', $tableId)->value('table_name')
            ?? throw ValidationException::withMessages(['table_id' => 'That Airtable table is not in the copy yet. The copy refreshes every hour.']);

        if (FinancialTable::query()->where('base_id', $base->id)->where('source_table_id', $tableId)->where('source_base_id', $baseId)->exists()) {
            throw ValidationException::withMessages(['table_id' => 'This base already mirrors that Airtable table.']);
        }

        return DB::transaction(function () use ($base, $baseId, $tableId, $name) {
            $table = FinancialTable::create([
                'base_id' => $base->id,
                'name' => $name,
                'position' => (int) $base->tables()->max('position') + 1,
                'source_base_id' => $baseId,
                'source_table_id' => $tableId,
            ]);
            $table->views()->create(['name' => 'Grid view', 'type' => 'grid', 'position' => 0]);
            self::refresh($table);

            return $table;
        });
    }

    /** @return array{added:int, changed:int, removed:int} */
    public static function refresh(FinancialTable $table): array
    {
        $rows = AirtableRecord::query()
            ->where('base_id', $table->source_base_id)
            ->where('table_id', $table->source_table_id)
            ->whereNull('gone_at')
            ->get(['record_id', 'fields', 'record_created_at']);

        return DB::transaction(function () use ($table, $rows) {
            $fields = self::columns($table, $rows);
            $existing = $table->records()->get()->keyBy('source_record_id');
            $done = ['added' => 0, 'changed' => 0, 'removed' => 0];
            $position = (int) $existing->max('position');

            foreach ($rows->sortBy('record_created_at') as $row) {
                $values = [];
                foreach ($fields as $source => $field) {
                    $values[$field->id] = self::cell($field, $row->fields[$source] ?? null);
                }
                $values = array_filter($values, fn ($v) => $v !== null);

                $record = $existing->get($row->record_id);
                if ($record === null) {
                    $table->records()->create(['source_record_id' => $row->record_id, 'values' => $values, 'position' => ++$position]);
                    $done['added']++;
                } elseif ($record->values != $values) {
                    $record->update(['values' => $values]);
                    $done['changed']++;
                }
            }

            $done['removed'] = FinancialRecord::query()
                ->where('table_id', $table->id)
                ->whereNotNull('source_record_id')
                ->whereNotIn('source_record_id', $rows->pluck('record_id'))
                ->delete();

            $table->forceFill(['synced_at' => now()])->save();

            return $done;
        });
    }

    /** @return array<string, FinancialField> Airtable column name => field */
    private static function columns(FinancialTable $table, Collection $rows): array
    {
        $byName = $table->fields()->get()->keyBy('source_name');
        $samples = [];
        foreach ($rows as $row) {
            foreach ((array) $row->fields as $name => $value) {
                $samples[$name][] = $value;
            }
        }
        ksort($samples);

        $primary = $table->fields()->where('is_primary', true)->first();
        $position = (int) $table->fields()->max('position');
        $out = [];

        foreach ($samples as $name => $values) {
            $name = (string) $name;
            if (! isset($byName[$name])) {
                [$type, $options] = self::infer($values);
                // The first text column names each record, as Airtable's primary does.
                $byName[$name] = $primary === null && $type === 'text'
                    ? $primary = $table->fields()->create(['name' => $name, 'source_name' => $name, 'type' => 'text', 'is_primary' => true, 'position' => 0])
                    : $table->fields()->create(['name' => self::freeName($table, $name), 'source_name' => $name, 'type' => $type, 'options' => $options, 'position' => ++$position]);
            }
            $out[$name] = $byName[$name];
        }

        if ($primary === null) {
            $table->fields()->create(['name' => 'Record', 'type' => 'text', 'is_primary' => true, 'position' => 0]);
        }

        return $out;
    }

    private static function freeName(FinancialTable $table, string $name): string
    {
        $candidate = $name;
        for ($i = 2; $table->fields()->where('name', $candidate)->exists(); $i++) {
            $candidate = "{$name} ({$i})";
        }

        return $candidate;
    }

    /** @return array{0:string, 1:?array} */
    private static function infer(array $values): array
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && $v !== '' && $v !== []));
        $all = fn (callable $test) => $values !== [] && collect($values)->every($test);

        if ($all(fn ($v) => is_array($v) && collect($v)->every(fn ($a) => is_array($a) && isset($a['url'])))) {
            return ['attachment', null];
        }
        if ($all(fn ($v) => is_bool($v))) {
            return ['checkbox', null];
        }
        if ($all(fn ($v) => is_int($v) || is_float($v))) {
            return ['number', null];
        }
        if ($all(fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}(T[\d:.]+Z?)?$/', $v))) {
            return ['date', null];
        }
        if ($all(fn ($v) => is_array($v) && collect($v)->every(fn ($x) => is_string($x) && ! str_starts_with($x, 'rec')))) {
            $choices = collect($values)->flatten()->unique()->sort()->values()->take(200);

            return ['multi_select', ['choices' => $choices->map(fn ($c) => ['name' => $c])->all()]];
        }
        if ($all(fn ($v) => is_string($v)) && count($values) >= 5) {
            $distinct = collect($values)->unique();
            if ($distinct->count() <= 25 && $distinct->count() * 2 <= count($values) && $distinct->every(fn ($v) => mb_strlen($v) <= 60)) {
                return ['single_select', ['choices' => $distinct->sort()->values()->map(fn ($c) => ['name' => $c])->all()]];
            }
        }

        return ['text', null];
    }

    /** A value that does not fit its column keeps its text rather than vanishing. */
    private static function cell(FinancialField $field, mixed $value): mixed
    {
        // A formula error comes as {"error": "#ERROR!"}; it shows as that text, never breaks the row.
        if (is_array($value) && isset($value['error']) && is_string($value['error'])) {
            return $value['error'];
        }
        if (is_array($value) && ! in_array($field->type, ['multi_select', 'attachment'], true)) {
            $value = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        }

        if ($field->type === 'single_select' || $field->type === 'multi_select') {
            $known = FieldType::choices($field->options ?? []);
            $new = array_diff((array) $value, $known, ['']);
            if ($new !== []) {
                $field->options = ['choices' => array_map(fn ($c) => ['name' => $c], [...$known, ...array_values($new)])];
                $field->save();
            }
        }

        try {
            return FieldType::coerce($field->type, $value, $field->options ?? [], $field->name);
        } catch (ValidationException) {
            return is_scalar($value) ? (string) $value : null;
        }
    }
}
