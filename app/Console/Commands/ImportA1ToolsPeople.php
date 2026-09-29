<?php

namespace App\Console\Commands;

use App\Support\Hr\A1ToolsPeopleImport;
use Illuminate\Console\Command;

/**
 * A1 Tools' current staff into EzBizi — docs/A1-TOOLS-PEOPLE-MIGRATION.md.
 *
 * Thin on purpose: what is read, checked and written is A1ToolsPeopleImport,
 * and the bundle it reads is specified in the spec's HR section. Run with
 * `--dry-run` first; it checks everything and writes nothing.
 */
class ImportA1ToolsPeople extends Command
{
    protected $signature = 'hr:import-a1-tools
        {bundle : Directory holding people.json and files/}
        {--dry-run : Check everything and report what would be written, writing nothing}';

    protected $description = 'Import A1 Tools staff (people, onboarding records, documents, HR fields) into EzBizi';

    public function handle(A1ToolsPeopleImport $import): int
    {
        $plan = $import->plan((string) $this->argument('bundle'));

        if ($plan['errors'] !== []) {
            $this->error('Refused. Nothing was written. '.count($plan['errors']).' problem(s):');

            foreach ($plan['errors'] as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        $counts = array_count_values(array_column($plan['people'], 'status'));

        $this->info(sprintf(
            '%d people: %d new, %d matched, %d already imported.',
            count($plan['people']),
            $counts['new'] ?? 0,
            $counts['matched'] ?? 0,
            $counts['already_imported'] ?? 0,
        ));

        if ($this->option('dry-run')) {
            foreach ($plan['people'] as $person) {
                $this->line("{$person['label']}: would be {$person['status']} — ".$import->summary($person));
            }

            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $totals = $import->run($plan, fn (string $line) => $this->line($line));

        $this->info(sprintf(
            'Done: %d created, %d updated, %d already imported, %d failed.',
            $totals['created'], $totals['updated'], $totals['skipped'], $totals['failed'],
        ));

        return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
