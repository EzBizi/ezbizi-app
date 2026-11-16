<?php

namespace Tests\Feature\Tasks;

use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        $this->assertCount(1, $this->mondayQueries, 'one query for the one board: '.implode(' | ', $this->mondayQueries));
        $this->assertStringContainsString('__last_updated__', $this->mondayQueries[0]);
        $this->assertSame([], array_filter($this->mondayQueries, fn ($q) => str_contains($q, 'items(ids:') || str_contains($q, 'users(')),
            'no item, update or user read');

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
