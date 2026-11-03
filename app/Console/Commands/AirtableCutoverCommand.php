<?php

namespace App\Console\Commands;

use App\Models\AirtableRecord;
use App\Models\FinancialRecord;
use App\Models\FinancialTable;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Financials\AirtableBaseName;
use App\Support\Financials\AirtableMirror;
use App\Support\Financials\AirtableParity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Switch a whole Airtable base to EzBizi at once (every table's own
 * cut-over, audited as financials.cut_over), or back with --undo. Refuses
 * until the base has config('airtable.parity_days') green parity days in a
 * row, unless --force. Undo first saves the rows EzBizi changed since the
 * switch (they would be overwritten by the Airtable copy) to
 * storage/app/airtable-rollback/ for re-entry in Airtable.
 */
class AirtableCutoverCommand extends Command
{
    protected $signature = 'ezbizi:airtable-cutover {organization : slug} {base : Airtable base id or name} {--undo} {--force : switch without the green streak}';

    protected $description = 'Cut one Airtable base over to EzBizi Financials (or back), all its tables at once';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $org = Organization::query()->where('slug', $this->argument('organization'))->firstOrFail();

        return $tenant->run($org, function () use ($audit) {
            $baseId = AirtableBaseName::resolve((string) $this->argument('base'));
            $tables = FinancialTable::query()->where('source_base_id', $baseId)->get();
            if ($tables->isEmpty()) {
                $this->error("No EzBizi table holds base {$baseId}: run ezbizi:airtable-backfill first.");

                return self::FAILURE;
            }

            return $this->option('undo') ? $this->undo($audit, $baseId, $tables) : $this->switch($audit, $baseId, $tables);
        });
    }

    private function switch(AuditLogger $audit, string $baseId, $tables): int
    {
        $unheld = AirtableRecord::query()->where('base_id', $baseId)->distinct()->pluck('table_id')
            ->diff($tables->pluck('source_table_id'));
        if ($unheld->isNotEmpty()) {
            $this->error('Tables of the base not yet in EzBizi: '.$unheld->implode(', '));

            return self::FAILURE;
        }
        $streak = AirtableParity::streak($baseId);
        $need = (int) config('airtable.parity_days', 14);
        if ($streak < $need && ! $this->option('force')) {
            $this->error("Parity green {$streak} day(s) in a row; {$need} needed.");

            return self::FAILURE;
        }

        foreach ($tables->whereNull('cut_over_at') as $table) {
            $table->forceFill(['cut_over_at' => now(), 'cut_over_by_user_id' => null])->save();
            $audit->log('financials.cut_over', $table, metadata: [
                'airtable' => $baseId.'/'.$table->source_table_id, 'whole_base' => true, 'streak' => $streak, 'forced' => $streak < $need,
            ]);
            $this->line("{$table->name}: cut over");
        }

        return self::SUCCESS;
    }

    private function undo(AuditLogger $audit, string $baseId, $tables): int
    {
        $cut = $tables->whereNotNull('cut_over_at');
        $kept = [];
        foreach ($cut as $table) {
            $kept[$table->name] = FinancialRecord::query()->where('table_id', $table->id)
                ->where(fn ($q) => $q->whereNull('source_record_id')->orWhere('updated_at', '>=', $table->cut_over_at))
                ->get(['id', 'source_record_id', 'values', 'updated_at'])->toArray();
        }
        if (array_filter($kept) !== []) {
            $path = 'airtable-rollback/'.$baseId.'-'.now()->format('Ymd-His').'.json';
            Storage::put($path, json_encode($kept, JSON_PRETTY_PRINT));
            $this->warn("Rows changed in EzBizi since the switch saved to storage/app/{$path}");
        }

        foreach ($cut as $table) {
            $table->forceFill(['cut_over_at' => null, 'cut_over_by_user_id' => null])->save();
            $audit->log('financials.cut_over_undone', $table, metadata: ['airtable' => $baseId.'/'.$table->source_table_id, 'whole_base' => true]);
            AirtableMirror::refresh($table);
            $this->line("{$table->name}: back on Airtable");
        }
        if (in_array($baseId, FinancialTable::cutOverBases(), true)) {
            $this->warn('AIRTABLE_CUTOVER_BASES still names this base: remove it and run config:cache, or it stays on EzBizi.');
        }

        return self::SUCCESS;
    }
}
