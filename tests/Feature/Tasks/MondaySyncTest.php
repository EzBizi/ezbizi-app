<?php

namespace Tests\Feature\Tasks;

use App\Models\MondayOutboxEntry;
use App\Models\Notification;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Tasks\JobTitle;
use App\Support\Tasks\Monday\MondayOutbox;
use App\Support\Tasks\Monday\MondaySync;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\FakesMonday;
use Tests\TestCase;

/**
 * Monday → Task Board after the import (desk, 2026-11-12: "a poll every 10
 * minutes for the imported boards ... that upserts items, statuses,
 * assignees, dates, subitems and new updates"). Last writer wins.
 */
class MondaySyncTest extends TestCase
{
    use BuildsTenants;
    use FakesMonday;
    use RefreshDatabase;

    private array $tenant;

    private User $jose;

    private SocialConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 11:00:00');
        $this->tenant = $this->provisionTenant('Alpha');
        $this->jose = $this->userWithRole($this->tenant, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards'], 'jose@ezbizi.test');
        $this->inTenant($this->tenant['org'], fn () => $this->jose->forceFill(['name' => 'Jose Luis Demasi'])->save());
        $this->monday['users'] = [['id' => '1', 'name' => 'Jose Demasi', 'email' => 'jose@a-1chimney.com']];

        // The import, as the desk runs it: Jose's personal monday board onto his own board.
        $file = tempnam(sys_get_temp_dir(), 'monday');
        file_put_contents($file, json_encode(['people' => ['jose@a-1chimney.com' => 'jose@ezbizi.test'], 'users' => [], 'boards' => [[
            'monday_id' => '100', 'name' => 'Jose Demasi', 'owner_email' => 'jose@a-1chimney.com', 'groups' => [], 'items' => [[
                'monday_id' => '11', 'name' => 'Call the insurer', 'group' => 'Open Tasks', 'created_at' => '2026-11-01T09:00:00Z',
                'updated_at' => '2026-11-12T10:00:00Z', 'creator_email' => 'jose@a-1chimney.com', 'status' => 'Working on it', 'done' => false,
                'people' => ['jose@a-1chimney.com'], 'columns' => [], 'subitems' => [], 'updates' => [],
            ]],
        ]]]));
        $this->artisan('ezbizi:tasks-import-monday', ['file' => $file, '--org' => $this->tenant['org']->id, '--as' => 'jose@a-1chimney.com'])->assertSuccessful();
        @unlink($file);

        $this->connection = $this->inTenant($this->tenant['org'], function () {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->tenant['org']->id, 'provider' => 'monday', 'remote_id' => 'acc1', 'display_name' => 'A-1 Group',
                'setup' => ['monday' => ['as' => $this->jose->id]],
            ]);
            $c->connect(['access_token' => 'monday-token', 'refresh_token' => null, 'scopes' => []]);
            $c->save();

            return $c;
        });
        $this->fakeMonday();
    }

    private function task(string $id): ?Task
    {
        return $this->inTenant($this->tenant['org'], fn () => Task::query()->with('statusRow', 'owners')->where('external_ref', 'monday:'.$id)->first());
    }

    private function poll(array $items): void
    {
        $this->monday['boards'] = ['100' => $this->mondayBoard('100', 'Jose Demasi', $items)];
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();
    }

    /** Desk 11-20: an item Monday sent with no Status gets ours, and ours goes back so Monday is not left blank. */
    public function test_a_blank_status_from_monday_is_pushed_back(): void
    {
        Carbon::setTestNow('2026-11-12 12:05:00');
        $item = $this->mondayItem('12', 'Order a liner', '2026-11-12T12:00:00Z');
        $item['column_values'][1]['text'] = '';
        $item['column_values'][1]['label'] = null;
        $item['column_values'][1]['index'] = null;
        $item['column_values'][1]['value'] = null;
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z'), $item]);

        $queued = $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->whereJsonContains('kinds', 'status')->pluck('task_id')->all());
        $this->assertSame([$this->task('12')->id], $queued, 'only the blank one');
    }

    /** Desk 11-19: a board stopped part way keeps what it did; the next run reads only the rest, then moves the cursor. */
    public function test_a_board_stopped_part_way_resumes_with_the_rest(): void
    {
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T10:00:00Z')]);
        $cursor = $this->connection->fresh()->setup['monday']['cursors']['100'];

        // The last run did item 11 for this cursor and was stopped before 12.
        $setup = $this->connection->fresh()->setup;
        $setup['monday']['partial']['100'] = ['cursor' => $cursor, 'done' => ['11']];
        $this->inTenant($this->tenant['org'], fn () => $this->connection->forceFill(['setup' => $setup])->save());

        $this->monday['boards'] = ['100' => $this->mondayBoard('100', 'Jose Demasi', [
            $this->mondayItem('11', 'Call the insurer AGAIN', '2026-11-12T12:01:00Z'),
            $this->mondayItem('12', 'Order a liner', '2026-11-12T12:02:00Z'),
        ])];
        Carbon::setTestNow('2026-11-12 12:10:00');
        $this->mondayQueries = [];
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();

        $this->assertNotNull($this->task('12'), 'the rest was read');
        $this->assertSame('Call the insurer', $this->task('11')->title, 'what was done is not read again');
        $after = $this->connection->fresh()->setup['monday'];
        $this->assertArrayNotHasKey('100', (array) ($after['partial'] ?? []), 'finished: no partial left');
        $this->assertNotSame($cursor, $after['cursors']['100'], 'finished: the cursor moved');
    }

    /** Desk 11-19: a sync past its budget stops between boards, so the lane (and parity behind it) is freed. */
    public function test_a_sync_past_its_deadline_reads_no_more_boards(): void
    {
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->monday['boards'] = ['100' => $this->mondayBoard('100', 'Jose Demasi', [$this->mondayItem('12', 'Order a liner', '2026-11-12T12:00:00Z')])];

        app(MondaySync::class)->run($this->connection, deadline: microtime(true) - 1);
        $this->assertNull($this->task('12'), 'past the deadline: no board read');

        app(MondaySync::class)->run($this->connection, deadline: microtime(true) + 600);
        $this->assertNotNull($this->task('12'), 'within it: read');
    }

    public function test_the_import_marks_its_board_for_the_sync(): void
    {
        $board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('owner_user_id', $this->jose->id)->sole());
        $this->assertSame('monday:100', $board->external_ref);
        $this->assertSame('2026-11-12 10:00:00', $this->task('11')->external_updated_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_poll_brings_mondays_changes_new_items_subitems_and_updates(): void
    {
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->monday['subitem_columns'] = [['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => json_encode(['done_colors' => [1]])]];
        $this->poll([
            $this->mondayItem('11', 'Call the insurer again', '2026-11-12T12:00:00Z', ['status' => 'Done', 'changed_at' => '2026-11-12T11:58:00Z', 'due' => '2026-11-20',
                'updates' => [$this->mondayUpdate('501', 'Policy found', '2026-11-12T11:59:00Z', [$this->mondayUpdate('502', 'Great', '2026-11-12T12:00:00Z')])]]),
            $this->mondayItem('12', 'Order a liner', '2026-11-12T11:30:00Z', ['subitems' => [
                ['board' => ['id' => '900']] + $this->mondayItem('121', 'Measure the flue', '2026-11-12T11:30:00Z'),
            ]]),
        ]);

        $done = $this->task('11');
        $this->assertSame('Call the insurer again', $done->title);
        $this->assertTrue($done->statusRow->is_done);
        $this->assertSame('2026-11-12 11:58:00', $done->completed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20', $done->due_on->toDateString());
        $this->assertSame([$this->jose->id], $done->owners->pluck('user_id')->all(), 'matched by name across the two emails');
        $updates = $this->inTenant($this->tenant['org'], fn () => TaskUpdate::query()->where('task_id', $done->id)->orderBy('created_at')->get());
        $this->assertSame(['Policy found', '↳ Great'], $updates->pluck('body')->all());
        $this->assertSame([$this->jose->id, $this->jose->id], $updates->pluck('user_id')->all());

        $new = $this->task('12');
        $this->assertSame($done->board_id, $new->board_id);
        $this->assertSame($new->id, $this->task('121')->parent_task_id);

        $this->connection->refresh();
        $this->assertSame('2026-11-12T12:00:00+00:00', $this->connection->setup['monday']['cursors']['100']);

        // Nothing new: a second poll changes nothing and writes nothing twice.
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();
        $this->assertSame(2, $this->inTenant($this->tenant['org'], fn () => TaskUpdate::query()->where('task_id', $done->id)->count()));
        $this->assertSame(3, $this->inTenant($this->tenant['org'], fn () => Task::query()->whereNotNull('external_ref')->count()));
    }

    /** Desk 11-18: a task moved to another board here stopped 29 syncs ("no column called <Area id>"). */
    /** Desk 11-18: an item moved to another board ON MONDAY (nothing changed here) follows it here. */
    public function test_an_item_moved_to_another_board_on_monday_follows_it_here(): void
    {
        $home = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('external_ref', 'monday:100')->sole());
        $col = fn ($board, $name, $type, $settings = null) => TaskColumn::query()->create(['organization_id' => $this->tenant['org']->id, 'board_id' => $board->id, 'name' => $name, 'type' => $type, 'position' => 50, 'settings' => $settings]);
        [$notes, $area] = $this->inTenant($this->tenant['org'], function () use ($home, $col) {
            $other = app(TaskBoards::class)->ensureFor($this->tenant['franchise']);
            $task = Task::query()->where('external_ref', 'monday:11')->first();
            $values = [
                $col($other, 'Notes', 'text')->id => 'gate code 12',
                $col($other, 'Area', 'status', ['options' => [['id' => 'o1', 'label' => 'Texas', 'colour' => '#000']]])->id => 'o1',
                $col($other, 'Only there', 'text')->id => 'gone',
            ];
            foreach ($values as $c => $v) {
                TaskColumnValue::query()->create(['organization_id' => $task->organization_id, 'task_id' => $task->id, 'column_id' => $c, 'value_text' => $v]);
            }
            // As if it had been on the other board and Monday moved it to board 100: no change here since the sync.
            DB::table('tasks')->where('external_ref', 'monday:11')->update(['board_id' => $other->id, 'external_synced_at' => now()->addMinute(), 'updated_at' => now()]);

            return [$col($home, 'Notes', 'text'), $col($home, 'Area', 'status', ['options' => [['id' => 'x9', 'label' => 'Texas', 'colour' => '#000']]])];
        });
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z', ['status' => 'Done'])]);

        $this->assertSame($home->id, $this->task('11')->board_id);
        // Carried as Monday carries them: same name and type; a status by its label; the rest dropped.
        $now = $this->inTenant($this->tenant['org'], fn () => TaskColumnValue::query()->where('task_id', $this->task('11')->id)->pluck('value_text', 'column_id')->all());
        $this->assertSame('gate code 12', $now[$notes->id] ?? null);
        $this->assertSame('x9', $now[$area->id] ?? null);
        $this->assertNotContains('gone', $now);
    }

    public function test_a_task_moved_to_another_board_here_stays_there_and_the_sync_goes_on(): void
    {
        $elsewhere = $this->inTenant($this->tenant['org'], function () {
            $b = app(TaskBoards::class)->ensureFor($this->tenant['franchise']);
            Task::query()->where('external_ref', 'monday:11')->update(['board_id' => $b->id, 'updated_at' => now()->addMinutes(5)]); // moved here, after the last sync

            return $b;
        });
        Carbon::setTestNow('2026-11-12 12:05:00');
        $board = $this->mondayBoard('100', 'Jose Demasi', [
            $this->mondayItem('11', 'Call the insurer again', '2026-11-12T12:00:00Z', ['status' => 'Done']),
            $this->mondayItem('12', 'Order a liner', '2026-11-12T12:00:00Z'),
        ]);
        $board['columns'][] = ['id' => 'area', 'title' => 'Area', 'type' => 'status', 'settings_str' => json_encode(['labels' => ['0' => 'North']])];
        foreach ($board['items'] as &$i) {
            $i['column_values'][] = ['id' => 'area', 'type' => 'status', 'text' => 'North', 'label' => 'North', 'index' => 0, 'value' => json_encode(['index' => 0])];
        }
        unset($i);
        $this->monday['boards'] = ['100' => $board];
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();

        $this->assertSame($elsewhere->id, $this->task('11')->board_id);
        $this->assertNotNull($this->task('12'), 'the next item still arrived');
    }

    /** Desk 11-18: a group renamed on Monday is renamed here, same group, with no item changed; a starter group with no tasks goes. */
    public function test_a_quiet_pass_renames_a_group_monday_renamed_and_keeps_its_tasks(): void
    {
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z')]);
        $before = $this->task('11')->group_id;

        $this->monday['boards']['100']['groups'][0]['title'] = 'James (Mar after 4 PM)';
        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();

        $group = $this->inTenant($this->tenant['org'], fn () => TaskGroup::query()->find($before));
        $this->assertSame('James (Mar after 4 PM)', $group->name);
        $this->assertSame($before, $this->task('11')->group_id, 'same group, its task stays');
        $names = $this->inTenant($this->tenant['org'], fn () => TaskGroup::query()->where('board_id', $group->board_id)->orderBy('position')->pluck('name')->all());
        $this->assertSame(['James (Mar after 4 PM)', 'Completed Tasks'], array_slice($names, 0, 2), "Monday's groups, in Monday's order, first");
        $this->assertNotContains('Guidelines', $names, 'an empty starter group Monday lacks goes');
    }

    /** Desk 11-18: an item archived on Monday is moved to the trash here (restorable), logged. */
    public function test_an_item_archived_on_monday_goes_to_the_trash_here(): void
    {
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z') + ['state' => 'archived']]);
        $this->assertNull($this->task('11'));
        $this->assertNotNull($this->inTenant($this->tenant['org'], fn () => Task::withTrashed()->where('external_ref', 'monday:11')->first()?->deleted_at));
    }

    /** Jose 11-18, "everything must be mirrored": the nightly parity check finds both sides' gaps and closes them. */
    public function test_parity_finds_what_one_side_lacks_and_fix_closes_it(): void
    {
        $board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('external_ref', 'monday:100')->sole());
        $mine = $this->inTenant($this->tenant['org'], fn () => MondayOutbox::suppress(fn () => app(TaskWriter::class)->create($board, ['title' => 'Made before the outbox'], $this->jose)));
        $this->monday['boards'] = ['100' => $this->mondayBoard('100', 'Jose Demasi', [
            $this->mondayItem('11', 'Call the insurer', '2026-11-12T10:30:00Z', ['group' => 'Completed Tasks']),
            $this->mondayItem('13', 'Only on Monday', '2026-11-12T10:30:00Z'),
        ])];

        $gone = $this->inTenant($this->tenant['org'], fn () => MondayOutbox::suppress(function () use ($board) {
            $t = app(TaskWriter::class)->create($board, ['title' => 'Deleted on Monday'], $this->jose);
            $t->forceFill(['external_ref' => 'monday:99'])->saveQuietly();

            return $t;
        }));
        $this->artisan('ezbizi:monday-parity')->expectsOutputToContain('tasks only here 1, items only on Monday 1, moved here not there 0, gone on Monday 1, in another group 1, name differs 1')->assertFailed();
        $this->artisan('ezbizi:monday-parity', ['--fix' => true])->assertSuccessful();

        $this->assertNotNull($this->task('13'));
        $this->assertNull($this->task('99'), 'removed on Monday, removed here');
        $this->assertSame('Jose Demasi', $this->inTenant($this->tenant['org'], fn () => $board->fresh()->name), "Monday's board name");
        $this->assertSame('Completed Tasks', $this->inTenant($this->tenant['org'], fn () => $this->task('11')->group->name), 'moved on Monday later: moved here');
        $this->assertTrue($this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->where('task_id', $mine->id)->whereJsonContains('kinds', 'create')->exists()));
    }

    /** Desk 11-19: Client name filled here, empty on Monday, and nothing saw it. --values counts it; --fix queues it. */
    public function test_value_parity_finds_a_value_monday_lacks_and_queues_it(): void
    {
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z'), $this->mondayItem('12', 'Order a liner', '2026-11-12T12:00:00Z')]);
        [$client, $same] = $this->inTenant($this->tenant['org'], function () {
            $board = TaskBoard::query()->where('external_ref', 'monday:100')->sole();
            $col = TaskColumn::query()->create(['organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => 'Client name', 'type' => 'text', 'position' => 9]);
            foreach (['11' => 'Pamela Gignon', '12' => 'Tom Garangelli'] as $ref => $v) {
                TaskColumnValue::query()->create(['organization_id' => $board->organization_id, 'task_id' => Task::query()->where('external_ref', 'monday:'.$ref)->value('id'), 'column_id' => $col->id, 'value_text' => $v]);
            }

            return [$col, Task::query()->where('external_ref', 'monday:12')->value('id')];
        });
        $board = $this->monday['boards']['100'];
        $board['columns'][] = ['id' => 'text_c', 'title' => 'Client name', 'type' => 'text', 'settings_str' => '{}'];
        $board['items'][0]['column_values'][] = ['id' => 'text_c', 'type' => 'text', 'text' => '', 'value' => null];             // empty there
        $board['items'][1]['column_values'][] = ['id' => 'text_c', 'type' => 'text', 'text' => 'tom  garangelli', 'value' => null]; // same, as a person reads it
        // Desk 11-20: a duplicate "Client name" column, empty, after it: the one with the value counts.
        $board['columns'][] = ['id' => 'text_c2', 'title' => 'Client name', 'type' => 'text', 'settings_str' => '{}'];
        $board['items'][1]['column_values'][] = ['id' => 'text_c2', 'type' => 'text', 'text' => '', 'value' => null];
        $this->monday['boards'] = ['100' => $board];

        $this->artisan('ezbizi:monday-parity', ['--values' => true])->expectsOutputToContain('values differ 1')->assertFailed();
        $this->artisan('ezbizi:monday-parity', ['--values' => true, '--fix' => true])->assertSuccessful();

        $queued = $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->whereJsonContains('kinds', 'values:'.$client->id)->pluck('task_id')->all());
        $this->assertSame([$this->task('11')->id], $queued, 'only the one Monday lacks');
        $this->assertNotContains($same, $queued);
    }

    /** Desk 11-18: missing from Monday's board listing is not proof; an item Monday says is active is never trashed. */
    public function test_parity_trashes_only_what_monday_says_is_archived_or_deleted(): void
    {
        $board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('external_ref', 'monday:100')->sole());
        [$live, $dead] = $this->inTenant($this->tenant['org'], fn () => MondayOutbox::suppress(function () use ($board) {
            $a = app(TaskWriter::class)->create($board, ['title' => 'Still on Monday'], $this->jose);
            $a->forceFill(['external_ref' => 'monday:77'])->saveQuietly();
            $b = app(TaskWriter::class)->create($board, ['title' => 'Deleted on Monday'], $this->jose);
            $b->forceFill(['external_ref' => 'monday:78'])->saveQuietly();

            return [$a, $b];
        }));
        $this->monday['boards'] = ['100' => $this->mondayBoard('100', 'Jose Demasi', [$this->mondayItem('11', 'Call the insurer', '2026-11-12T10:00:00Z')])];
        $this->monday['states'] = ['77' => 'active', '78' => 'deleted'];

        $this->artisan('ezbizi:monday-parity', ['--fix' => true, '--boards' => $board->name])->assertSuccessful();

        $this->assertNotNull($this->task('77'), 'active on Monday: kept');
        $this->assertNull($this->task('78'), 'deleted on Monday: trashed here');
    }

    /** Desk 11-18: the 30-minute run also trashes what Monday deleted, and skips boards above --max-items. */
    public function test_the_thirty_minute_run_trashes_monday_deletions_and_skips_big_boards(): void
    {
        $board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('external_ref', 'monday:100')->sole());
        $this->inTenant($this->tenant['org'], fn () => MondayOutbox::suppress(function () use ($board) {
            app(TaskWriter::class)->create($board, ['title' => 'Deleted on Monday'], $this->jose)->forceFill(['external_ref' => 'monday:78'])->saveQuietly();
        }));
        $this->monday['boards'] = ['100' => $this->mondayBoard('100', 'Jose Demasi', [$this->mondayItem('11', 'Call the insurer', '2026-11-12T10:00:00Z')])];
        $this->monday['states'] = ['78' => 'deleted'];

        // Two Monday items here: above a max of 1, the board is left to the nightly run.
        $this->artisan('ezbizi:monday-parity', ['--fix' => true, '--groups-only' => true, '--gone' => true, '--max-items' => 1])->assertSuccessful();
        $this->assertNotNull($this->task('78'));

        $this->artisan('ezbizi:monday-parity', ['--fix' => true, '--groups-only' => true, '--gone' => true, '--max-items' => 2000])->assertSuccessful();
        $this->assertNull($this->task('78'));
    }

    /** Desk 11-18: the 30-minute run settles groups only: Monday's move arrives, nothing else is queued or trashed. */
    public function test_groups_only_parity_pulls_mondays_move_and_queues_nothing_else(): void
    {
        $board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('external_ref', 'monday:100')->sole());
        $mine = $this->inTenant($this->tenant['org'], fn () => MondayOutbox::suppress(fn () => app(TaskWriter::class)->create($board, ['title' => 'Only here'], $this->jose)));
        $this->monday['boards'] = ['100' => $this->mondayBoard('100', 'Jose Demasi', [
            $this->mondayItem('11', 'Call the insurer', '2026-11-12T10:00:00Z', ['group' => 'Completed Tasks']),
        ])];

        $this->artisan('ezbizi:monday-parity', ['--fix' => true, '--groups-only' => true, '--boards' => 'Nothing,%'.substr($board->name, 1, 3).'%'])->assertSuccessful();

        $this->assertSame('Completed Tasks', $this->inTenant($this->tenant['org'], fn () => $this->task('11')->group->name), 'same clock, still pulled');
        $this->assertFalse($this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->where('task_id', $mine->id)->exists()), 'no create queued');
    }

    /** Nehorai, CT GM, 11-17: joined after the import; his branch board refused his tasks. */
    public function test_someone_who_joins_later_gets_the_branch_boards_monday_shares_with_them(): void
    {
        $branch = $this->inTenant($this->tenant['org'], function () {
            $b = app(TaskBoards::class)->ensureFor($this->tenant['franchise']);
            $b->forceFill(['external_ref' => 'monday:200'])->saveQuietly();

            return $b;
        });
        $nehorai = $this->userWithRole($this->tenant, 'Member', ['tasks.view', 'tasks.edit'], 'nehorai@ezbizi.test');
        $this->monday['users'][] = ['id' => '2', 'name' => 'Nehorai Maamari', 'email' => 'nehorai@ezbizi.test'];
        $this->monday['boards'] = ['200' => $this->mondayBoard('200', 'Connecticut', [$this->mondayItem('21', 'Call back', '2026-11-12T12:00:00Z', ['people' => ['2']])])
            + ['subscribers' => [['id' => '2']]]];
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();

        $this->assertSame('member', $this->inTenant($this->tenant['org'], fn () => TaskBoardMember::query()
            ->where('board_id', $branch->id)->where('user_id', $nehorai->id)->value('role')));
        $this->assertSame([$nehorai->id], $this->task('21')->owners->pluck('user_id')->all());

        // Removed here by an admin: the next pass does not put him back, and Monday's silence removes nobody.
        $row = $this->inTenant($this->tenant['org'], fn () => TaskBoardMember::query()->where('board_id', $branch->id)->where('user_id', $nehorai->id)->value('id'));
        $this->signedInAs($this->tenant['admin'])->deleteJson("/api/v1/task-boards/{$branch->id}/members/{$row}")->assertNoContent();
        $other = $this->userWithRole($this->tenant, 'Kept', ['tasks.view'], 'kept@ezbizi.test');
        $this->inTenant($this->tenant['org'], fn () => TaskBoardMember::query()->create(['organization_id' => $this->tenant['org']->id, 'board_id' => $branch->id, 'user_id' => $other->id, 'role' => 'member']));
        Carbon::setTestNow('2026-11-12 12:20:00');
        $this->artisan('ezbizi:monday-sync', ['--full' => true])->assertSuccessful();
        $roles = $this->inTenant($this->tenant['org'], fn () => TaskBoardMember::query()->where('board_id', $branch->id)->pluck('role', 'user_id')->all());
        $this->assertArrayNotHasKey($nehorai->id, $roles);
        $this->assertArrayHasKey($other->id, $roles);
    }

    /**
     * 2026-11-16: the poll listed every item of 39 boards (88k) every ten
     * minutes, ~4 min, and a 5-min scheduler slot timed out. A pass with
     * nothing new asks Monday's date filter once per board and reads no item.
     */
    public function test_a_quiet_pass_asks_only_mondays_date_filter_and_reads_no_item(): void
    {
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z')]);
        $this->assertSame('2026-11-12T12:00:00+00:00', $this->connection->fresh()->setup['monday']['cursors']['100']);

        // The next day, Monday unchanged.
        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->mondayQueries = [];
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();

        // The date filter, then the board itself (name, groups, members: renames touch no item, desk 11-18).
        $this->assertStringContainsString('__last_updated__', $this->mondayQueries[0]);
        $this->assertLessThanOrEqual(5, count($this->mondayQueries), implode(' | ', $this->mondayQueries));
        $this->assertSame([], array_filter($this->mondayQueries, fn ($q) => str_contains($q, 'items(ids:')), 'no item or update read');

        // A change since the cursor is still found, and only it is read.
        $this->monday['boards']['100']['items'][] = $this->mondayItem('12', 'New job', '2026-11-13T08:30:00Z') + ['_board' => '100'];
        $this->mondayQueries = [];
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();
        $this->assertNotNull($this->task('12'));
        $this->assertSame('2026-11-13T08:30:00+00:00', $this->connection->fresh()->setup['monday']['cursors']['100']);
    }

    public function test_last_writer_wins_a_change_here_after_mondays_stays_and_a_later_monday_change_wins(): void
    {
        Carbon::setTestNow('2026-11-12 11:30:00');
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task('11'), ['status' => 'Stuck'], $this->jose));

        Carbon::setTestNow('2026-11-12 11:45:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T11:15:00Z', ['status' => 'Done'])]);
        $this->assertSame('Stuck', $this->task('11')->statusRow->name, 'Monday changed at 11:15, here at 11:30: here wins');

        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z', ['status' => 'Done'])]);
        $this->assertSame('Done', $this->task('11')->statusRow->name, 'Monday changed again at 12:00: Monday wins');
    }

    /** Jose 11-19: an item made on Monday as "Job #N - Name" arrives as "Name", N in its column, and the clean title goes back. */
    /** Desk 11-19: a task given to someone on Monday rings their bell here, and the change is Monday's, not the connection user's. */
    public function test_an_assignment_made_on_monday_notifies_the_person_here(): void
    {
        $ana = $this->userWithRole($this->tenant, 'Helper', ['tasks.view', 'tasks.edit'], 'ana@a-1chimney.com');
        $this->monday['users'][] = ['id' => '2', 'name' => 'Ana', 'email' => 'ana@a-1chimney.com'];
        $this->inTenant($this->tenant['org'], fn () => TaskBoardMember::query()->create(['organization_id' => $this->tenant['org']->id,
            'board_id' => TaskBoard::query()->where('external_ref', 'monday:100')->value('id'), 'user_id' => $ana->id, 'role' => 'member']));
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z', ['people' => ['2']])]);

        $this->assertSame(1, $this->inTenant($this->tenant['org'], fn () => Notification::query()->where('user_id', $ana->id)->where('kind', 'task.assigned')->count()));
        $this->assertNull($this->inTenant($this->tenant['org'], fn () => TaskActivity::query()->where('task_id', $this->task('11')->id)->where('field', 'owner')->latest('created_at')->value('user_id')), 'Monday, not Jose');
    }

    public function test_a_job_number_title_from_monday_is_split_and_sent_back_clean(): void
    {
        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('31', 'Job #800020972 - Kirstin Deimel', '2026-11-12T12:00:00Z')]);

        $task = $this->task('31');
        $this->assertSame('Kirstin Deimel', $task->title);
        $this->assertSame('800020972', $this->inTenant($this->tenant['org'], fn () => TaskColumnValue::query()->with('column')->where('task_id', $task->id)->get()->firstWhere('column.name', 'Workiz Job ID')?->value_text));
        $this->assertTrue($this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->where('task_id', $task->id)->whereJsonContains('kinds', 'title')->exists()), 'the clean title goes back to Monday');
        $this->assertNull(JobTitle::split('Call back Mr Smith'));
    }

    /** Desk 11-18: a title still waiting in the outbox is never put back by Monday's older title. */
    public function test_a_field_waiting_in_the_outbox_is_not_overwritten_by_monday(): void
    {
        Carbon::setTestNow('2026-11-12 11:30:00');
        // Renamed as the night backfill did: a direct write (no Activity row) and its outbox row.
        $this->inTenant($this->tenant['org'], function () {
            $t = $this->task('11');
            DB::table('tasks')->where('id', $t->id)->update(['title' => 'Insurer', 'updated_at' => now()]);
            MondayOutboxEntry::query()->create(['organization_id' => $t->organization_id, 'task_id' => $t->id, 'kinds' => ['title'], 'changed_at' => now(), 'available_at' => now()->addHour()]);
        });

        // Monday still shows the old title, its clock (our earlier push of another field) before our rename.
        Carbon::setTestNow('2026-11-12 11:45:00');
        $this->poll([$this->mondayItem('11', 'Job #600019688 - Call the insurer', '2026-11-12T11:20:00Z', ['status' => 'Done'])]);

        $this->assertSame('Insurer', $this->task('11')->title, 'ours waits for the push');
        $this->assertTrue($this->inTenant($this->tenant['org'], fn () => $this->task('11')->statusRow->is_done), "Monday's other fields still arrive");
    }

    /** Desk 11-18: an owner change here later must not keep Monday's move to Done from arriving. */
    public function test_both_changed_each_side_keeps_its_own_fields(): void
    {
        Carbon::setTestNow('2026-11-12 11:30:00');
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task('11'), ['title' => 'Call the insurer (renamed here)'], $this->jose));

        Carbon::setTestNow('2026-11-12 11:45:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T11:15:00Z', ['group' => 'Completed Tasks'])]);

        $task = $this->task('11');
        $this->assertSame('Call the insurer (renamed here)', $task->title, 'the field changed here stays');
        $this->assertSame('Completed Tasks', $this->inTenant($this->tenant['org'], fn () => $task->group->name), "Monday's move arrives");
    }

    /** 2026-11-17: Monday's "assign creator" put Jose over Ana and Tiara on 22 missed-call tasks a rule made. */
    public function test_a_rule_made_task_keeps_its_people_when_monday_names_only_our_api_user(): void
    {
        $ana = $this->userWithRole($this->tenant, 'Helper', ['tasks.view'], 'ana@ezbizi.test');
        $this->inTenant($this->tenant['org'], function () use ($ana) {
            $task = $this->task('11');
            $task->board->members()->create(['organization_id' => $this->tenant['org']->id, 'user_id' => $ana->id, 'role' => 'member']);
            app(TaskWriter::class)->update($task, ['assignee_user_ids' => [$ana->id]], $this->jose);
            $task->forceFill(['created_by_automation_id' => (string) Str::uuid()])->saveQuietly();
        });

        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z', ['status' => 'Done'])]);

        $this->assertSame('Done', $this->task('11')->statusRow->name, 'the status still syncs');
        $this->assertSame([$ana->id], $this->task('11')->owners->pluck('user_id')->all(), 'the rule\'s person stays');
    }

    public function test_a_board_imported_before_boards_were_marked_is_adopted(): void
    {
        $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('owner_user_id', $this->jose->id)->update(['external_ref' => null]));

        Carbon::setTestNow('2026-11-12 12:05:00');
        $this->poll([$this->mondayItem('11', 'Call the insurer', '2026-11-12T12:00:00Z', ['status' => 'Done'])]);

        $this->assertSame('monday:100', $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('owner_user_id', $this->jose->id)->value('external_ref')));
        $this->assertTrue($this->task('11')->statusRow->is_done);
    }

    public function test_a_refused_token_marks_the_connection_broken(): void
    {
        $this->monday['refuse'] = true;
        $this->artisan('ezbizi:monday-sync')->assertFailed();

        $this->assertSame(SocialConnection::STATUS_BROKEN, $this->connection->refresh()->status);
        $this->assertNull($this->task('11')->completed_at);
    }
}
