<?php

namespace Tests\Feature\Tasks;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Personal monday boards with everything (Jose, 2026-11-12: "Jose, Rodrigo,
 * Ana, Brian, Jonathan"): done items land done with Monday's completion time,
 * update files become attachments, people map across emails, and the 11-03
 * run's deleted rows are reused instead of hitting the unique key.
 */
class TasksImportMondayEverythingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private User $jose;

    private User $rodrigo;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->tenant = $this->provisionTenant('Alpha');
        $this->jose = $this->userWithRole($this->tenant, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards'], 'jose@ezbizi.test');
        $this->rodrigo = $this->userWithRole($this->tenant, 'Marketing', ['tasks.view'], 'rodrigo@ezbizi.test');
        $this->inTenant($this->tenant['org'], fn () => $this->rodrigo->forceFill(['name' => 'Rodrigo Mena'])->save());
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'monday-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'a1.pdf', '%PDF-1.4 resume');
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'a2.mp4', str_repeat('v', 4096));
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'a4.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function item(string $id, string $name, array $extra = []): array
    {
        return $extra + [
            'monday_id' => $id, 'name' => $name, 'group' => 'Open Tasks', 'created_at' => '2026-10-01T12:00:00Z',
            'creator_email' => null, 'status' => null, 'done' => false, 'completed_at' => null, 'priority' => null,
            'people' => [], 'due_on' => null, 'timeline' => null, 'columns' => [], 'subitems' => [], 'updates' => [],
        ];
    }

    private function import(array $items, array $extra = []): string
    {
        $file = $this->dir.DIRECTORY_SEPARATOR.'export.json';
        file_put_contents($file, json_encode([
            'exported_at' => '2026-11-12',
            // monday emails are not EzBizi emails: the export carries the map.
            'people' => ['jose@a-1chimney.com' => 'jose@ezbizi.test'],
            'users' => [['monday_id' => '9', 'name' => 'Rodrigo Mena', 'email' => 'rodrigo@a-1chimney.com']],
            'boards' => [['monday_id' => '1', 'name' => 'Jose Demasi', 'owner_email' => 'jose@a-1chimney.com', 'groups' => [], 'items' => $items]],
        ]));
        $this->artisan('ezbizi:tasks-import-monday', ['file' => $file, '--org' => $this->tenant['org']->id, '--as' => 'jose@a-1chimney.com', '--files' => $this->dir] + $extra)
            ->assertSuccessful();

        return $file;
    }

    public function test_done_items_land_done_with_mondays_completion_time(): void
    {
        $this->import([
            $this->item('11', 'Shipped thing', ['status' => 'Done', 'done' => true, 'completed_at' => '2026-10-05T08:30:00Z', 'group' => 'Completed Tasks',
                'subitems' => [$this->item('111', 'Sub done', ['status' => 'Delivered', 'done' => true, 'completed_at' => '2026-10-04T10:00:00Z']),
                    $this->item('112', 'Sub open', ['status' => 'Working on it'])]]),
            $this->item('12', 'Blank label in a done group', ['group' => 'Completed Tasks']),
            $this->item('13', 'Still open', ['status' => 'Done - Pending Review', 'timeline' => ['from' => '2026-11-10', 'to' => '2026-11-12']]),
        ]);

        $this->inTenant($this->tenant['org'], function () {
            $done = Task::query()->where('external_ref', 'monday:11')->firstOrFail();
            $this->assertTrue($done->statusRow->is_done);
            $this->assertSame('2026-10-05 08:30:00', $done->completed_at->utc()->format('Y-m-d H:i:s'));
            $this->assertSame('Completed Tasks', $done->group->name);

            $sub = Task::query()->where('external_ref', 'monday:111')->firstOrFail();
            $this->assertSame('Delivered', $sub->statusRow->name);
            $this->assertTrue($sub->statusRow->is_done, 'a new label on a done item is made a done status');
            $this->assertSame('2026-10-04', $sub->completed_at->toDateString());
            $this->assertNull(Task::query()->where('external_ref', 'monday:112')->value('completed_at'));

            $blank = Task::query()->where('external_ref', 'monday:12')->firstOrFail();
            $this->assertTrue($blank->statusRow->is_done, 'a blank status in a Completed group is done');

            $open = Task::query()->where('external_ref', 'monday:13')->firstOrFail();
            $this->assertFalse($open->statusRow->is_done, '"Done - Pending Review" is not done in monday either');
            $this->assertNull($open->completed_at);
            $this->assertSame('2026-11-12', $open->due_on->toDateString());
            $timeline = $open->values->first(fn ($v) => $v->column->name === 'Timeline');
            $this->assertSame('timeline', $timeline->column->type);
            $this->assertSame(['2026-11-10', '2026-11-12'], [$timeline->value_date instanceof \DateTimeInterface ? $timeline->value_date->format('Y-m-d') : substr((string) $timeline->value_date, 0, 10), $timeline->value_text]);
        });
    }

    public function test_every_update_file_becomes_an_attachment_once_whatever_its_size_or_type(): void
    {
        $items = [$this->item('21', 'Hire a sweep', ['updates' => [[
            'monday_id' => 'u1', 'author_email' => 'rodrigo@a-1chimney.com', 'author_name' => 'Rodrigo Mena', 'created_at' => '2026-10-02T09:00:00Z', 'body' => '',
            'files' => [
                ['monday_id' => 'a1', 'name' => 'resume.pdf', 'ext' => '.pdf', 'size' => 15, 'url' => null],
                // Over the 20 MB upload limit and off the upload type list: an import carries it anyway (Jose, 11-12).
                ['monday_id' => 'a2', 'name' => 'walkthrough.mp4', 'ext' => '.mp4', 'size' => 90 * 1048576, 'url' => null],
                ['monday_id' => 'a4', 'name' => 'logo.svg', 'ext' => '.svg', 'size' => 70, 'url' => null],
                // Nowhere to fetch it from: the one kind of file named instead.
                ['monday_id' => 'a3', 'name' => 'lost.pdf', 'ext' => '.pdf', 'size' => 10, 'url' => null],
            ],
            'replies' => [['monday_id' => 'r9', 'author_email' => 'jose@a-1chimney.com', 'created_at' => '2026-10-03T09:00:00Z', 'body' => 'Got it', 'files' => []]],
        ]]])];

        $this->import($items, ['--dry-run' => true]);
        $this->inTenant($this->tenant['org'], fn () => $this->assertSame(0, Attachment::query()->count(), 'a dry run stores no file'));

        $this->import($items);
        $this->import($items);

        $this->inTenant($this->tenant['org'], function () {
            $task = Task::query()->where('external_ref', 'monday:21')->firstOrFail();
            $updates = TaskUpdate::query()->where('task_id', $task->id)->orderBy('created_at')->get();
            $this->assertCount(2, $updates, 'a second run adds nothing');
            $this->assertSame($this->rodrigo->id, $updates[0]->user_id, 'matched by name when the monday email is not his EzBizi one');
            $this->assertSame('[file not carried from monday: lost.pdf (0.0 MB, could not be fetched)]', $updates[0]->body, 'named once, not once per run');
            $this->assertSame('monday:u1.r9', $updates[1]->external_ref);
            $this->assertSame($this->jose->id, $updates[1]->user_id);

            $files = Attachment::query()->orderBy('original_name')->get()->keyBy('original_name');
            $this->assertSame(['logo.svg', 'resume.pdf', 'walkthrough.mp4'], $files->keys()->all());
            foreach ($files as $file) {
                $this->assertSame('task_updates', $file->attachable_type);
                $this->assertSame($updates[0]->id, $file->attachable_id);
                Storage::disk($file->disk)->assertExists($file->path);
            }
            $this->assertSame(4096, $files['walkthrough.mp4']->size_bytes);
            // Off the upload list: served as a download, never rendered (an svg can carry script).
            $this->assertSame('application/octet-stream', $files['logo.svg']->mime);
            $this->assertSame('application/octet-stream', $files['walkthrough.mp4']->mime);
            $this->assertSame('application/pdf', $files['resume.pdf']->mime);
        });
    }

    public function test_the_earlier_runs_deleted_rows_are_restored_onto_the_personal_board(): void
    {
        [$oldId, $oldSubId] = $this->inTenant($this->tenant['org'], function () {
            // The 11-03 run put Jose's items on a custom board, deleted 11-09.
            $old = app(TaskBoards::class)->createCustom($this->jose, 'Jose Demasi', TaskBoard::MAIN, null, null);
            $writer = app(TaskWriter::class);
            $task = $writer->create($old, ['title' => 'Call the insurer', 'values' => []], $this->jose);
            $sub = $writer->create($old, ['title' => 'Find the policy', 'parent_task_id' => $task->id], $this->jose);
            $task->forceFill(['external_ref' => 'monday:31'])->saveQuietly();
            $sub->forceFill(['external_ref' => 'monday:311'])->saveQuietly();
            TaskUpdate::query()->create(['organization_id' => $task->organization_id, 'task_id' => $task->id, 'body' => 'Left a voicemail'])
                ->forceFill(['external_ref' => 'monday:u3'])->saveQuietly();
            TaskUpdate::query()->create(['organization_id' => $task->organization_id, 'task_id' => $task->id, 'body' => '↳ Try again'])
                ->forceFill(['external_ref' => 'monday:u3.0'])->saveQuietly();
            $writer->delete($task, $this->jose);
            $old->delete();

            return [$task->id, $sub->id];
        });

        $this->import([$this->item('31', 'Call the insurer', ['status' => 'Done', 'done' => true, 'completed_at' => '2026-11-01T00:00:00Z',
            'people' => ['jose@a-1chimney.com', 'rodrigo@a-1chimney.com'],
            'subitems' => [$this->item('311', 'Find the policy', ['status' => 'Working on it'])],
            'updates' => [['monday_id' => 'u3', 'author_email' => null, 'created_at' => '2026-10-02T09:00:00Z', 'body' => 'Left a voicemail', 'files' => [],
                'replies' => [['monday_id' => 'r77', 'author_email' => null, 'created_at' => '2026-10-03T09:00:00Z', 'body' => 'Try again', 'files' => []]]]],
        ])]);

        $this->inTenant($this->tenant['org'], function () use ($oldId, $oldSubId) {
            $task = Task::query()->where('external_ref', 'monday:31')->firstOrFail();
            $this->assertSame($oldId, $task->id, 'the deleted row is reused, not duplicated');
            $this->assertSame($this->jose->id, $task->board->owner_user_id);
            $this->assertTrue($task->statusRow->is_done);
            $this->assertSame($task->board_id, $task->statusRow->board_id);
            $this->assertSame([$this->jose->id], $task->owners->pluck('user_id')->all(), 'Rodrigo cannot own a task on Jose\'s board');
            $kept = TaskActivity::query()->where('task_id', $task->id)->where('field', 'monday_assignee')->get();
            $this->assertSame(['Rodrigo Mena'], $kept->pluck('to')->all(), 'so his Monday assignment is kept as words');
            $this->assertSame('Assigned in Monday', $kept[0]->label);

            $sub = Task::query()->where('external_ref', 'monday:311')->firstOrFail();
            $this->assertSame($oldSubId, $sub->id);
            $this->assertSame($task->id, $sub->parent_task_id);
            $this->assertSame($task->board_id, $sub->board_id);
            $this->assertSame('Working on it', $sub->statusRow->name);
            $this->assertSame($task->board_id, $sub->statusRow->board_id);

            $this->assertSame(2, TaskUpdate::query()->where('task_id', $task->id)->count(), 'the 11-03 reply key (by position) still counts');
            $this->assertSame(1, TaskStatus::query()->where('board_id', $task->board_id)->where('name', 'Done')->count());
        });
    }
}
