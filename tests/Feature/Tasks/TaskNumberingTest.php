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

    /** The workers must observe committed setup rows, not this test's transaction. */
    protected array $connectionsToTransact = [];

    public function test_task_numbers_use_the_board_place_prefix_and_an_organization_wide_committed_two_process_sequence(): void
    {
        $tenant = $this->provisionTenant('Alpha');

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

        $startAt = microtime(true) + 1.0;
        $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$org = App\Models\Organization::query()->findOrFail($argv[1]);
while (microtime(true) < (float) $argv[3]) { usleep(1_000); }
echo app(App\Support\Tenancy\TenantContext::class)->run($org, fn () => app(App\Support\Numbering\RecordNumberGenerator::class)->next(App\Support\Numbering\RecordNumberGenerator::TASKS, App\Models\Company::query()->findOrFail($argv[2])));
PHP;
        $command = [PHP_BINARY, '-r', $worker, $tenant['org']->id, $tenant['company']->id, (string) $startAt];
        $first = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $firstPipes, base_path());
        $second = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $secondPipes, base_path());

        $this->assertIsResource($first);
        $this->assertIsResource($second);
        fclose($firstPipes[0]);
        fclose($secondPipes[0]);
        $firstOutput = trim(stream_get_contents($firstPipes[1]));
        $secondOutput = trim(stream_get_contents($secondPipes[1]));
        $firstError = stream_get_contents($firstPipes[2]);
        $secondError = stream_get_contents($secondPipes[2]);
        fclose($firstPipes[1]); fclose($firstPipes[2]); fclose($secondPipes[1]); fclose($secondPipes[2]);
        $this->assertSame(0, proc_close($first), $firstError);
        $this->assertSame(0, proc_close($second), $secondError);

        $this->assertSame(['ALPHA-00002', 'ALPHA-00003'], collect([$firstOutput, $secondOutput])->sort()->values()->all(), $firstError.$secondError);
        $this->assertSame(4, DB::table('record_sequences')
            ->where('organization_id', $tenant['org']->id)
            ->where('sequence_key', RecordNumberGenerator::TASKS)
            ->value('next_value'));
    }
}
