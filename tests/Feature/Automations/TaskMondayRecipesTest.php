<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Support\Automations\ActionRunner;
use App\Support\Automations\AutomationEngine;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Monday recipes the Task Board lacked (parity audit 2026-11-13,
 * docs/agents/monday-parity-audit-2026-11-13.md): "when Area changes to
 * Connecticut, add Nehorai", "when Date arrives (Recurring Tasks), notify
 * and push Date by 1 month", "set Reminder to Done / Date to today".
 */
class TaskMondayRecipesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private TaskBoard $board;

    private TaskColumn $area;

    private TaskColumn $date;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->t = $this->provisionTenant('Alpha');
        $this->inTenant($this->t['org'], function () {
            $this->board = TaskBoard::query()->where('franchise_id', $this->t['franchise']->id)->firstOrFail();
            $this->area = TaskColumn::create(['organization_id' => $this->t['org']->id, 'board_id' => $this->board->id, 'name' => 'Area',
                'type' => 'status', 'position' => 1, 'settings' => ['options' => [
                    ['id' => 'ct', 'label' => 'Connecticut', 'colour' => '#ff642e'], ['id' => 'oh', 'label' => 'Ohio', 'colour' => '#00c875'],
                ]]]);
            $this->date = TaskColumn::create(['organization_id' => $this->t['org']->id, 'board_id' => $this->board->id, 'name' => 'Date',
                'type' => 'date', 'position' => 2]);
        });
    }

    private function task(array $input = []): Task
    {
        return $this->inTenant($this->t['org'], fn () => app(TaskWriter::class)->create($this->board, ['title' => 'Oil change'] + $input, $this->t['admin']));
    }

    public function test_a_column_change_is_an_event_a_rule_can_match_by_column_and_new_value(): void
    {
        $task = $this->task(['values' => [$this->area->id => 'oh']]);
        $this->inTenant($this->t['org'], function () use ($task) {
            $this->assertSame(0, AutomationEvent::query()->where('event_key', 'task.column_changed')->count(), 'a new task\'s values are not a change');

            app(TaskWriter::class)->update($task, ['values' => [$this->area->id => 'ct']], $this->t['admin']);
            $event = AutomationEvent::query()->where('event_key', 'task.column_changed')->sole();
            $this->assertSame('Ohio', $event->payload['before']['value']);
            $this->assertSame(['Area', 'Connecticut', $this->area->id], [$event->payload['after']['column'], $event->payload['after']['value'], $event->payload['after']['column_id']]);
            $this->assertSame($task->group_id, $event->payload['after']['group_id']);

            $engine = app(AutomationEngine::class);
            $rule = fn (string $to) => ['all' => [
                ['field' => 'board_id', 'op' => 'eq', 'value' => $this->board->id],
                ['field' => 'column_id', 'op' => 'eq', 'value' => $this->area->id],
                ['field' => 'value', 'op' => 'changed_to', 'value' => $to],
            ]];
            $this->assertTrue($engine->wouldMatch($rule('Connecticut'), $event));
            $this->assertFalse($engine->wouldMatch($rule('Ohio'), $event));

            // Writing the same value again is no change.
            app(TaskWriter::class)->update($task->fresh(), ['values' => [$this->area->id => 'ct']], $this->t['admin']);
            $this->assertSame(1, AutomationEvent::query()->where('event_key', 'task.column_changed')->count());
        });
    }

    public function test_a_date_arriving_today_or_tomorrow_is_raised_once_per_date(): void
    {
        $today = $this->task(['due_on' => '2026-11-13']);
        $tomorrow = $this->task(['values' => [$this->date->id => '2026-11-14']]);
        $this->task(['due_on' => '2026-11-20']);

        Artisan::call('ezbizi:tasks-dates-arrived');
        Artisan::call('ezbizi:tasks-dates-arrived');

        $this->inTenant($this->t['org'], function () use ($today, $tomorrow) {
            $events = AutomationEvent::query()->where('event_key', 'task.date_arrived')->get()->keyBy('subject_id');
            $this->assertCount(2, $events);
            $this->assertSame(['due', 'Due date', 0], [$events[$today->id]->payload['after']['column_id'], $events[$today->id]->payload['after']['column'], $events[$today->id]->payload['after']['days_until']]);
            $this->assertSame([$this->date->id, 'Date', 1], [$events[$tomorrow->id]->payload['after']['column_id'], $events[$tomorrow->id]->payload['after']['column'], $events[$tomorrow->id]->payload['after']['days_until']]);

            // Moved to another day, it arrives again.
            app(TaskWriter::class)->update($today->fresh(), ['due_on' => '2026-11-14'], $this->t['admin']);
        });
        Artisan::call('ezbizi:tasks-dates-arrived');
        $this->inTenant($this->t['org'], fn () => $this->assertSame(3, AutomationEvent::query()->where('event_key', 'task.date_arrived')->count()));
    }

    public function test_a_rule_pushes_a_date_sets_a_column_and_adds_an_assignee(): void
    {
        $second = $this->userWithRole($this->t, 'Manager', ['tasks.view', 'tasks.edit']);
        $task = $this->task(['due_on' => '2026-01-31', 'values' => [$this->date->id => '2026-11-13'], 'assignee_user_ids' => [$this->t['admin']->id]]);

        $this->inTenant($this->t['org'], function () use ($task, $second) {
            $rule = Automation::create(['name' => 'Recurring', 'trigger_key' => 'task.date_arrived', 'is_active' => false, 'created_by' => $this->t['admin']->id]);
            $runner = app(ActionRunner::class);
            $event = new AutomationEvent(['event_key' => 'task.date_arrived', 'payload' => [],
                'scope_snapshot' => ['type' => 'franchise', 'id' => $this->t['franchise']->id, 'company_chain' => []]]);
            $value = fn () => TaskColumnValue::query()->where('task_id', $task->id)->where('column_id', $this->date->id)->value('value_date')?->toDateString();

            $pushed = $runner->run(['type' => 'task.shift_date', 'column_id' => $this->date->id, 'amount' => 1, 'unit' => 'months'], $event, $task->fresh(), automation: $rule);
            $this->assertTrue($pushed['ok'], $pushed['detail']);
            $this->assertSame('2026-12-13', $value());
            $due = $runner->run(['type' => 'task.shift_date', 'column_id' => 'due', 'amount' => 1, 'unit' => 'months'], $event, $task->fresh(), automation: $rule);
            $this->assertTrue($due['ok'], $due['detail']);
            $this->assertSame('2026-02-28', $task->fresh()->due_on->toDateString(), 'Jan 31 + 1 month does not spill into March');

            $set = $runner->run(['type' => 'task.set_value', 'column_id' => $this->area->id, 'value' => 'connecticut'], $event, $task->fresh(), automation: $rule);
            $this->assertTrue($set['ok'], $set['detail']);
            $this->assertSame('ct', TaskColumnValue::query()->where('task_id', $task->id)->where('column_id', $this->area->id)->value('value_text'));
            $todayDate = $runner->run(['type' => 'task.set_value', 'column_id' => $this->date->id, 'value' => 'today'], $event, $task->fresh(), automation: $rule);
            $this->assertTrue($todayDate['ok'], $todayDate['detail']);
            $this->assertSame('2026-11-13', $value());
            $unknown = $runner->run(['type' => 'task.set_value', 'column_id' => $this->area->id, 'value' => 'Texas'], $event, $task->fresh(), automation: $rule);
            $this->assertFalse($unknown['ok']);

            $added = $runner->run(['type' => 'task.assign', 'user_field' => 'user:'.$second->id, 'add' => true], $event, $task->fresh(), automation: $rule);
            $this->assertTrue($added['ok'], $added['detail']);
            $this->assertSame([$this->t['admin']->id, $second->id], $task->fresh()->owners()->pluck('user_id')->all(), 'Monday\'s "Add assignee" keeps whoever had it');
        });
    }
}
