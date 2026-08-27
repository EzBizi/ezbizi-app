<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskGroup;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskConcurrencyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->alpha['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->alpha['company']->id],
        ])->save();
        $this->actingAs($this->alpha['admin']->fresh());
        $id = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Concurrent work', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($id);
    }

    public function test_stale_write_returns_only_requested_current_fields_and_changes_nothing(): void
    {
        $task = $this->createTask('Original', $this->taskGroups()->first());
        $staleVersion = $task->version;
        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version, 'title' => 'Current',
        ])->assertOk();

        $response = $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $staleVersion, 'title' => 'Stale overwrite',
        ])->assertConflict()
            ->assertJsonPath('data.title', 'Current')
            ->assertJsonPath('meta.current_version', 2)
            ->assertJsonPath('meta.conflicting_fields.0', 'title');
        $this->assertSame(['id', 'version', 'title'], array_keys($response->json('data')));
        $this->enterTenant();
        $this->assertSame('Current', $task->fresh()->title);
    }

    public function test_access_revocation_wins_before_a_stale_write_and_returns_no_task_shape(): void
    {
        $privateId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Private work', 'visibility' => 'private',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $private = TaskBoard::findOrFail($privateId);
        $worker = $this->userWithRole($this->alpha, 'Worker', ['tasks.view', 'tasks.edit']);
        $this->enterTenant();
        $this->postJson("/api/v1/tasks/boards/{$private->id}/members", [
            'user_id' => $worker->id, 'role' => 'member',
        ])->assertCreated();
        $this->enterTenant();
        $task = $this->createTask('Secret', $private->groups()->active()->firstOrFail(), $private);

        $this->enterTenant();
        $membership = TaskBoardMember::where('board_id', $private->id)
            ->where('user_id', $worker->id)->firstOrFail();
        $membership->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        $worker->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->alpha['company']->id],
        ])->save();
        $this->signedInAs($worker);

        $response = $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version, 'title' => 'Leaked?',
        ])->assertNotFound();
        $this->assertNull($response->json('data'));
        $this->enterTenant();
        $this->assertSame('Secret', $task->fresh()->title);
    }

    public function test_private_task_is_not_enumerable_before_action_permission_refusal(): void
    {
        $privateId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Hidden', 'visibility' => 'private',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $private = TaskBoard::findOrFail($privateId);
        $task = $this->createTask('Hidden task', $private->groups()->active()->firstOrFail(), $private);
        $viewer = $this->userWithRole($this->alpha, 'View only stranger', ['tasks.view']);
        $viewer->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->alpha['company']->id],
        ])->save();
        $this->signedInAs($viewer);

        $this->patchJson("/api/v1/tasks/{$task->id}", [
            'expected_version' => $task->version, 'title' => 'No',
        ])->assertNotFound();
        $this->patchJson('/api/v1/tasks/'.Str::uuid(), [
            'expected_version' => 1, 'title' => 'No',
        ])->assertNotFound();
    }

    public function test_cross_group_move_is_dense_versions_only_the_moved_task_and_rejects_stale_order(): void
    {
        $groups = $this->taskGroups();
        $source = $groups->first();
        $destination = $groups->last();
        $moved = $this->createTask('Move me', $source);
        $sourceSibling = $this->createTask('Stay', $source);
        $destinationSibling = $this->createTask('Already there', $destination);
        $this->enterTenant();
        $source = $source->fresh();
        $destination = $destination->fresh();
        $sourceSiblingVersion = $sourceSibling->version;
        $destinationSiblingVersion = $destinationSibling->version;

        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/order", [
            'task_id' => $moved->id,
            'destination_group_id' => $destination->id,
            'after_task_id' => $destinationSibling->id,
            'expected_source_order_version' => $source->order_version,
            'expected_destination_order_version' => $destination->order_version,
            'expected_task_version' => $moved->version,
        ])->assertOk()->assertJsonPath('data.group_id', $destination->id)
            ->assertJsonPath('data.version', $moved->version + 1);

        $this->enterTenant();
        $this->assertSame([1], Task::active()->where('group_id', $source->id)->pluck('position')->all());
        $this->assertSame([1, 2], Task::active()->where('group_id', $destination->id)->orderBy('position')->pluck('position')->all());
        $this->assertSame($sourceSiblingVersion, $sourceSibling->fresh()->version);
        $this->assertSame($destinationSiblingVersion, $destinationSibling->fresh()->version);

        $moved = $moved->fresh();
        $destination = $destination->fresh();
        $auditBefore = DB::table('audit_events')->where('target_id', $moved->id)->count();
        $activityBefore = DB::table('task_activities')->where('task_id', $moved->id)->count();
        $coreVersion = $moved->version;
        $detailVersion = $moved->detail_version;
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/order", [
            'task_id' => $moved->id,
            'destination_group_id' => $destination->id,
            'before_task_id' => $destinationSibling->id,
            'expected_source_order_version' => $destination->order_version,
            'expected_destination_order_version' => $destination->order_version,
            'expected_task_version' => $moved->version,
        ])->assertOk()->assertJsonPath('data.version', $coreVersion)
            ->assertJsonPath('data.detail_version', $detailVersion + 1);
        $this->enterTenant();
        $this->assertSame($auditBefore + 1, DB::table('audit_events')->where('target_id', $moved->id)->count());
        $this->assertSame($activityBefore + 1, DB::table('task_activities')->where('task_id', $moved->id)->count());
        $this->assertSame([1, 2], Task::active()->where('group_id', $destination->id)->orderBy('position')->pluck('position')->all());

        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/order", [
            'task_id' => $moved->id,
            'destination_group_id' => $destination->id,
            'expected_source_order_version' => $destination->order_version,
            'expected_destination_order_version' => $destination->order_version,
            'expected_task_version' => $coreVersion,
        ])->assertConflict()->assertJsonStructure(['meta' => ['current_group_versions']]);
    }

    public function test_private_membership_is_rechecked_under_the_same_locks_for_both_race_outcomes(): void
    {
        $privateId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Race board', 'visibility' => 'private',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $private = TaskBoard::findOrFail($privateId);
        $worker = $this->userWithRole($this->alpha, 'Race worker', ['tasks.view', 'tasks.edit']);
        $worker->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->alpha['company']->id],
        ])->save();
        $this->enterTenant();
        $this->postJson("/api/v1/tasks/boards/{$private->id}/members", [
            'user_id' => $worker->id, 'role' => 'member',
        ])->assertCreated();
        $this->enterTenant();
        $membership = TaskBoardMember::where('board_id', $private->id)
            ->where('user_id', $worker->id)->firstOrFail();
        $task = $this->createTask('Race original', $private->groups()->active()->firstOrFail(), $private);

        // Child processes need committed fixtures. This is the last test in the
        // class and the finally block rebuilds the isolated test schema.
        DB::commit();
        $processes = [];

        try {
            $arguments = [
                $this->alpha['org']->id, $worker->id, $private->id,
                $task->id, $membership->id, (string) $task->version,
            ];

            $writerFirst = $this->startRaceWorker('writer_hold', $arguments);
            $processes[] = &$writerFirst;
            $this->assertSame('LOCKED', $this->readRaceLine($writerFirst));
            $revokerSecond = $this->startRaceWorker('revoke', $arguments);
            $processes[] = &$revokerSecond;
            $this->assertSame('READY', $this->readRaceLine($revokerSecond));
            $this->releaseRaceWorker($revokerSecond);
            $this->releaseRaceWorker($writerFirst);
            $this->assertSame('RESULT|UPDATED', $this->finishRaceWorker($writerFirst));
            $this->assertSame('RESULT|REVOKED', $this->finishRaceWorker($revokerSecond));

            $this->enterTenant();
            $this->assertSame('Writer won', $task->fresh()->title);
            $this->assertFalse($membership->fresh()->is_active);
            $membership->forceFill(['is_active' => true, 'archived_at' => null])->save();
            $task = $task->fresh();
            $arguments[5] = (string) $task->version;

            $revokerFirst = $this->startRaceWorker('revoke_hold', $arguments);
            $processes[] = &$revokerFirst;
            $this->assertSame('LOCKED', $this->readRaceLine($revokerFirst));
            $writerSecond = $this->startRaceWorker('writer', $arguments);
            $processes[] = &$writerSecond;
            $this->assertSame('READY', $this->readRaceLine($writerSecond));
            $this->releaseRaceWorker($writerSecond);
            $this->releaseRaceWorker($revokerFirst);
            $this->assertSame('RESULT|REVOKED', $this->finishRaceWorker($revokerFirst));
            $this->assertSame('RESULT|DENIED', $this->finishRaceWorker($writerSecond));

            $this->enterTenant();
            $this->assertSame('Writer won', $task->fresh()->title);
            $this->assertSame(2, $task->fresh()->version);
        } finally {
            foreach ($processes as &$process) {
                $this->closeRaceWorker($process);
            }
            app(TenantContext::class)->forget();
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    private function createTask(string $title, TaskGroup $group, ?TaskBoard $board = null): Task
    {
        $board ??= $this->board;
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function taskGroups()
    {
        return $this->board->groups()->active()->orderBy('position')->get();
    }

    private function startRaceWorker(string $operation, array $arguments): array
    {
        $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$operation = $argv[1];
$organization = App\Models\Organization::query()->findOrFail($argv[2]);
$result = app(App\Support\Tenancy\TenantContext::class)->run($organization, function () use ($operation, $argv) {
    $actor = App\Models\User::query()->findOrFail($argv[3]);
    $boardId = $argv[4];
    $taskId = $argv[5];
    $membershipId = $argv[6];
    $expectedVersion = (int) $argv[7];
    $wait = static function (string $signal): void {
        fwrite(STDOUT, $signal."\n"); fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'GO') { throw new RuntimeException('missing GO'); }
    };
    $revoke = static function () use ($boardId, $membershipId): void {
        Illuminate\Support\Facades\DB::transaction(function () use ($boardId, $membershipId): void {
            App\Models\TaskBoard::query()->lockForUpdate()->findOrFail($boardId);
            $membership = App\Models\TaskBoardMember::query()->lockForUpdate()->findOrFail($membershipId);
            $membership->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        });
    };
    if ($operation === 'revoke_hold') {
        return Illuminate\Support\Facades\DB::transaction(function () use ($boardId, $membershipId, $wait): string {
            App\Models\TaskBoard::query()->lockForUpdate()->findOrFail($boardId);
            $membership = App\Models\TaskBoardMember::query()->lockForUpdate()->findOrFail($membershipId);
            $wait('LOCKED');
            $membership->forceFill(['is_active' => false, 'archived_at' => now()])->save();
            return 'REVOKED';
        });
    }
    if ($operation === 'revoke') {
        $wait('READY'); $revoke(); return 'REVOKED';
    }
    $write = static function () use ($actor, $taskId, $expectedVersion): string {
        try {
            $task = App\Models\Task::query()->findOrFail($taskId);
            app(App\Support\Tasks\TaskWriter::class)->update(
                $actor, $task, App\Support\Scoping\ViewScope::forUser($actor),
                $expectedVersion, ['title' => 'Writer won'],
            );
            return 'UPDATED';
        } catch (Illuminate\Auth\Access\AuthorizationException) {
            return 'DENIED';
        }
    };
    if ($operation === 'writer_hold') {
        return Illuminate\Support\Facades\DB::transaction(function () use ($boardId, $actor, $wait, $write): string {
            App\Models\TaskBoard::query()->lockForUpdate()->findOrFail($boardId);
            App\Models\TaskBoardMember::query()->where('board_id', $boardId)
                ->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $wait('LOCKED');
            return $write();
        });
    }
    $wait('READY');
    return $write();
});
fwrite(STDOUT, 'RESULT|'.$result."\n");
PHP;
        $command = [PHP_BINARY, '-r', $worker, $operation, ...$arguments];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);

        return ['process' => $process, 'pipes' => $pipes, 'finished' => false];
    }

    private function readRaceLine(array $worker): string
    {
        return trim((string) fgets($worker['pipes'][1]));
    }

    private function releaseRaceWorker(array $worker): void
    {
        fwrite($worker['pipes'][0], "GO\n");
        fflush($worker['pipes'][0]);
    }

    private function finishRaceWorker(array &$worker): string
    {
        $line = $this->readRaceLine($worker);
        fclose($worker['pipes'][0]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        $worker['finished'] = true;
        $this->assertSame(0, $exit, $stderr);

        return $line;
    }

    private function closeRaceWorker(array &$worker): void
    {
        if ($worker['finished'] ?? true) {
            return;
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
        $worker['finished'] = true;
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->alpha['org']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
