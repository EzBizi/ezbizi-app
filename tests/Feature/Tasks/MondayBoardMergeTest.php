<?php

namespace Tests\Feature\Tasks;

use App\Models\Automation;
use App\Models\MondayOutboxEntry;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardFolder;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-16: the imported Monday branch boards (NW - Nationwide, CT -
 * Connecticut…) beside the EzBizi branch boards "should have been moved
 * towards their franchise board instead. like both should just be one".
 */
class MondayBoardMergeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_monday_branch_board_folds_into_the_branch_board_which_then_answers_for_monday(): void
    {
        $t = $this->provisionTenant('Alpha');
        $jose = $t['admin'];
        $org = $t['org'];
        [$branch, $monday, $folder, $own] = $this->inTenant($org, function () use ($t, $jose, $org) {
            $branch = app(TaskBoards::class)->ensureFor($t['franchise']);
            $own = app(TaskWriter::class)->create($branch, ['title' => 'Branch job'], $jose);
            TaskColumn::create(['organization_id' => $org->id, 'board_id' => $branch->id, 'name' => 'Area', 'type' => 'text', 'position' => 1]);
            TaskColumn::create(['organization_id' => $org->id, 'board_id' => $branch->id, 'name' => 'Reason', 'type' => 'status', 'position' => 2,
                'settings' => ['options' => [['id' => 'r1', 'label' => 'Complaint', 'colour' => '#111111']]]]);
            TaskStatus::create(['organization_id' => $org->id, 'board_id' => $branch->id, 'name' => 'Pending', 'is_done' => false, 'position' => 9]);

            $folder = TaskBoardFolder::create(['organization_id' => $org->id, 'name' => 'Operations Management', 'position' => 1]);
            $monday = app(TaskBoards::class)->createCustom($jose, 'CT - Connecticut', TaskBoard::PRIVATE, null, $t['franchise']);
            $monday->forceFill(['external_ref' => 'monday:500', 'folder_id' => $folder->id])->save();

            return [$branch, $monday, $folder, $own];
        });
        [$task, $area, $reason, $doneGroup] = $this->inTenant($org, function () use ($monday, $org, $jose) {
            $doneGroup = TaskGroup::create(['organization_id' => $org->id, 'board_id' => $monday->id, 'name' => 'Done', 'colour' => '#00c875', 'position' => 9]);
            TaskStatus::create(['organization_id' => $org->id, 'board_id' => $monday->id, 'name' => 'Pending', 'is_done' => true, 'position' => 9]);
            $area = TaskColumn::create(['organization_id' => $org->id, 'board_id' => $monday->id, 'name' => 'Area', 'type' => 'status', 'position' => 1,
                'settings' => ['options' => [['id' => 'monday-0', 'label' => 'Connecticut', 'colour' => '#ff642e']]]]);
            $reason = TaskColumn::create(['organization_id' => $org->id, 'board_id' => $monday->id, 'name' => 'Reason', 'type' => 'status', 'position' => 2,
                'settings' => ['options' => [['id' => 'monday-3', 'label' => 'Complaint', 'colour' => '#df2f4a'], ['id' => 'monday-4', 'label' => 'Reschedule', 'colour' => null]]]]);
            $task = app(TaskWriter::class)->create($monday, ['title' => 'Call from Ann', 'group' => 'Done', 'status' => 'Pending',
                'values' => [$area->id => 'monday-0', $reason->id => 'monday-4']], $jose);
            app(TaskWriter::class)->create($monday, ['title' => 'Measure', 'parent_task_id' => $task->id], $jose);
            app(TaskWriter::class)->comment($task, $jose, 'Called back');
            $task->forceFill(['external_ref' => 'monday:11'])->saveQuietly();
            $rule = Automation::create(['name' => 'CT: Done', 'trigger_key' => 'task.status_changed', 'is_active' => true, 'created_by' => $jose->id,
                'scope_type' => 'franchise', 'scope_id' => $monday->franchise_id]);
            $rule->addVersion(['all' => [['field' => 'board_id', 'op' => 'eq', 'value' => $monday->id], ['field' => 'column_id', 'op' => 'eq', 'value' => $reason->id]]],
                [['type' => 'task.move_group', 'group_id' => $doneGroup->id]], $jose);

            return [$task, $area, $reason, $doneGroup];
        });

        $this->artisan('ezbizi:monday-merge-board', ['--board' => ['500'], '--org' => $org->id, '--as' => $jose->email, '--dry-run' => true])
            ->expectsOutputToContain('(dry run)')->assertSuccessful();
        $this->inTenant($org, fn () => $this->assertSame($monday->id, Task::query()->find($task->id)->board_id, 'a dry run moves nothing'));

        $this->artisan('ezbizi:monday-merge-board', ['--board' => ['500'], '--org' => $org->id, '--as' => $jose->email])
            ->expectsOutputToContain('conflict: status "Pending": not done on the branch board, done on Monday')
            ->expectsOutputToContain('conflict: column "Area": text on the branch board, status on Monday')
            ->assertSuccessful();

        $this->inTenant($org, function () use ($branch, $monday, $folder, $own, $task, $doneGroup, $jose) {
            $moved = Task::query()->with('statusRow', 'group')->findOrFail($task->id);
            $this->assertSame($branch->id, $moved->board_id);
            $this->assertSame('Done', $moved->group->name);
            $this->assertSame($branch->id, $moved->group->board_id);
            $this->assertSame('Pending', $moved->statusRow->name);
            $this->assertSame($branch->id, $moved->statusRow->board_id);
            $this->assertFalse($moved->statusRow->is_done, 'the branch board\'s status wins');
            $this->assertSame($branch->id, Task::query()->where('parent_task_id', $task->id)->value('board_id'), 'subtasks come along');
            $this->assertSame(1, \App\Models\TaskUpdate::query()->where('task_id', $task->id)->count());
            $this->assertSame($branch->id, Task::query()->find($own->id)->board_id, 'the branch board keeps its own');

            $cols = TaskColumn::query()->where('board_id', $branch->id)->get()->keyBy('name');
            $this->assertSame('text', $cols['Area']->type, 'the branch board\'s column stays');
            $this->assertSame('status', $cols['Area (Monday)']->type);
            $this->assertSame(['Complaint', 'Reschedule'], array_column($cols['Reason']->options(), 'label'), 'reused by name and type, the missing label added');
            $values = TaskColumnValue::query()->where('task_id', $task->id)->get()->keyBy('column_id');
            $this->assertSame('monday-0', $values[$cols['Area (Monday)']->id]->value_text);
            $reschedule = collect($cols['Reason']->options())->firstWhere('label', 'Reschedule')['id'];
            $this->assertSame($reschedule, $values[$cols['Reason']->id]->value_text);

            $this->assertSame('monday:500', $branch->fresh()->external_ref, 'the sync and webhooks find the branch board now');
            $this->assertNotNull(TaskBoard::query()->withTrashed()->find($monday->id)->deleted_at);
            $this->assertNull(TaskBoardFolder::query()->find($folder->id), 'the emptied folder goes');

            $rule = Automation::query()->with('activeVersion')->sole();
            $this->assertSame($branch->id, collect($rule->activeVersion->conditions['all'])->firstWhere('field', 'board_id')['value']);
            $this->assertSame($cols['Reason']->id, collect($rule->activeVersion->conditions['all'])->firstWhere('field', 'column_id')['value']);
            $this->assertSame($moved->group_id, $rule->activeVersion->actions[0]['group_id']);
            $this->assertSame(2, $rule->versions()->count(), 'a new version, the old kept');

            // Two-way: the Monday task on the branch board still goes back; the branch board's own does not.
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $branch->organization_id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $branch->organization_id, 'provider' => 'monday', 'remote_id' => 'acc1', 'display_name' => 'A-1 Group']);
            $c->connect(['access_token' => 'x', 'refresh_token' => null, 'scopes' => []]);
            $c->save();
            app(TaskWriter::class)->update($moved->fresh(), ['title' => 'Call from Ann (again)'], $jose);
            app(TaskWriter::class)->update(Task::query()->find($own->id), ['title' => 'Branch job 2'], $jose);
            app(TaskWriter::class)->create($branch->fresh(), ['title' => 'New branch job'], $jose);
            $this->assertSame([$task->id], MondayOutboxEntry::query()->pluck('task_id')->all());
        });
    }

    /** NW on prod sits on no branch (restored from the 11-03 run): --franchise names it; without it, nothing moves. */
    public function test_a_board_on_no_branch_merges_into_the_branch_named_with_franchise(): void
    {
        $t = $this->provisionTenant('Alpha');
        $nw = $this->inTenant($t['org'], function () use ($t) {
            $b = app(TaskBoards::class)->createCustom($t['admin'], 'NW - Nationwide', TaskBoard::PRIVATE, null, null);
            $b->forceFill(['external_ref' => 'monday:10084134034'])->save();
            app(TaskWriter::class)->create($b, ['title' => 'Call'], $t['admin']);

            return $b;
        });
        $args = ['--board' => ['10084134034'], '--org' => $t['org']->id, '--as' => $t['admin']->email];
        $this->artisan('ezbizi:monday-merge-board', $args)->expectsOutputToContain('name its branch with --franchise')->assertFailed();

        $spec = $this->inTenant($t['org'], fn () => \App\Models\Company::query()->findOrFail($t['franchise']->company_id)->name.'/'.$t['franchise']->name);
        $this->artisan('ezbizi:monday-merge-board', $args + ['--franchise' => $spec])->assertSuccessful();
        $this->inTenant($t['org'], function () use ($t, $nw) {
            $branch = TaskBoard::query()->where('kind', TaskBoard::KIND_FRANCHISE)->where('franchise_id', $t['franchise']->id)->sole();
            $this->assertSame('monday:10084134034', $branch->external_ref);
            $this->assertSame($branch->id, Task::query()->where('title', 'Call')->value('board_id'));
            $this->assertSame($t['franchise']->id, Task::query()->where('title', 'Call')->value('franchise_id'));
            $this->assertNotNull(TaskBoard::query()->withTrashed()->find($nw->id)->deleted_at);
        });
    }
}
