<?php

namespace Tests\Feature\Tasks;

use App\Models\MondayOutboxEntry;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
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
            $vars = (array) ($request->data()['variables'] ?? []);
            if (preg_match('/^\s*mutation\b/', $q)) {
                $this->mutations[] = ['query' => $q, 'vars' => $vars];
                $this->mondayUpdatedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');

                return Http::response(['data' => match (true) {
                    str_contains($q, 'create_item(') => ['create_item' => ['id' => '77']],
                    str_contains($q, 'create_update(') => ['create_update' => ['id' => '901']],
                    default => ['ok' => ['id' => '1']],
                }]);
            }

            return Http::response(['data' => match (true) {
                str_contains($q, 'users(') => ['users' => [['id' => '5', 'name' => 'Jose Demasi', 'email' => 'jose@a-1chimney.com']]],
                str_contains($q, 'updated_at board { id } group { id }') => ['items' => [['id' => $vars['i'][0], 'updated_at' => $this->mondayUpdatedAt, 'board' => ['id' => '100'], 'group' => ['id' => 'topics']]]],
                str_contains($q, 'groups { id title }') => ['boards' => [['id' => '100', 'groups' => [['id' => 'topics', 'title' => 'Open Tasks'], ['id' => 'done', 'title' => 'Completed Tasks']],
                    'columns' => [
                        ['id' => 'name', 'title' => 'Name', 'type' => 'name', 'settings_str' => '{}'],
                        ['id' => 'person', 'title' => 'Person', 'type' => 'people', 'settings_str' => '{}'],
                        ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => '{"labels":{"0":"Working on it","1":"Done"}}'],
                        ['id' => 'date4', 'title' => 'Due Date', 'type' => 'date', 'settings_str' => '{}'],
                        ['id' => 'color_area', 'title' => 'Area', 'type' => 'status', 'settings_str' => '{}'],
                    ]]]],
                default => [],
            }]);
        }]);
    }

    private function values(int $i = 0): array
    {
        return json_decode((string) ($this->mutations[$i]['vars']['v'] ?? '{}'), true);
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

    public function test_last_writer_wins_and_the_loser_is_on_the_activity(): void
    {
        // Monday changed the item at 10:45; the change here is at 11:00: here wins, Monday's change is the loser.
        $this->mondayUpdatedAt = '2026-11-13T10:45:00Z';
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task, ['status' => 'Stuck'], $this->jose));
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertSame(['status' => ['label' => 'Stuck']], $this->values());
        $said = fn () => $this->inTenant($this->tenant['org'], fn () => TaskActivity::query()->where('task_id', $this->task->id)->where('field', 'monday_sync')->pluck('to')->all());
        $this->assertStringContainsString('replaced it on Monday', $said()[0]);

        // Here at 11:10, Monday at 11:20 (before the push ran): Monday wins, nothing is sent.
        Carbon::setTestNow('2026-11-13 11:10:00');
        $this->inTenant($this->tenant['org'], fn () => app(TaskWriter::class)->update($this->task->fresh(), ['status' => 'Working on it'], $this->jose));
        $this->mondayUpdatedAt = '2026-11-13T11:20:00Z';
        Carbon::setTestNow('2026-11-13 11:21:00');
        $this->artisan('ezbizi:monday-push')->assertSuccessful();
        $this->assertCount(1, $this->mutations);
        $this->assertStringContainsString('Monday\'s is kept', $said()[1]);
        $this->assertSame(MondayOutboxEntry::SUPERSEDED, $this->inTenant($this->tenant['org'], fn () => MondayOutboxEntry::query()->latest('changed_at')->first()->state));
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

    public function test_what_the_monday_sync_writes_is_never_sent_back(): void
    {
        $this->inTenant($this->tenant['org'], fn () => \App\Support\Tasks\Monday\MondayOutbox::suppress(
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
