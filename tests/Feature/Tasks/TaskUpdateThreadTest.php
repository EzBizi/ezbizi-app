<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Chaim 11-24 (Monday's update panel): replies under their update, likes, and who has seen it. */
class TaskUpdateThreadTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_reply_hangs_under_its_update_a_like_toggles_and_seen_counts_readers(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $other = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit']);
        $api = fn () => $this->signedInAs($tenant['admin']);
        $task = $api()->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Liner'])->assertCreated()->json('data.id');
        $top = $api()->postJson("/api/v1/tasks/{$task}/updates", ['body' => 'Booked for Friday'])->assertCreated()->json('data.id');
        $reply = $api()->postJson("/api/v1/tasks/{$task}/updates", ['body' => 'Thanks', 'parent_id' => $top])->assertCreated()->json('data');
        $this->assertSame($top, $reply['parent_id']);
        // A reply to a reply is refused: one level, as on Monday.
        $api()->postJson("/api/v1/tasks/{$task}/updates", ['body' => 'x', 'parent_id' => $reply['id']])->assertNotFound();

        $this->assertSame(['likes' => 1, 'liked' => true], $api()->postJson("/api/v1/tasks/{$task}/updates/{$top}/like")->assertOk()->json('data'));
        $this->assertSame(['likes' => 0, 'liked' => false], $api()->postJson("/api/v1/tasks/{$task}/updates/{$top}/like")->assertOk()->json('data'));
        $this->signedInAs($other)->postJson("/api/v1/tasks/{$task}/updates/{$top}/like")->assertOk();

        // The other person reads the task: the admin's update now counts one seen.
        $this->travel(1)->minutes();
        $this->signedInAs($other)->postJson('/api/v1/note-reads', ['type' => 'task', 'id' => $task])->assertOk();
        $updates = collect($api()->getJson("/api/v1/tasks/{$task}")->assertOk()->json('data.updates'))->keyBy('id');
        $this->assertSame(1, $updates[$top]['seen']);
        $this->assertSame(1, $updates[$top]['likes']);
        $this->assertFalse($updates[$top]['liked']);
        $this->assertSame($top, $updates[$reply['id']]['parent_id']);
    }

    /** Monday's update feed (gap review P1): every board's updates, mentions, unread, mark all read. */
    public function test_the_feed_lists_updates_across_boards_filters_and_marks_all_read(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $other = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit']);
        $task = $this->signedInAs($tenant['admin'])->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Liner'])->assertCreated()->json('data.id');
        $this->signedInAs($other)->postJson("/api/v1/tasks/{$task}/updates", ['body' => 'Parts came'])->assertCreated();
        $this->travel(1)->minutes();
        $this->signedInAs($other)->postJson("/api/v1/tasks/{$task}/updates", ['body' => '@you look', 'mentions' => [$tenant['admin']->id]])->assertCreated();

        $feed = fn (string $q = '') => $this->signedInAs($tenant['admin'])->getJson('/api/v1/task-updates'.$q)->assertOk();
        $all = $feed()->json();
        $this->assertSame(['@you look', 'Parts came'], collect($all['data'])->pluck('body')->all());
        $this->assertSame('Liner', $all['data'][0]['task']);
        $this->assertSame(2, collect($all['meta']['boards'])->firstWhere('id', $board->id)['count']);
        $this->assertSame(['@you look'], collect($feed('?filter=mentions')->json('data'))->pluck('body')->all());
        $this->assertCount(2, $feed('?unread=1')->json('data'));

        $this->travel(1)->minutes();
        $this->signedInAs($tenant['admin'])->postJson('/api/v1/task-updates/read-all')->assertOk();
        $this->assertCount(0, $feed('?unread=1')->json('data'));
    }
}
