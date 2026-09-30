<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use App\Support\Training\A1ToolsTrainingImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Bring A1 Tools' training into EzBizi — guides, their images, tests, and the
 * history of the people who are in EzBizi. See A1ToolsTrainingImport, which
 * this only feeds and prints.
 */
class TrainingImportA1Tools extends Command
{
    protected $signature = 'training:import-a1-tools
                            {dir? : The directory holding manifest.json and media/}
                            {--dry-run : Print exactly what would happen, and write nothing}
                            {--undo : Remove everything an earlier import created, so it can be run again}';

    protected $description = "Import A1 Tools' guides, tests and history into A1-Group's Training";

    public function handle(TenantContext $tenant, A1ToolsTrainingImport $import, FileStore $files): int
    {
        if ($this->option('undo')) {
            return $this->undo($tenant, $import);
        }

        $dir = rtrim((string) $this->argument('dir'), '/\\');
        $manifestPath = $dir.DIRECTORY_SEPARATOR.'manifest.json';

        if ($dir === '') {
            $this->error('Give the directory holding manifest.json and media/.');

            return self::FAILURE;
        }

        if (! is_file($manifestPath)) {
            $this->error("No manifest.json in {$dir}.");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            $this->error('manifest.json is not valid JSON.');

            return self::FAILURE;
        }

        $orgs = Organization::query()->where('name', A1ToolsTrainingImport::ORGANIZATION)->get();

        if ($orgs->count() !== 1) {
            $this->error('Expected exactly one organization named '.A1ToolsTrainingImport::ORGANIZATION.', found '.$orgs->count().'.');

            return self::FAILURE;
        }

        $org = $orgs->first();
        $dry = (bool) $this->option('dry-run');

        return $tenant->run($org, function () use ($import, $manifest, $dir, $org, $dry, $files): int {
            try {
                $plan = $import->plan($manifest, $dir, $org);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->report($plan, $dry);

            if ($dry) {
                $this->newLine();
                $this->line('[dry run] Nothing was written.');

                return self::SUCCESS;
            }

            $created = $import->apply($plan, $dir, $org, $this->actor());

            // The structure A1 had — chapters, sections, which image went on which
            // page — kept beside the images, with nobody's email or answers in it.
            Storage::disk($files->defaultDisk())->put(
                $files->directoryFor('training-media', $org->id).'/a1-structure-'.now()->format('Ymd-His').'.json',
                $import->structureOnly($manifest),
            );

            $this->newLine();
            $this->info(sprintf(
                'Created: %d guides (%d images), %d tests, %d attempts, %d progress.',
                $created['guides'], $created['images'], $created['tests'], $created['attempts'], $created['progress'],
            ));

            return self::SUCCESS;
        });
    }

    private function undo(TenantContext $tenant, A1ToolsTrainingImport $import): int
    {
        $orgs = Organization::query()->where('name', A1ToolsTrainingImport::ORGANIZATION)->get();

        if ($orgs->count() !== 1) {
            $this->error('Expected exactly one organization named '.A1ToolsTrainingImport::ORGANIZATION.', found '.$orgs->count().'.');

            return self::FAILURE;
        }

        return $tenant->run($orgs->first(), function () use ($import, $orgs): int {
            $removed = $import->undo($orgs->first(), $this->actor());

            $this->info(sprintf('Removed: %d guides, %d tests, %d import keys.', $removed['guides'], $removed['tests'], $removed['keys']));

            return self::SUCCESS;
        });
    }

    private function actor(): ?User
    {
        return User::query()
            ->whereHas('role', fn ($q) => $q->where('is_administrator', true))
            ->orderBy('created_at')
            ->first();
    }

    /** @param  array<string, mixed>  $plan */
    private function report(array $plan, bool $dry): void
    {
        $prefix = $dry ? '[dry run] ' : '';

        foreach ($plan['guides'] as $guide) {
            $images = count($guide['images']);
            $videos = collect($guide['screens'])->sum(fn ($s) => count(array_filter($s['blocks'], fn ($b) => $b['type'] === 'video')));

            $this->line(sprintf(
                '%sguide "%s": %d topics, %d images, %d videos.',
                $prefix, $guide['title'], count($guide['screens']), $images, $videos,
            ));

            if ($guide['target_roles'] !== []) {
                // Reported, never turned into assignments: A1 role names have no
                // crosswalk to EzBizi roles, and a guess assigns the wrong people.
                $this->line('    A1 targeted it at: '.implode(', ', $guide['target_roles']).' — assign in EzBizi if wanted.');
            }
        }

        foreach ($plan['tests'] as $test) {
            $this->line(sprintf(
                '%stest "%s": %d questions, pass mark %d%%, %s attempts.',
                $prefix, $test['title'], count($test['questions']), $test['pass_mark'], $test['max_attempts'] ?? 'unlimited',
            ));
        }

        $this->line(sprintf(
            '%shistory: %d attempts and %d progress rows for %d people in EzBizi; left out %d attempts and %d progress rows for %d people who are not.',
            $prefix,
            count($plan['attempts']), count($plan['progress']), $plan['people']['matched'],
            $plan['left_out']['attempts'], $plan['left_out']['progress'], $plan['people']['unmatched'],
        ));

        $skipped = array_sum($plan['skipped']);

        if ($skipped > 0) {
            $this->line(sprintf(
                '%salready imported, skipped: %d guides, %d tests, %d attempts, %d progress.',
                $prefix, ...array_values($plan['skipped']),
            ));
        }

        foreach (array_unique($plan['problems']) as $problem) {
            $this->warn('  '.$problem);
        }
    }
}
