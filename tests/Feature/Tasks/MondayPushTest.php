<?php

namespace Tests\Feature\Tasks;

use App\Jobs\PushBoardMembers;
use App\Jobs\PushMondayShard;
use App\Models\AuditEvent;
use App\Models\MondayOutboxEntry;
use App\Models\Notification;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Tasks\Monday\MondayClient;
use App\Support\Tasks\Monday\MondayOutbox;
use App\Support\Tasks\Monday\MondayPush;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Task Board → Monday while both are in use (Jose, 2026-11-13: "keep the sync
 * parity for a week or two"). A change here is written to its Monday item;
 * last writer wins with the loser on the task's Activity; Monday's own
 * changes, brought by the sync, are never written back.
 */
class MondayPushTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private User $jose;

    private TaskBoard $board;

    private Task $task;

    /** @var list<array{query: string, vars: array}> */
    private array $mutations = [];

    private bool $archived = false;

    /** Column ids Monday's activity log shows changed since our change. */
    private array $mondayChanged = [];

    /** Who the item's Person column holds on Monday now. */
    private array $mondayPeople = [];

    /** An item's column values as Monday holds them (id => text), and a board's extra columns. */
    private array $itemValues = [];

    private array $extraColumns = [];

    /** Monday answers every read with "complexity budget exhausted, retry in 40 s". */
    private bool $budgetSpent = false;

    /** A Monday user id Monday refuses to assign on the board. */
    private ?string $refusePerson = null;

    /** The board's people on Monday. */
    private array $boardPeople = ['owners' => [], 'subscribers' => []];

    private array $mondayUsers = [['id' => '5', 'name' => 'Jose Demasi', 'email' => 'jose@a-1chimney.com']];

    private string $mondayUpdatedAt = '2026-11-13T10:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 11:00:00');
        $this->tenant = $this->provisionTenant('Alpha');
        $this->jose = $this->userWithRole($this->tenant, 'Owner', ['tasks.view', 'tasks.edit'], 'jose@ezbizi.test');
        $this->inTenant($this->tenant['org'], function () {
            $this->jose->forceFill(['name' => 'Jose Luis Demasi'])->save();
            $this->board = TaskBoard::query()->where('owner_user_id', $this->jose->id)->firstOrFail();
            $this->board->forceFill(['external_ref' => 'monday:100'])->save();
            TaskColumn::create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'name' => 'Area', 'type' => 'status',
                'position' => 1, 'settings' => ['options' => [['id' => 'monday-0', 'label' => 'Connecticut', 'colour' => null], ['id' => 'monday-1', 'label' => 'Ohio', 'colour' => null]]]]);
            $this->task = app(TaskWriter::class)->create($this->board, ['title' => 'Order the liner', 'assignee_user_ids' => [$this->jose->id]], $this->jose);
            $this->task->forceFill(['external_ref' => 'monday:11', 'external_updated_at' => '2026-11-13 10:00:00', 'external_synced_at' => '2026-11-13 10:00:00'])->saveQuietly();
            MondayOutboxEntry::query()->delete();

            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->tenant['org']->id, 'provider' => 'monday', 'remote_id' => 'acc1', 'display_name' => 'A-1 Group',
                'setup' => ['monday' => ['as' => $this->jose->id]],
            ]);
            $c->connect(['access_token' => 'monday-token', 'refresh_token' => null, 'scopes' => []]);
            $c->save();
        });

        Http::fake(['api.monday.com/*' => function (Request $request) {
            $q = (string) $request->data()['query'];
            if ($this->budgetSpent && ! preg_match('/^\s*mutation/', $q)) {
                return Http::response(['errors' => [['message' => 'budget', 'extensions' => ['code' => 'COMPLEXITY_BUDGET_EXHAUSTED', 'retry_in_seconds' => 40]]]]);
            }
            $vars = (array) ($request->data()['variables'] ?? []);
            if (preg_match('/^\s*mutation\b/', $q)) {
                $this->mutations[] = ['query' => $q, 'vars' => $vars];
                if ($this->refusePerson !== null && str_contains($q, 'change_multiple_column_values') && str_contains((string) ($vars['v'] ?? ''), '"id":'.$this->refusePerson)) {
                    return Http::response(['errors' => [['message' => 'invalid value - unable to assign person with id: '.$this->refusePerson.'. Please check our API documentation']]]);
                }
                if ($this->archived && str_contains($q, 'change_multiple_column_values')) {
                    return Http::response(['errors' => [['message' => 'Cannot change column value for inactive item', 'path' => ['change_multiple_column_values']]]]);
                }
                if ($this->archived && str_contains($q, 'create_update(')) {
                    return Http::response(['errors' => [['message' => 'User unauthorized to perform action', 'path' => ['create_update'],
                        'extensions' => ['status_code' => 403, 'code' => 'USER_UNAUTHORIZED', 'error_data' => ['failure_reason' => 'Access denied']]]]]);
                }
                $this->mondayUpdatedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');

                return Http::response(['data' => match (true) {
                    str_contains($q, 'create_item(') => ['create_item' => ['id' => '77']],
                    str_contains($q, 'create_update(') => ['create_update' => ['id' => '901']],
                    str_contains($q, 'create_group(') => ['create_group' => ['id' => 'g_new']],
                    str_contains($q, 'create_column(') => (function () use ($vars) {
                        // Monday now has it: the next read lists it.
                        $this->extraColumns[(string) $vars['b']][] = ['id' => 'text_new', 'title' => $vars['t'], 'type' => 'text', 'settings_str' => '{}'];

                        return ['create_column' => ['id' => 'text_new']];
                    })(),
                    default => ['ok' => ['id' => '1']],
                }]);
            }

            return Http::response(['data' => match (true) {
                str_contains($q, 'column_values { id text value }') => ['items' => [['column_values' => array_map(fn ($id, $t) => ['id' => $id, 'text' => $t, 'value' => '{}'], array_keys($this->itemValues), $this->itemValues)]]],
                str_contains($q, 'PeopleValue') => ['items' => [['column_values' => [['persons_and_teams' => $this->mondayPeople]]]]],
                str_contains($q, 'owners { id } subscribers { id }') => ['boards' => [$this->boardPeople]],
                str_contains($q, 'activity_logs(') => ['boards' => [['activity_logs' => array_map(fn ($c) => ['event' => 'update_column_value', 'user_id' => $c === 'person' ? '5' : '99', 'data' => json_encode(['column_id' => $c])], $this->mondayChanged)]]],
                str_contains($q, '{ me { id } }') => ['me' => ['id' => '5']],
                str_contains($q, 'users(') => ['users' => $this->mondayUsers],
                str_contains($q, 'updated_at board { id } group { id }') => ['items' => [['id' => $vars['i'][0], 'updated_at' => $this->mondayUpdatedAt, 'board' => ['id' => '100'], 'group' => ['id' => 'topics'], 'state' => $this->archived ? 'archived' : 'active']]],
                str_contains($q, 'groups { id title') => ['boards' => [['id' => '100', 'groups' => [['id' => 'topics', 'title' => 'Open Tasks'], ['id' => 'done', 'title' => 'Completed Tasks']],
                    'columns' => [
                        ['id' => 'name', 'title' => 'Name', 'type' => 'name', 'settings_str' => '{}'],
                        ['id' => 'person', 'title' => 'Person', 'type' => 'people', 'settings_str' => '{}'],
                        ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => '{"labels":{"0":"Working on it","1":"Done"}}'],
                        ['id' => 'date4', 'title' => 'Due Date', 'type' => 'date', 'settings_str' => '{}'],
                        ['id' => 'color_area', 'title' => 'Area', 'type' => 'status', 'settings_str' => '{}'],
                        ...($this->extraColumns[(string) ($vars['b'][0] ?? '')] ?? []),
                    ]]]],
                default => [],
            }]);
        }]);
    }

    private function values(int $i = 0): array
    {
        return json_decode((string) ($this->mutations[$i]['vars']['v'] ?? '{}'), true);
    }

    /** Jose 11-18: the job-id columns (any text column made here) are made on Monday too, then filled. */
    public function test_a_text_column_monday_lacks_is_made_there_and_filled(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            $col = TaskColumn::query()->create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'name' => 'Workiz Job ID', 'type' => 'text', 'position' => 20]);
            app(TaskWriter::class)->update($this->task, ['values' => [$col->id => '800021103']], $this->jose);
        });
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $made = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'create_column('));
        $this->assertSame(['b' => '100', 't' => 'Workiz Job ID', 'a' => 'name'], $made['vars'], 'right after Name');
        $values = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'change_multiple_column_values'));
        $this->assertSame('800021103', json_decode($values['vars']['v'], true)['text_new']);
    }

    /** Desk 11-19: a text column is made on Monday once, however many tasks need it in one run. */
    public function test_a_text_column_is_made_once_for_many_tasks(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            $col = TaskColumn::query()->create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'name' => 'Workiz Job ID', 'type' => 'text', 'position' => 20]);
            app(TaskWriter::class)->update($this->task, ['values' => [$col->id => '800021103']], $this->jose);
            $other = app(TaskWriter::class)->create($this->board, ['title' => 'Second'], $this->jose);
            $other->forceFill(['external_ref' => 'monday:12'])->saveQuietly();
            app(TaskWriter::class)->update($other->fresh(), ['values' => [$col->id => '800021104']], $this->jose);
        });
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $this->assertCount(1, collect($this->mutations)->filter(fn ($m) => str_contains($m['query'], 'create_column(')));
    }

    /** Desk 11-18: Monday files an item with no group in its FIRST group; a task with no group is never sent. */
    public function test_a_task_in_a_blank_named_group_is_held_not_dropped_in_mondays_first_group(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            $g = TaskGroup::create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'name' => ' ', 'position' => 9]);
            app(TaskWriter::class)->create($this->board, ['title' => 'Blank group', 'group_id' => $g->id], $this->jose);
        });
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $this->assertCount(0, collect($this->mutations)->filter(fn ($m) => str_contains($m['query'], 'create_item(')));
    }

    /** Desk 11-18: a task moved to another board here moves on Monday too. */
    public function test_a_task_moved_to_another_mirrored_board_moves_on_monday(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            $other = app(TaskBoards::class)->ensureFor($this->tenant['franchise']);
            $other->forceFill(['external_ref' => 'monday:200'])->saveQuietly();
            $group = $other->groups()->create(['organization_id' => $other->organization_id, 'name' => 'Open Tasks', 'position' => 99]);
            app(TaskWriter::class)->move($this->task->fresh(), $group, null, $this->jose);
        });
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $move = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'move_item_to_board('));
        $this->assertNotNull($move, 'moved on Monday');
        $this->assertSame(['i' => '11', 'b' => '200', 'g' => 'topics'], $move['vars']);
    }

    /** Desk 11-19: Monday's "wait N seconds" past the budget stops the run at once (no sleep, no timeout); the row waits. */
    public function test_a_monday_wait_past_the_budget_stops_the_run_and_the_row_stays_pending(): void
    {
        $this->budgetSpent = true;
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task, ['status' => 'Stuck'], $this->jose));
        $connection = SocialConnection::query()->withoutGlobalScopes()->where('provider', 'monday')->first();

        $started = microtime(true);
        app(MondayPush::class)->run($connection, 60, seconds: 10);

        $this->assertLessThan(5, microtime(true) - $started, 'did not sleep 40 s');
        $row = $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->where('task_id', $this->task->id)->first());
        $this->assertSame(MondayOutboxEntry::PENDING, $row->state);
        $this->assertSame(0, (int) $row->attempts, 'not counted as a failure');
        $this->assertNull(MondayClient::$deadline, 'no budget left behind for the next job');
    }

    /** Desk 11-18: a shard stops at its time budget (under the queue's retry_after); the rest wait, nothing is lost. */
    public function test_a_spent_time_budget_sends_nothing_more_and_leaves_rows_pending(): void
    {
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->create($this->board, ['title' => 'Later'], $this->jose));
        $connection = SocialConnection::query()->withoutGlobalScopes()->where('provider', 'monday')->first();
        $counts = app(MondayPush::class)->run($connection, 60, seconds: -1);

        $this->assertSame(0, $counts['sent']);
        $this->assertCount(0, $this->mutations);
        $this->assertSame(1, $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->where('state', MondayOutboxEntry::PENDING)->count()));
    }

    /** Desk 11-18: a live change goes before any deferred backfill row, however old the backfill's change. */
    public function test_live_rows_go_before_deferred_backfill_rows(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            foreach (range(1, 3) as $i) {
                MondayOutboxEntry::query()->create(['organization_id' => $this->tenant['org']->id, 'task_id' => $this->task->id,
                    'kinds' => ['title'], 'backfill' => true, 'changed_at' => now()->subHours(5), 'available_at' => now()->subMinute()]); // backfill, now due
            }
            app(TaskWriter::class)->create($this->board, ['title' => 'Missed call now'], $this->jose);
        });
        $this->artisan('ezbizi:monday-push', ['--limit' => 1])->assertSuccessful();

        $this->assertStringContainsString('create_item(', $this->mutations[0]['query'], 'the live create went first');
    }

    /** Desk 11-19: a live row whose retry moved available_at well past its change is still live, ahead of every backfill row. */
    public function test_a_retried_live_row_still_goes_before_backfill_rows(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            foreach (range(1, 3) as $i) {
                MondayOutboxEntry::query()->create(['organization_id' => $this->tenant['org']->id, 'task_id' => $this->task->id,
                    'kinds' => ['title'], 'backfill' => true, 'changed_at' => now()->subHours(5), 'available_at' => now()->subHours(4)]);
            }
            app(TaskWriter::class)->create($this->board, ['title' => 'Overbooked'], $this->jose);
            // It hit Monday's budget once: the retry put available_at 14 minutes after its change.
            MondayOutboxEntry::query()->whereJsonContains('kinds', 'create')->update(['attempts' => 1, 'changed_at' => now()->subMinutes(15), 'available_at' => now()->subMinute()]);
        });
        $this->artisan('ezbizi:monday-push', ['--limit' => 1])->assertSuccessful();

        $this->assertStringContainsString('create_item(', $this->mutations[0]['query'], 'the retried live create went first');
    }

    /** Desk 11-18: one switch pauses every sender; nothing is lost while paused. */
    public function test_pause_stops_every_sender_and_resume_sends_what_waited(): void
    {
        $this->artisan('ezbizi:monday-push', ['--pause' => 30])->assertSuccessful();
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task, ['status' => 'Stuck'], $this->jose));
        $this->artisan('ezbizi:monday-push', ['--shard' => '0/1'])->assertSuccessful();
        (new PushMondayShard(0, 1))->handle(app(MondayPush::class));
        $this->assertCount(0, $this->mutations, 'paused: nothing written');

        $this->artisan('ezbizi:monday-push', ['--resume' => true])->assertSuccessful();
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertSame(['status' => ['label' => 'Stuck']], $this->values());
    }

    /** Desk 11-18: a board's people reach Monday: added here → added there; removed here → unsubscribed there; Monday owners stay. */
    public function test_board_members_added_and_removed_here_reach_monday(): void
    {
        $ana = $this->userWithRole($this->tenant, 'Helper', ['tasks.view'], 'ana@a-1chimney.com');
        $gone = $this->userWithRole($this->tenant, 'Gone', ['tasks.view'], 'gone@a-1chimney.com');
        $this->mondayUsers = [['id' => '5', 'name' => 'Jose Demasi', 'email' => 'jose@a-1chimney.com'], ['id' => '6', 'name' => 'Ana', 'email' => 'ana@a-1chimney.com'], ['id' => '7', 'name' => 'Gone', 'email' => 'gone@a-1chimney.com']];
        $this->boardPeople = ['owners' => [['id' => '5']], 'subscribers' => [['id' => '7']]];
        $this->inTenant($this->tenant['org'], function () use ($ana, $gone) {
            TaskBoardMember::query()->create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'user_id' => $ana->id, 'role' => 'member']);
            $this->board->forceFill(['builtin_columns' => ['_removed' => [$gone->id]]])->save();
        });

        (new PushBoardMembers($this->board->id))->handle(app(TenantContext::class));

        $add = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'add_users_to_board('));
        $this->assertStringContainsString('kind:subscriber', $add['query']);
        $this->assertSame(['b' => '100', 'u' => ['6']], $add['vars']);
        $del = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'delete_subscribers_from_board('));
        $this->assertSame(['b' => '100', 'u' => ['7']], $del['vars']);
        $this->assertCount(2, $this->mutations, 'Jose, a Monday owner, untouched');

        // A personal board's own person is never pushed as a Monday owner.
        $this->mutations = [];
        $mine = $this->inTenant($this->tenant['org'], fn () => app(TaskBoards::class)->ensureFor($ana));
        $this->inTenant($this->tenant['org'], fn () => $mine->forceFill(['external_ref' => 'monday:300'])->saveQuietly());
        $this->boardPeople = ['owners' => [], 'subscribers' => []];
        (new PushBoardMembers($mine->id))->handle(app(TenantContext::class));
        $this->assertCount(0, $this->mutations);
    }

    /** Desk 11-18: parallel senders share the outbox by task; together they send every row once. */
    public function test_shards_split_the_outbox_by_task_and_send_each_row_once(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            foreach (['A', 'B', 'C', 'D'] as $t) {
                app(TaskWriter::class)->create($this->board, ['title' => 'Shard '.$t], $this->jose);
            }
        });
        $expected = $this->inTenant($this->tenant['org'], fn () => Task::query()->where('title', 'like', 'Shard %')->get()
            ->filter(fn ($t) => crc32($t->id) % 2 === 0)->pluck('title')->sort()->values()->all());
        $this->artisan('ezbizi:monday-push', ['--shard' => '0/2'])->assertSuccessful();
        $first = collect($this->mutations)->filter(fn ($m) => str_contains($m['query'], 'create_item('))->pluck('vars.n')->filter(fn ($n) => str_starts_with($n, 'Shard'))->sort()->values()->all();
        $this->artisan('ezbizi:monday-push', ['--shard' => '1/2'])->assertSuccessful();
        $made = collect($this->mutations)->filter(fn ($m) => str_contains($m['query'], 'create_item('))->pluck('vars.n')->sort()->values()->all();

        $this->assertSame(['Shard A', 'Shard B', 'Shard C', 'Shard D'], $made, 'each made once');
        $this->assertSame($expected, $first, 'shard 0 took exactly its own tasks');
    }

    /** Desk 11-18: Monday drops a value whose column the target board lacks; such a move is refused and said on the task. */
    public function test_a_move_whose_values_have_no_column_there_is_refused_and_a_missing_text_column_is_made(): void
    {
        $this->extraColumns = ['100' => [['id' => 'phone_1', 'title' => 'Phone', 'type' => 'phone', 'settings_str' => '{}'], ['id' => 'text_9', 'title' => 'Notes', 'type' => 'text', 'settings_str' => '{}']]];
        $this->itemValues = ['phone_1' => '+1 732 555 0101', 'text_9' => 'gate code 12'];
        $this->inTenant($this->tenant['org'], function () {
            $other = app(TaskBoards::class)->ensureFor($this->tenant['franchise']);
            $other->forceFill(['external_ref' => 'monday:200'])->saveQuietly();
            $group = $other->groups()->create(['organization_id' => $other->organization_id, 'name' => 'Open Tasks', 'position' => 99]);
            app(TaskWriter::class)->move($this->task->fresh(), $group, null, $this->jose);
        });
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $this->assertNull(collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'move_item_to_board(')), 'not moved: Phone would be lost');
        $this->assertStringContainsString('no "Phone" (phone) column', $this->inTenant($this->tenant['org'], fn () => TaskActivity::query()->where('task_id', $this->task->id)->where('field', 'monday_sync')->value('to')));

        // With a Phone column there too, the missing text column (Notes) is made, then it moves.
        $this->extraColumns['200'] = [['id' => 'phone_2', 'title' => 'Phone', 'type' => 'phone', 'settings_str' => '{}']];
        $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->update(['available_at' => now()]));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $made = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'create_column('));
        $this->assertSame(['b' => '200', 't' => 'Notes'], $made['vars']);
        $this->assertNotNull(collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'move_item_to_board(')));
    }

    public function test_a_change_here_is_written_to_its_monday_item_once(): void
    {
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task, [
            'title' => 'Order the 6" liner', 'status' => 'Done', 'due_on' => '2026-11-20',
            'values' => [TaskColumn::query()->where('name', 'Area')->value('id') => 'monday-1'],
        ], $this->jose));
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->move($this->task->fresh(), $this->board->groups()->where('name', 'Completed Tasks')->firstOrFail(), null, $this->jose));
        $this->assertSame(1, $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->count()), 'one pending row per task, fields merged');

        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $this->assertCount(2, $this->mutations);
        $this->assertStringContainsString('change_multiple_column_values', $this->mutations[0]['query']);
        $this->assertSame([
            'name' => 'Order the 6" liner', 'status' => ['label' => 'Done'], 'date4' => ['date' => '2026-11-20'], 'color_area' => ['label' => 'Ohio'],
        ], $this->values());
        $this->assertStringContainsString('move_item_to_group', $this->mutations[1]['query']);
        $this->assertSame('done', $this->mutations[1]['vars']['g']);

        $entry = $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->sole());
        $this->assertSame(MondayOutboxEntry::SENT, $entry->state);
        $task = $this->inTenant($this->tenant['org'], fn () => $this->task->fresh());
        $this->assertSame('2026-11-13 11:00:00', $task->external_updated_at->utc()->format('Y-m-d H:i:s'), 'the echo is already seen');

        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertCount(2, $this->mutations, 'sent once');
    }

    /** Jose 11-19: a group moved here moves on Monday after its new neighbour (update_group relative_position_after). */
    public function test_a_group_moved_here_moves_on_monday(): void
    {
        $groups = $this->inTenant($this->tenant['org'], function () {
            $gs = $this->board->groups()->orderBy('position')->get()->values();
            foreach ($gs as $i => $g) {
                $g->forceFill(['external_ref' => 'monday:grp'.$i])->save();
            }

            return $gs;
        });
        $last = $groups->last();
        $this->signedInAs($this->tenant['admin'])->patchJson("/api/v1/task-boards/{$this->board->id}/groups/{$last->id}", ['position' => 0])->assertOk();

        $names = $this->inTenant($this->tenant['org'], fn () => $this->board->groups()->orderBy('position')->pluck('id')->all());
        $this->assertSame($last->id, $names[0], 'first here, saved');
        $move = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'update_group('));
        $this->assertStringContainsString('relative_position_before', $move['query']);
        $this->assertSame(['b' => '100', 'g' => 'grp'.($groups->count() - 1), 'v' => 'grp0'], $move['vars']);
    }

    /** Desk 11-19: Monday refuses one owner on a private board: the others still go, and the task says so. */
    public function test_an_owner_monday_refuses_is_dropped_and_the_rest_are_sent(): void
    {
        $ana = $this->userWithRole($this->tenant, 'Helper', ['tasks.view', 'tasks.edit'], 'ana@a-1chimney.com');
        $this->mondayUsers[] = ['id' => '6', 'name' => 'Ana', 'email' => 'ana@a-1chimney.com'];
        $this->refusePerson = '6';
        $this->inTenant($this->tenant['org'], function () use ($ana) {
            TaskBoardMember::query()->create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'user_id' => $ana->id, 'role' => 'member']);
            app(TaskWriter::class)->update($this->task, ['assignee_user_ids' => [$this->jose->id, $ana->id]], $this->jose);
        });
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $this->assertSame([['id' => 5, 'kind' => 'person']], $this->values(count($this->mutations) - 1)['person']['personsAndTeams']);
        $this->assertSame(MondayOutboxEntry::SENT, $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->latest('changed_at')->first()->state));
    }

    /** Desk 11-19: our own earlier write of the Person column is not "Monday changed it": a rule task's owner still goes. */
    public function test_our_own_earlier_write_does_not_make_our_owner_change_lose(): void
    {
        Carbon::setTestNow('2026-11-13 11:10:00');
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task, ['assignee_user_ids' => [$this->jose->id]], null));
        $this->mondayUpdatedAt = '2026-11-13T11:20:00Z';
        $this->mondayChanged = ['person']; // written by our API user (5), not a person on Monday
        Carbon::setTestNow('2026-11-13 11:21:00');
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $this->assertSame([['id' => 5, 'kind' => 'person']], $this->values(count($this->mutations) - 1)['person']['personsAndTeams'] ?? null);
    }

    /** Desk 11-18: Monday's Person column never shrinks because someone here has no Monday account. */
    public function test_owners_keep_mondays_people_we_cannot_name_and_never_clear_the_column(): void
    {
        $this->mondayPeople = [['id' => '5', 'kind' => 'person'], ['id' => '77', 'kind' => 'person']];
        $nobody = $this->userWithRole($this->tenant, 'Helper', ['tasks.view', 'tasks.edit'], 'nobody@ezbizi.test');
        $this->inTenant($this->tenant['org'], fn () => TaskBoardMember::query()->create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'user_id' => $nobody->id, 'role' => 'member']));

        // Jose taken off, only someone Monday cannot name left: Jose goes, Monday's own #77 stays.
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task, ['assignee_user_ids' => [$nobody->id]], $this->jose));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertSame([['id' => 77, 'kind' => 'person']], $this->values(count($this->mutations) - 1)['person']['personsAndTeams']);
        $this->assertStringContainsString('no Monday account', $this->inTenant($this->tenant['org'], fn () => TaskActivity::query()->where('task_id', $this->task->id)->where('field', 'monday_sync')->value('to')));

        // Jose and him: Jose by his Monday id, Monday's own #77 kept.
        Carbon::setTestNow(now()->addMinute());
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task->fresh(), ['assignee_user_ids' => [$this->jose->id, $nobody->id]], $this->jose));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertSame([['id' => 5, 'kind' => 'person'], ['id' => 77, 'kind' => 'person']], $this->values(count($this->mutations) - 1)['person']['personsAndTeams']);
    }

    /** Desk 11-18: last writer wins per field; Monday touching another field never drops ours. */
    public function test_last_writer_wins_per_field_and_the_loser_is_on_the_activity(): void
    {
        $said = fn () => $this->inTenant($this->tenant['org'], fn () => TaskActivity::query()->where('task_id', $this->task->id)->where('field', 'monday_sync')->pluck('to')->all());

        // Monday changed the item before our change: ours is sent.
        $this->mondayUpdatedAt = '2026-11-13T10:45:00Z';
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task, ['status' => 'Stuck'], $this->jose));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertSame(['status' => ['label' => 'Stuck']], $this->values());

        // Monday changed the DUE DATE after our status change: our status still goes.
        Carbon::setTestNow('2026-11-13 11:10:00');
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task->fresh(), ['status' => 'Working on it'], $this->jose));
        $this->mondayUpdatedAt = '2026-11-13T11:20:00Z';
        $this->mondayChanged = ['date4'];
        Carbon::setTestNow('2026-11-13 11:21:00');
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertSame(['status' => ['label' => 'Working on it']], $this->values(count($this->mutations) - 1));

        // Monday changed the STATUS after ours: Monday's is kept, nothing sent.
        $sent = count($this->mutations);
        Carbon::setTestNow('2026-11-13 11:30:00');
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task->fresh(), ['status' => 'Done'], $this->jose));
        $this->mondayUpdatedAt = '2026-11-13T11:40:00Z';
        $this->mondayChanged = ['status'];
        Carbon::setTestNow('2026-11-13 11:41:00');
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertCount($sent, $this->mutations);
        $this->assertStringContainsString('Monday changed status', collect($said())->last());
        $this->assertSame(MondayOutboxEntry::SUPERSEDED, $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->latest('changed_at')->first()->state));
    }

    /** Jose 11-18, "full sync": a group made here (the complaint rule's) is made on Monday too, once. */
    public function test_a_task_in_a_group_monday_lacks_makes_the_group_there_once(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            $g = TaskGroup::create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'name' => 'Customer Experience', 'position' => 9]);
            foreach (['Complaint A', 'Complaint B'] as $t) {
                app(TaskWriter::class)->create($this->board, ['title' => $t, 'group_id' => $g->id], $this->jose);
            }
        });
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $groups = collect($this->mutations)->filter(fn ($m) => str_contains($m['query'], 'create_group('));
        $this->assertCount(1, $groups);
        $this->assertSame('Customer Experience', $groups->first()['vars']['n']);
        // Placed after the group before it, never at the top (Monday's default): desk 11-18.
        $this->assertSame('done', $groups->first()['vars']['r']);
        $this->assertStringContainsString('position_relative_method:after_at', $groups->first()['query']);
        $this->assertSame(['g_new', 'g_new'], collect($this->mutations)->filter(fn ($m) => str_contains($m['query'], 'create_item('))->pluck('vars.g')->values()->all());
    }

    /** Prod 11-18: a value change on an item archived on Monday ("inactive item") fails once, never retried. */
    public function test_a_change_on_an_inactive_monday_item_fails_once_with_the_reason(): void
    {
        $this->archived = true;
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task, ['status' => 'Stuck'], $this->jose));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $row = $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->where('task_id', $this->task->id)->latest('changed_at')->first());
        $this->assertSame(MondayOutboxEntry::FAILED, $row->state);
        $this->assertStringStartsWith('The item is archived on Monday', $row->error);
    }

    /** Desk 11-18: an update on an item archived on Monday was retried 5 times on a 403. */
    public function test_an_update_monday_refuses_on_an_archived_item_fails_once_with_a_reason_on_the_task(): void
    {
        $this->archived = true;
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->comment($this->task, $this->jose, 'Called him back'));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $row = $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->where('task_id', $this->task->id)->latest('changed_at')->first());
        $this->assertSame(MondayOutboxEntry::FAILED, $row->state);
        $this->assertStringStartsWith('The item is archived on Monday', $row->error);
        $this->assertSame('Not sent to Monday: The item is archived on Monday, which takes no changes to it.',
            $this->inTenant($this->tenant['org'], fn () => TaskActivity::query()->where('task_id', $this->task->id)->where('field', 'monday_sync')->value('to')));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertCount(1, collect($this->mutations)->filter(fn ($m) => str_contains($m['query'], 'create_update(')), 'never retried');
    }

    public function test_a_new_task_and_a_new_update_are_made_on_monday_and_keep_their_ids(): void
    {
        $made = $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->create($this->board, ['title' => 'Call the insurer', 'assignee_user_ids' => [$this->jose->id]], $this->jose));
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->comment($this->task, $this->jose, 'Policy found'));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $create = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'create_item('));
        $this->assertSame(['b' => '100', 'g' => 'topics', 'n' => 'Call the insurer'], array_intersect_key($create['vars'], array_flip(['b', 'g', 'n'])));
        $values = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'change_multiple_column_values') && $m['vars']['i'] === '77');
        $this->assertSame(['personsAndTeams' => [['id' => 5, 'kind' => 'person']]], json_decode($values['vars']['v'], true)['person'], 'Jose by his Monday name');
        $this->assertSame('monday:77', $this->inTenant($this->tenant['org'], fn () => $made->fresh()->external_ref));

        $post = collect($this->mutations)->first(fn ($m) => str_contains($m['query'], 'create_update('));
        $this->assertSame('11', $post['vars']['i']);
        $this->assertStringStartsWith("Policy found\n\n— from EzBizi, Jose Luis Demasi", $post['vars']['b']);
        $this->assertSame('monday:901', $this->inTenant($this->tenant['org'], fn () => TaskUpdate::query()->where('task_id', $this->task->id)->value('external_ref')));
    }

    /**
     * Jose 11-16: a branch board merged with its Monday board sends what is made on it from now on,
     * deletes included, but not its own work from before the merge (nor that work's subtasks).
     */
    public function test_a_merged_branch_board_sends_new_tasks_and_deletes_but_not_its_past(): void
    {
        [$branch, $old, $new, $sub] = $this->inTenant($this->tenant['org'], function () {
            $branch = TaskBoard::query()->where('franchise_id', $this->tenant['franchise']->id)->firstOrFail();
            $old = app(TaskWriter::class)->create($branch, ['title' => 'Pre-merge job'], $this->jose);
            $this->board->forceFill(['external_ref' => null])->save();
            $branch->forceFill(['external_ref' => 'monday:100'])->save(); // the merge
            app(TaskWriter::class)->update($old->fresh(), ['title' => 'Pre-merge job (edited)'], $this->jose);
            app(TaskWriter::class)->comment($old->fresh(), $this->jose, 'Called back');
            $this->assertSame(0, MondayOutboxEntry::query()->count(), 'the past is not pushed');
            $new = app(TaskWriter::class)->create($branch->fresh(), ['title' => 'After ship job'], $this->jose);
            $sub = app(TaskWriter::class)->create($branch->fresh(), ['title' => 'Bring the ladder', 'parent_task_id' => $old->id], $this->jose);

            return [$branch, $old, $new, $sub];
        });

        $this->artisan('ezbizi:monday-push')->assertSuccessful();

        $made = collect($this->mutations)->filter(fn ($m) => str_contains($m['query'], 'create_item(') || str_contains($m['query'], 'create_subitem('));
        $this->assertSame(['After ship job'], $made->pluck('vars.n')->values()->all(), 'only the new task; the subtask of a past task stays here');
        $this->assertSame('monday:77', $this->inTenant($this->tenant['org'], fn () => $new->fresh()->external_ref));
        $this->assertNull($this->inTenant($this->tenant['org'], fn () => $sub->fresh()->external_ref));

        $this->mutations = [];
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->delete($new->fresh(), $this->jose));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertCount(1, $this->mutations);
        $this->assertStringContainsString('archive_item(', $this->mutations[0]['query']);
        $this->assertSame('77', $this->mutations[0]['vars']['i']);
    }

    /** All Calls and Toula: archived on Monday, archived here, a read-only mirror (Jose via desk, 11-13). */
    public function test_an_archived_board_is_never_written_back_nor_polled(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            $this->board->forceFill(['archived_at' => now()])->save();
            app(TaskWriter::class)->update($this->task->fresh(), ['status' => 'Done'], $this->jose);
            app(TaskWriter::class)->comment($this->task->fresh(), $this->jose, 'Seen');
            $this->assertSame(0, MondayOutboxEntry::query()->count());
        });

        $this->artisan('ezbizi:monday-sync')->assertSuccessful();
        $this->assertSame([], $this->mutations);
        $this->assertSame([], array_values(array_filter(Http::recorded()->all(), fn ($r) => str_contains((string) $r[0]->data()['query'], 'items_page'))),
            'the 10-minute poll does not re-read an archived board');
    }

    /**
     * Desk 11-17: staff file most items on Monday. The sync's own write is
     * checked by the board rules; the move goes back to Monday, and the bell
     * goes to the item's Monday creator. The echo of that move changes nothing.
     */
    public function test_a_misfiled_item_from_monday_is_moved_and_the_move_goes_back(): void
    {
        $natalie = $this->userWithRole($this->tenant, 'CR', ['tasks.view', 'tasks.edit'], 'natalie@ezbizi.test');
        $this->inTenant($this->tenant['org'], function () use ($natalie) {
            $field = TaskGroup::create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'name' => 'Field Manager - Lucas', 'position' => 20]);
            $cr = TaskGroup::create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id, 'name' => 'Customer Relations - Natalie', 'position' => 21]);

            // As MondayImport: one transaction, the outbox suppressed inside it,
            // the Monday creator stamped after the create.
            DB::transaction(fn () => MondayOutbox::suppress(function () use ($field, $natalie) {
                $t = app(TaskWriter::class)->create($this->board, ['title' => 'Refund for Gilbert', 'group_id' => $field->id], $this->jose);
                $t->forceFill(['external_ref' => 'monday:77', 'created_by_user_id' => $natalie->id])->save();
            }));

            $task = Task::query()->where('external_ref', 'monday:77')->sole();
            $this->assertSame($cr->id, $task->group_id);
            $this->assertSame(['group'], MondayOutboxEntry::query()->where('task_id', $task->id)->sole()->kinds);
            $this->assertSame('Moved to Customer Relations: refunds start there',
                Notification::query()->where('user_id', $natalie->id)->where('title', 'like', 'Moved%')->value('title'));

            // Monday echoes the move: same group, nothing more happens.
            DB::transaction(fn () => MondayOutbox::suppress(
                fn () => app(TaskWriter::class)->update($task, ['group_id' => $cr->id], $this->jose)));
            $this->assertSame(1, AuditEvent::query()->where('action', 'task.board_rule.moved')->count());
            $this->assertSame(1, Notification::query()->where('user_id', $natalie->id)->where('title', 'like', 'Moved%')->count(), 'no second bell');
        });
    }

    public function test_what_the_monday_sync_writes_is_never_sent_back(): void
    {
        $this->inTenant($this->tenant['org'], fn () => MondayOutbox::suppress(
            fn () => app(TaskWriter::class)->update($this->task, ['status' => 'Done'], $this->jose)));
        $this->assertSame(0, $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->count()));

        // A board with no Monday board behind it records nothing either.
        $this->inTenant($this->tenant['org'], function () {
            $other = TaskBoard::query()->where('franchise_id', $this->tenant['franchise']->id)->firstOrFail();
            app(TaskWriter::class)->create($other, ['title' => 'Not on Monday'], $this->tenant['admin']);
        });
        $this->assertSame(0, $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->count()));
    }
}
