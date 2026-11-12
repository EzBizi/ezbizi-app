<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Tasks\Monday\MondayImport;
use Illuminate\Console\Command;

/**
 * Brings A-1's monday.com boards onto the Task Board from a JSON export (Jose,
 * 2026-11-03: "Replace monday until Nov 10th"; 2026-11-12: Jose, Rodrigo, Ana,
 * Brian, Jonathan with everything). Reads the file, never Monday itself; the
 * work is App\Support\Tasks\Monday\MondayImport, which the sync shares.
 * --dry-run rolls everything back and fetches no file.
 */
class TasksImportMonday extends Command
{
    protected $signature = 'ezbizi:tasks-import-monday {file} {--org=} {--as=} {--files= : folder holding <monday asset id><ext>, tried before the export\'s url} {--dry-run}';

    protected $description = 'Import monday.com boards from a JSON export onto the Task Board';

    public function handle(MondayImport $import): int
    {
        $data = json_decode((string) file_get_contents($this->argument('file')), true, flags: JSON_THROW_ON_ERROR);
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($this->option('org'));
        $dry = (bool) $this->option('dry-run');

        $report = $import->run($org, $data, (string) $this->option('as'), $dry, $this->option('files') ?: null);

        $this->line($dry ? '[dry run, nothing saved, no file fetched]' : '[saved]');
        self::print($this, $report);

        return self::SUCCESS;
    }

    /** @param  array{counts: array, unmatched: list<string>, owners_dropped: array<string, int>, not_carried: list<string>}  $report */
    public static function print(Command $out, array $report): void
    {
        foreach ($report['counts'] as $k => $v) {
            $out->line("$k: $v");
        }
        $out->line('people not matched: '.count($report['unmatched']).($report['unmatched'] ? ' ('.implode(', ', $report['unmatched']).')' : ''));
        $dropped = $report['owners_dropped'];
        $out->line('owners dropped (cannot own a task on that board): '.array_sum($dropped)
            .($dropped ? ' ('.implode(', ', array_map(fn ($n, $c) => "$n x$c", array_keys($dropped), $dropped)).')' : ''));
        foreach ($report['not_carried'] as $line) {
            $out->line('  file not carried: '.$line);
        }
    }
}
