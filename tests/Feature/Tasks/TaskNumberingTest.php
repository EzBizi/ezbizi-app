<?php

namespace Tests\Feature\Tasks;

use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskNumberingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_task_numbers_use_the_board_place_prefix_and_an_organization_wide_committed_two_process_sequence(): void
    {
        $migration = require database_path('migrations/2026_08_25_171000_create_task_core_tables.php');
        $migration->down();

        try {
            $this->assertFalse($this->hasIndex('task_column_options', 'tasks_options_definition_lookup_index'));
        } finally {
            $migration->up();
        }

        $tenant = null;
        $organizationId = null;

        try {
            $tenant = $this->provisionTenant('Alpha');
            $organizationId = $tenant['org']->id;

            $this->inTenant($tenant['org'], function () use ($tenant): void {
                $boardId = (string) Str::uuid7();
                DB::table('task_boards')->insert([
                    'id' => $boardId, 'organization_id' => $tenant['org']->id,
                    'company_id' => $tenant['company']->id, 'franchise_id' => null, 'folder_id' => null,
                    'name' => 'Company task board', 'description' => null, 'visibility' => 'scope', 'position' => 0,
                    'created_by' => $tenant['admin']->id, 'updated_by' => $tenant['admin']->id,
                    'archived_at' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                app(SettingsResolver::class)->set('numbering.task_prefix', $tenant['company'], 'ALPHA-');
                $board = DB::table('task_boards')->find($boardId);
                $place = \App\Models\Company::query()->findOrFail($board->company_id);
                $number = app(RecordNumberGenerator::class)->next(RecordNumberGenerator::TASKS, $place);
                $this->assertSame('ALPHA-00001', $number);
            });

            $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$org = App\Models\Organization::query()->findOrFail($argv[1]);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'GO') { fwrite(STDERR, "missing GO\n"); exit(2); }
$started = microtime(true);
fwrite(STDOUT, "STARTED|{$started}\n");
fflush(STDOUT);
$number = app(App\Support\Tenancy\TenantContext::class)->run($org, fn () => app(App\Support\Numbering\RecordNumberGenerator::class)->next(App\Support\Numbering\RecordNumberGenerator::TASKS, App\Models\Company::query()->findOrFail($argv[2])));
fwrite(STDOUT, "RESULT|{$number}\n");
PHP;
            $results = $this->runConcurrentAllocators($worker, $tenant['org']->id, $tenant['company']->id);
            $this->assertSame(['ALPHA-00002', 'ALPHA-00003'], collect($results['numbers'])->sort()->values()->all());
            $this->assertLessThan(0.5, abs($results['started'][0] - $results['started'][1]));
            $this->assertSame(4, DB::table('record_sequences')
                ->where('organization_id', $tenant['org']->id)
                ->where('sequence_key', RecordNumberGenerator::TASKS)
                ->value('next_value'));
        } finally {
            if ($tenant !== null) {
                $this->purgeCommittedTenant($tenant['org']->id);
            }
        }

        $this->assertNotNull($organizationId);
        $this->assertSame(0, DB::table('settings')->where('organization_id', $organizationId)->count());
        $this->assertSame(0, DB::table('record_sequences')->where('organization_id', $organizationId)->count());
        $this->assertSame(0, DB::table('task_boards')->where('organization_id', $organizationId)->count());
        $this->assertSame(0, DB::table('organizations')->where('id', $organizationId)->count());
    }

    /** @return array{numbers: array<int, string>, started: array<int, float>} */
    private function runConcurrentAllocators(string $worker, string $organizationId, string $companyId): array
    {
        $command = [PHP_BINARY, '-r', $worker, $organizationId, $companyId];
        $workers = [];

        try {
            foreach ([0, 1] as $_) {
                $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            foreach ($workers as $worker) {
                $this->assertSame("READY\n", fgets($worker['pipes'][1]));
            }

            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "GO\n");
                fflush($worker['pipes'][0]);
                fclose($worker['pipes'][0]);
            }

            $numbers = [];
            $started = [];
            foreach ($workers as $worker) {
                $startedLine = trim((string) fgets($worker['pipes'][1]));
                $resultLine = trim((string) fgets($worker['pipes'][1]));
                $this->assertMatchesRegularExpression('/^STARTED\\|[0-9.]+$/', $startedLine);
                $this->assertMatchesRegularExpression('/^RESULT\\|ALPHA-\\d{5}$/', $resultLine);
                $started[] = (float) substr($startedLine, strlen('STARTED|'));
                $numbers[] = substr($resultLine, strlen('RESULT|'));
            }

            return compact('numbers', 'started');
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['pipes'][0])) {
                    fclose($worker['pipes'][0]);
                }
                $stderr = is_resource($worker['pipes'][2]) ? stream_get_contents($worker['pipes'][2]) : '';
                if (is_resource($worker['pipes'][1])) {
                    fclose($worker['pipes'][1]);
                }
                if (is_resource($worker['pipes'][2])) {
                    fclose($worker['pipes'][2]);
                }

                if (is_resource($worker['process'])) {
                    $exit = proc_close($worker['process']);
                    $this->assertSame(0, $exit, $stderr);
                }
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function purgeCommittedTenant(string $organizationId): void
    {
        DB::transaction(function () use ($organizationId): void {
            DB::table('settings')->where('organization_id', $organizationId)->delete();
            DB::table('record_sequences')->where('organization_id', $organizationId)->delete();
            DB::table('task_boards')->where('organization_id', $organizationId)->delete();
            DB::table('assignments')->where('organization_id', $organizationId)->delete();
            DB::table('users')->where('organization_id', $organizationId)->delete();
            DB::table('roles')->where('organization_id', $organizationId)->delete();
            DB::table('franchises')->where('organization_id', $organizationId)->delete();
            DB::table('companies')->where('organization_id', $organizationId)->delete();
            DB::table('organizations')->where('id', $organizationId)->delete();
        });
    }
}
