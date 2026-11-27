<?php

namespace Tests\Feature\Tasks;

use App\Models\Notification;
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
        $this->signedInAs($other)->postJson("/api/v1/tasks/{$task}/updates", ['body' => '@you <b>look</b>', 'mentions' => [$tenant['admin']->id]])->assertCreated();

        $feed = fn (string $q = '') => $this->signedInAs($tenant['admin'])->getJson('/api/v1/task-updates'.$q)->assertOk();
        $all = $feed()->json();
        $this->assertSame(['@you <b>look</b>', 'Parts came'], collect($all['data'])->pluck('body')->all());
        $this->assertSame('Liner', $all['data'][0]['task']);
        $this->assertSame(2, collect($all['meta']['boards'])->firstWhere('id', $board->id)['count']);
        $this->assertSame(['@you <b>look</b>'], collect($feed('?filter=mentions')->json('data'))->pluck('body')->all());
        $this->assertCount(2, $feed('?unread=1')->json('data'));

        $this->travel(1)->minutes();
        $this->signedInAs($tenant['admin'])->postJson('/api/v1/task-updates/read-all')->assertOk();
        $this->assertCount(0, $feed('?unread=1')->json('data'));
    }

    /** Chaim 11-24 (Monday's update card): the bell follows replies, and checklist lines tick. */
    public function test_following_an_update_rings_on_its_replies_and_a_checklist_line_ticks(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $tech = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit']);
        $watcher = $this->userWithRole($tenant, 'Watcher', ['tasks.view', 'tasks.edit']);
        $api = fn ($u) => $this->signedInAs($u);
        $task = $api($tenant['admin'])->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Liner'])->assertCreated()->json('data.id');
        $top = $api($tenant['admin'])->postJson("/api/v1/tasks/{$task}/updates", ['body' => "Steps\n☐ Order the liner\n☐ Book the crew"])->assertCreated()->json('data.id');

        $this->assertTrue($api($watcher)->postJson("/api/v1/tasks/{$task}/updates/{$top}/follow")->assertOk()->json('data.following'));
        $api($tech)->postJson("/api/v1/tasks/{$task}/updates", ['body' => 'Ordered', 'parent_id' => $top])->assertCreated();
        $this->inTenant($tenant['org'], fn () => $this->assertSame(1, Notification::query()->where('user_id', $watcher->id)->where('kind', 'task.reply.thread')->count()));
        // Row 67: the update's author hears it as a reply to their own update.
        $this->inTenant($tenant['org'], fn () => $this->assertSame(1, Notification::query()->where('user_id', $tenant['admin']->id)->where('kind', 'task.reply.mine')->count()));

        $body = $api($tech)->postJson("/api/v1/tasks/{$task}/updates/{$top}/check", ['line' => 1])->assertOk()->json('data.body');
        $this->assertSame("Steps\n☑ Order the liner\n☐ Book the crew", $body);
        $api($tech)->postJson("/api/v1/tasks/{$task}/updates/{$top}/check", ['line' => 0])->assertStatus(422);
    }

    /** Row 67 (Monday's notification settings): each reason rings as its own kind. */
    public function test_owner_reaction_invitation_and_deleted_update_ring_their_own_kinds(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $tech = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit']);
        $api = fn ($u) => $this->signedInAs($u);
        $kinds = fn ($u) => $this->inTenant($tenant['org'], fn () => Notification::query()->where('user_id', $u->id)->pluck('kind')->all());
        $task = $api($tenant['admin'])->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Liner', 'assignee_user_ids' => [$tech->id]])->assertCreated()->json('data.id');

        $mine = $api($tenant['admin'])->postJson("/api/v1/tasks/{$task}/updates", ['body' => 'Booked'])->assertCreated()->json('data.id');
        $this->assertContains('task.update.owner', $kinds($tech), 'an update on an item I own');

        $api($tech)->postJson("/api/v1/tasks/{$task}/updates/{$mine}/like")->assertOk();
        $this->assertContains('task.reaction', $kinds($tenant['admin']));
        $api($tenant['admin'])->postJson("/api/v1/tasks/{$task}/updates/{$mine}/like")->assertOk();
        $this->assertSame(1, collect($kinds($tenant['admin']))->filter(fn ($k) => $k === 'task.reaction')->count(), 'never my own like');

        $api($tenant['admin'])->deleteJson("/api/v1/tasks/{$task}/updates/{$mine}")->assertSuccessful();
        $this->assertContains('task.update_deleted', $kinds($tech));
        $this->assertNotContains('task.update_deleted', $kinds($tenant['admin']), 'not who deleted it');

        $new = $this->userWithRole($tenant, 'Helper', ['tasks.view', 'tasks.edit']);
        $api($tenant['admin'])->postJson("/api/v1/task-boards/{$board->id}/members", ['user_id' => $new->id, 'role' => 'member'])->assertCreated();
        $this->assertContains('task.invited', $kinds($new), "Monday's board invitation");
    }

    /** Monday's feed tabs (gap review): Bookmarked, and a Scheduled update unseen until its time. */
    public function test_a_bookmark_lists_under_bookmarked_and_a_scheduled_update_posts_at_its_time(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first());
        $tech = $this->userWithRole($tenant, 'Tech', ['tasks.view', 'tasks.edit']);
        $api = fn ($u) => $this->signedInAs($u);
        $task = $api($tenant['admin'])->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Liner'])->assertCreated()->json('data.id');
        $now = $api($tenant['admin'])->postJson("/api/v1/tasks/{$task}/updates", ['body' => 'Booked'])->assertCreated()->json('data.id');

        $this->assertTrue($api($tech)->postJson("/api/v1/tasks/{$task}/updates/{$now}/bookmark")->assertOk()->json('data.bookmarked'));
        $this->assertSame([$now], collect($api($tech)->getJson('/api/v1/task-updates?filter=bookmarked')->json('data'))->pluck('id')->all());

        $later = $api($tenant['admin'])->postJson("/api/v1/tasks/{$task}/updates", ['body' => '@crew <b>tomorrow</b> at 8', 'mentions' => [$tech->id], 'post_at' => now()->addHour()->toIso8601String()])
            ->assertCreated()->json('data');
        $this->assertNotNull($later['scheduled_for']);
        $this->assertSame([$later['id']], collect($api($tenant['admin'])->getJson('/api/v1/task-updates?filter=scheduled')->json('data'))->pluck('id')->all());
        $this->assertNotContains($later['id'], collect($api($tech)->getJson("/api/v1/tasks/{$task}")->json('data.updates'))->pluck('id')->all(), 'nobody sees it before its time');
        $mentioned = fn () => $this->inTenant($tenant['org'], fn () => Notification::query()->where('user_id', $tech->id)->where('kind', 'task.mentioned')->count());
        $this->assertSame(0, $mentioned());

        $this->travel(61)->minutes();
        $this->artisan('ezbizi:tasks-post-scheduled-updates')->expectsOutputToContain('posted: 1')->assertSuccessful();
        $this->assertContains($later['id'], collect($api($tech)->getJson("/api/v1/tasks/{$task}")->json('data.updates'))->pluck('id')->all());
        $this->assertSame(1, $mentioned());
        // Jose 11-25: the bell, push and email read the words, never the tags.
        $this->assertSame('@crew tomorrow at 8', $this->inTenant($tenant['org'], fn () => Notification::query()->where('user_id', $tech->id)->where('kind', 'task.mentioned')->value('body')));
        $this->artisan('ezbizi:tasks-post-scheduled-updates')->expectsOutputToContain('posted: 0')->assertSuccessful();
    }
}
