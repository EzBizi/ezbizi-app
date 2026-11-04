<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Monday's open items onto the Task Board (Jose, 2026-11-03: replace Monday by Nov 10). */
class TasksImportMondayTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private User $jose;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant('Alpha');
        $this->jose = $this->userWithRole($this->tenant, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $item = fn (string $id, string $name, array $extra = []) => $extra + [
            'monday_id' => $id, 'name' => $name, 'group' => 'Open Tasks', 'created_at' => '2026-10-01T12:00:00Z',
            'creator_email' => null, 'status' => null, 'priority' => null, 'people' => [], 'due_on' => null,
            'timeline' => null, 'columns' => [], 'subitems' => [], 'updates' => [],
        ];
        $this->file = tempnam(sys_get_temp_dir(), 'monday');
        file_put_contents($this->file, json_encode(['exported_at' => '2026-11-03T12:00:00Z', 'users' => [], 'boards' => [
            ['monday_id' => '1', 'name' => 'Jose Demasi', 'owner_email' => strtoupper($this->jose->email), 'groups' => [], 'items' => [
                $item('11', 'Call the insurer', [
                    'status' => 'Waiting on client', 'priority' => 'High', 'people' => [$this->jose->email],
                    'due_on' => '2026-11-07', 'columns' => ['Text' => 'policy 42'],
                    'subitems' => [$item('111', 'Find the policy')],
                    'updates' => [['monday_id' => 'u1', 'author_email' => 'gone@a-1chimney.com', 'created_at' => '2026-10-02T09:00:00Z',
                        'body' => 'Left a voicemail', 'replies' => [['author_email' => $this->jose->email, 'created_at' => '2026-10-03T09:00:00Z', 'body' => 'Try again Monday']]]],
                ]),
            ]],
            ['monday_id' => '2', 'name' => 'Ohio Project Management', 'owner_email' => null, 'groups' => [['monday_id' => 'g', 'name' => 'Scheduled', 'colour' => '#00C875']], 'items' => [
                $item('21', 'Liner job', ['group' => 'Scheduled', 'timeline' => ['from' => '2026-11-04', 'to' => '2026-11-06']]),
            ]],
        ]]));
    }

    private function import(array $extra = []): string
    {
        $this->artisan('ezbizi:tasks-import-monday', ['file' => $this->file, '--org' => $this->tenant['org']->id, '--as' => $this->jose->email] + $extra)
            ->assertSuccessful();

        return '';
    }

    public function test_it_brings_items_subitems_and_updates_once_and_runs_no_automation(): void
    {
        $this->import();
        $this->import();

        $this->inTenant($this->tenant['org'], function () {
            $task = Task::query()->where('external_ref', 'monday:11')->firstOrFail();
            $this->assertSame($this->jose->id, $task->board->owner_user_id, 'a personal board lands on that person\'s own board');
            $this->assertSame('Waiting on client', $task->statusRow->name);
            $this->assertSame('High', $task->priority);
            $this->assertSame('2026-11-07', $task->due_on->toDateString());
            $this->assertSame('2026-10-01', $task->created_at->toDateString());
            $this->assertSame([$this->jose->id], $task->owners->pluck('user_id')->all());
            $this->assertSame('policy 42', $task->values->first(fn ($v) => $v->column->name === 'Text')?->value_text);
            $this->assertSame($task->id, Task::query()->where('external_ref', 'monday:111')->value('parent_task_id'));

            $updates = TaskUpdate::query()->where('task_id', $task->id)->orderBy('created_at')->get();
            $this->assertSame(['gone@a-1chimney.com (on Monday): Left a voicemail', '↳ Try again Monday'], $updates->pluck('body')->all());
            $this->assertSame([null, $this->jose->id], $updates->pluck('user_id')->all());

            $ohio = Task::query()->where('external_ref', 'monday:21')->firstOrFail();
            $this->assertSame('Ohio Project Management', $ohio->board->name);
            $this->assertSame('Scheduled', $ohio->group->name);
            $this->assertSame('2026-11-06', $ohio->due_on->toDateString());

            $this->assertSame(3, Task::query()->whereNotNull('external_ref')->count(), 'a second run adds nothing');
            $this->assertSame(1, TaskBoard::query()->where('external_ref', 'monday:2')->count());
            $this->assertSame(0, AutomationEvent::query()->withoutGlobalScopes()->count(), 'an import is history, not an event');
        });
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->import(['--dry-run' => true]);
        $this->inTenant($this->tenant['org'], fn () => $this->assertSame(0, Task::query()->whereNotNull('external_ref')->count()));
    }
}
