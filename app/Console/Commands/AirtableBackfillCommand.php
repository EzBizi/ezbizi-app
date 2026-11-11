<?php

namespace App\Console\Commands;

use App\Models\AirtableRecord;
use App\Models\FinancialTable;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Financials\AirtableBaseName;
use App\Support\Financials\AirtableMirror;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time import of one Airtable base into EzBizi Financials (plan
 * 2026-11-03 section 3 step 2, phase A): every table of the base in the
 * Airtable copy gets its Financials table, rows keyed by the Airtable record
 * id, so a re-run changes nothing. Tables already cut over are left alone:
 * EzBizi holds them, and the copy must not overwrite what was typed since.
 * Dump financial_records + airtable_records before a real run.
 */
class AirtableBackfillCommand extends Command
{
    protected $signature = 'ezbizi:airtable-backfill {organization : slug} {base : Airtable base id or name} {--dry-run : count what would change, write nothing}';

    protected $description = 'Import one Airtable base (from the hourly copy) into EzBizi Financials; idempotent';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $org = Organization::query()->where('slug', $this->argument('organization'))->firstOrFail();
        $dry = (bool) $this->option('dry-run');

        return $tenant->run($org, function () use ($audit, $dry) {
            $baseId = AirtableBaseName::resolve((string) $this->argument('base'));
            $tables = AirtableRecord::query()->where('base_id', $baseId)->distinct()->pluck('table_id');
            if ($tables->isEmpty()) {
                $this->error("No rows of base {$baseId} in the Airtable copy.");

                return self::FAILURE;
            }

            $totals = ['tables_made' => 0, 'added' => 0, 'changed' => 0, 'removed' => 0, 'skipped_cut_over' => 0];
            $lines = [];
            try {
                DB::transaction(function () use ($baseId, $tables, &$totals, &$lines, $dry) {
                    foreach ($tables as $tableId) {
                        $table = FinancialTable::query()->where('source_base_id', $baseId)->where('source_table_id', $tableId)->orderBy('created_at')->first();
                        if ($table?->isCutOver()) {
                            $totals['skipped_cut_over']++;
                            $lines[] = "{$table->name}: cut over, left alone";

                            continue;
                        }
                        if ($table === null) {
                            $table = AirtableMirror::link(AirtableMirror::baseFor($baseId), $baseId, $tableId);
                            $totals['tables_made']++;
                            $done = ['added' => $table->records()->count(), 'changed' => 0, 'removed' => 0];
                        } else {
                            $done = AirtableMirror::refresh($table);
                        }
                        foreach ($done as $k => $n) {
                            $totals[$k] += $n;
                        }
                        $lines[] = "{$table->name}: +{$done['added']} ~{$done['changed']} -{$done['removed']}";
                    }
                    if ($dry) {
                        throw new RuntimeException('dry-run');
                    }
                });
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'dry-run') {
                    throw $e;
                }
            }

            foreach ($lines as $line) {
                $this->line(($dry ? '[dry run] ' : '').$line);
            }
            $this->info(($dry ? '[dry run] ' : '').json_encode($totals));
            if (! $dry) {
                $audit->log('financials.backfill', null, metadata: ['base' => $baseId] + $totals);
            }

            return self::SUCCESS;
        });
    }
}
