<?php

namespace Tests\Feature\Tasks;

use App\Console\Commands\TasksCleanup202611;
use App\Models\Assignment;
use App\Models\Lead;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\User;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The 2026-11-02 Task Board cleanup Jose approved (review section 5, items 2-4). */
class TasksCleanup202611Test extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    private User $jonathan;

    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant('Alpha');
        $this->board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $this->tenant['franchise']->id)->first());
        $this->jonathan = $this->userWithRole($this->tenant, 'Jonathan', ['tasks.view', 'tasks.edit']);
        $this->inTenant($this->tenant['org'], function () {
            $role = Role::query()->where('name', 'Jonathan')->first();
            $adeline = new User;
            $adeline->forceFill([
                'id' => TasksCleanup202611::ADELINE, 'organization_id' => $this->tenant['org']->id, 'role_id' => $role->id,
                'name' => 'Adeline', 'email' => 'adeline@alpha.test', 'password' => 'a-real-password', 'is_active' => true,
                'email_verified_at' => now(),
            ])->save();
            Assignment::create(['organization_id' => $this->tenant['org']->id, 'user_id' => $adeline->id, 'assigned_organization_id' => $this->tenant['org']->id]);
        });
        $this->ids = $this->seedBoard();
    }

    /** New York's shape: an uncoloured CX group and a text Description, as the complaint rule made them. */
    private function seedBoard(): array
    {
        return $this->inTenant($this->tenant['org'], function () {
            $group = TaskGroup::query()->create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id,
                'name' => 'Customer Experience', 'colour' => null, 'position' => 99]);
            $desc = TaskColumn::query()->create(['organization_id' => $this->tenant['org']->id, 'board_id' => $this->board->id,
                'name' => 'Description', 'type' => 'text', 'settings' => null, 'position' => 1]);
            $lead = Lead::query()->create([
                'company_id' => $this->tenant['company']->id, 'source' => 'manual', 'remote_id' => 'cleanup-1', 'lead_type' => 'PHONE_CALL',
                'status' => Lead::STATUS_NEW, 'contact_name' => 'Linda Lead', 'phone' => '+15550001111', 'received_at' => now(),
            ]);
            $w = app(TaskWriter::class);
            $about = ['about_type' => 'lead', 'about_id' => $lead->id];
            $make = fn (string $title, array $extra = []) => $w->create($this->board, ['title' => $title, 'group_id' => $group->id] + $extra, null)->id;
            $value = fn (string $task, string $text) => TaskColumnValue::query()->create([
                'organization_id' => $this->tenant['org']->id, 'task_id' => $task, 'column_id' => $desc->id, 'value_text' => $text]);

            $this->travelTo(now()->subHour());
            $oldest = $make('Leak came back', $about);
            $value($oldest, 'complaint');
            $this->travel(4)->seconds();
            $dupe = $make('Leak came back', $about);
            $value($dupe, 'Complaint');
            $this->travel(20)->minutes();
            $later = $make('Leak came back', $about); // outside the window: kept
            $value($later, 'Sad review');
            $review = $make('Wants pricing');
            $value($review, 'bad review');
            $odd = $make('Something else');
            $value($odd, 'angry');
            $owned = $make('Owned', ['assignee_user_id' => $this->jonathan->id]);
            $outside = $w->create($this->board, ['title' => 'Not CX'], null)->id;
            $unparsed = new Task;
            $unparsed->forceFill(['id' => TasksCleanup202611::UNPARSED, 'organization_id' => $this->tenant['org']->id,
                'board_id' => $this->board->id, 'group_id' => $group->id, 'title' => 'Complaint:   - Wants a price', 'status' => 'not_started', 'position' => 50])->save();
            $this->travelBack();

            return compact('group', 'desc', 'oldest', 'dupe', 'later', 'review', 'odd', 'owned', 'outside') + ['unparsed' => $unparsed->id];
        });
    }

    private function run202611(array $args = [])
    {
        return $this->artisan('ezbizi:tasks-cleanup-2026-11', $args + ['--also-assign' => $this->jonathan->id]);
    }

    private function state(): array
    {
        return $this->inTenant($this->tenant['org'], fn () => [
            TaskColumn::query()->orderBy('id')->get(['id', 'type', 'settings'])->toArray(),
            TaskGroup::query()->orderBy('id')->pluck('colour', 'id')->all(),
            TaskColumnValue::query()->orderBy('id')->pluck('value_text', 'id')->all(),
            Task::query()->orderBy('id')->pluck('assignee_user_id', 'id')->all(),
            TaskAssignee::query()->count(),
        ]);
    }

    public function test_each_step_and_a_second_run_changes_nothing(): void
    {
        $i = $this->ids;
        $this->run202611()
            ->expectsOutputToContain('boards normalised: 1')
            ->expectsOutputToContain('values mapped: 3') // Complaint, Sad review, bad review; "complaint" is already the id
            ->expectsOutputToContain('client names filled: 3')
            ->expectsOutputToContain('values not mapped: 1 '.$i['odd'])
            ->expectsOutputToContain('duplicates deleted: 1 '.$i['dupe'])
            ->expectsOutputToContain('unparsed deleted: 1 '.TasksCleanup202611::UNPARSED)
            ->expectsOutputToContain('tasks assigned: 4')
            ->assertSuccessful();

        $this->inTenant($this->tenant['org'], function () use ($i) {
            $desc = TaskColumn::query()->find($i['desc']->id); // same id, converted in place
            $this->assertSame('status', $desc->type);
            $this->assertSame(['complaint', 'bad_review'], array_column($desc->options(), 'id'));
            $v = fn (string $task) => TaskColumnValue::query()->where('task_id', $task)->where('column_id', $desc->id)->value('value_text');
            $this->assertSame('complaint', $v($i['oldest']));
            $this->assertSame('bad_review', $v($i['later']));
            $this->assertSame('bad_review', $v($i['review']));
            $this->assertSame('angry', $v($i['odd']));

            $name = TaskColumn::query()->where('board_id', $this->board->id)->where('name', 'Client name')->first();
            $this->assertSame('text', $name->type);
            $this->assertSame('Linda Lead', TaskColumnValue::query()->where('task_id', $i['oldest'])->where('column_id', $name->id)->value('value_text'));
            $this->assertSame('#E5484D', TaskGroup::query()->find($i['group']->id)->colour);

            $this->assertTrue(Task::withTrashed()->find($i['dupe'])->trashed());
            $this->assertTrue(Task::withTrashed()->find(TasksCleanup202611::UNPARSED)->trashed());
            $this->assertFalse(Task::query()->find($i['later'])->trashed());

            $owners = fn (string $t) => TaskAssignee::query()->where('task_id', $t)->orderBy('position')->pluck('user_id')->all();
            foreach (['oldest', 'later', 'review', 'odd'] as $k) {
                $this->assertSame([TasksCleanup202611::ADELINE, $this->jonathan->id], $owners($i[$k]), $k);
            }
            $this->assertSame([$this->jonathan->id], $owners($i['owned']));
            $this->assertNull(Task::query()->find($i['outside'])->assignee_user_id);
        });

        $before = $this->state();
        $this->run202611()
            ->expectsOutputToContain('boards normalised: 0')
            ->expectsOutputToContain('values mapped: 0')
            ->expectsOutputToContain('client names filled: 0')
            ->expectsOutputToContain('duplicates deleted: 0')
            ->expectsOutputToContain('unparsed deleted: 0')
            ->expectsOutputToContain('tasks assigned: 0')
            ->assertSuccessful();
        $this->assertSame($before, $this->state());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $before = $this->state();
        $this->run202611(['--dry-run' => true])
            ->expectsOutputToContain('[dry run, nothing saved]')
            ->expectsOutputToContain('boards normalised: 1')
            ->expectsOutputToContain('duplicates deleted: 1')
            ->expectsOutputToContain('tasks assigned: 4')
            ->assertSuccessful();
        $this->assertSame($before, $this->state());
    }

    public function test_without_also_assign_nobody_is_assigned(): void
    {
        $this->artisan('ezbizi:tasks-cleanup-2026-11')
            ->expectsOutputToContain('tasks assigned: skipped')
            ->assertSuccessful();
        $this->inTenant($this->tenant['org'], fn () => $this->assertNull(Task::query()->find($this->ids['oldest'])->assignee_user_id));
    }
}
