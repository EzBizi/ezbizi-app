<?php

namespace Tests\Feature\Tasks;

use App\Jobs\PushMondayGroup;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Row 69 (Jose 11-25): Monday's group menu in full. */
class TaskGroupMenuTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_duplicate_move_archive_restore_and_colour(): void
    {
        $t = $this->provisionTenant('Alpha');
        $api = fn () => $this->signedInAs($t['admin']);
        $board = $this->inTenant($t['org'], fn () => TaskBoard::query()->where('franchise_id', $t['franchise']->id)->firstOrFail());
        $g = $api()->postJson("/api/v1/task-boards/{$board->id}/groups", ['name' => 'Leads'])->assertCreated()->json('data.id');
        $api()->postJson("/api/v1/task-boards/{$board->id}/groups", ['name' => 'Other'])->assertCreated();
        foreach (['One', 'Two'] as $title) {
            $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'group_id' => $g, 'title' => $title])->assertCreated();
        }

        // Colour, and it goes to Monday when the group is Monday's.
        Bus::fake([PushMondayGroup::class]);
        $this->inTenant($t['org'], fn () => TaskGroup::query()->whereKey($g)->update(['external_ref' => 'monday:topics']));
        $api()->patchJson("/api/v1/task-boards/{$board->id}/groups/{$g}", ['colour' => '#ff642e'])->assertOk();
        Bus::assertDispatched(PushMondayGroup::class, fn ($j) => $j->attributes === ['color']);

        // Duplicate with items: "Duplicate of Leads" just below, its two tasks copied.
        $copy = $api()->postJson("/api/v1/task-boards/{$board->id}/groups/{$g}/duplicate", ['with_items' => true])->assertCreated()->json('data');
        $this->assertSame('Duplicate of Leads', $copy['name']);
        $this->assertSame('#ff642e', $copy['colour']);
        $this->inTenant($t['org'], fn () => $this->assertSame(['One', 'Two'], Task::query()->where('group_id', $copy['id'])->orderBy('title')->pluck('title')->all()));
        $bare = $api()->postJson("/api/v1/task-boards/{$board->id}/groups/{$g}/duplicate", ['with_items' => false])->assertCreated()->json('data.id');
        $this->inTenant($t['org'], fn () => $this->assertSame(0, Task::query()->where('group_id', $bare)->count()));

        // Archive: off the board with its tasks; restore brings both back.
        $api()->postJson("/api/v1/task-boards/{$board->id}/groups/{$copy['id']}/archive")->assertOk();
        $shown = $api()->getJson("/api/v1/task-boards/{$board->id}")->assertOk()->json('data');
        $this->assertNotContains($copy['id'], collect($shown['groups'])->pluck('id'));
        $this->assertContains($copy['id'], collect($shown['archived_groups'])->pluck('id'));
        $this->inTenant($t['org'], fn () => $this->assertSame(0, Task::query()->where('group_id', $copy['id'])->count()));
        $api()->postJson("/api/v1/task-boards/{$board->id}/groups/{$copy['id']}/restore")->assertOk();
        $this->inTenant($t['org'], fn () => $this->assertSame(2, Task::query()->where('group_id', $copy['id'])->count()));

        // Move to board: the group and its tasks land on the other board.
        $other = $api()->postJson('/api/v1/task-boards', ['name' => 'Elsewhere', 'visibility' => 'main'])->assertCreated()->json('data.id');
        $moved = $api()->postJson("/api/v1/task-boards/{$board->id}/groups/{$copy['id']}/move", ['board_id' => $other])->assertOk()->json('data');
        $this->assertSame(2, $moved['moved']);
        $this->inTenant($t['org'], function () use ($moved, $other, $copy) {
            $this->assertSame(2, Task::query()->where('board_id', $other)->where('group_id', $moved['group_id'])->count());
            $this->assertNull(TaskGroup::query()->find($copy['id']), 'the emptied group leaves its old board');
        });
    }
}
