<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Automations\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The board-scoping contract between Task Board and Automations (inbox 0120):
 * a rule saved through the real API with `board_id eq <A>` runs for tasks on
 * board A and for nothing on board B.
 */
class TaskBoardAutomationContractTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_board_counts_the_task_rules_conditioned_on_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        [$a, $b] = $this->inTenant($tenant['org'], fn () => [
            TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first(),
            TaskBoard::query()->where('company_id', $tenant['company']->id)->where('kind', 'company')->first(),
        ]);

        $this->signedInAs($admin)->postJson('/api/v1/automations', [
            'name' => 'Board A rule',
            'trigger_key' => 'task.status_changed',
            'conditions' => ['all' => [['field' => 'board_id', 'op' => 'eq', 'value' => $a->id]]],
            'actions' => [['type' => 'task.assign', 'user_field' => 'user:'.$admin->id]],
        ])->assertCreated();

        $this->signedInAs($admin)->getJson("/api/v1/task-boards/{$a->id}")->assertOk()->assertJsonPath('data.automations_count', 1);
        $this->signedInAs($admin)->getJson("/api/v1/task-boards/{$b->id}")->assertOk()->assertJsonPath('data.automations_count', 0);
    }

    public function test_a_rule_conditioned_on_board_id_runs_for_that_board_only(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        [$a, $b] = $this->inTenant($tenant['org'], fn () => [
            TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first(),
            TaskBoard::query()->where('company_id', $tenant['company']->id)->where('kind', 'company')->first(),
        ]);

        $ruleId = $this->signedInAs($admin)->postJson('/api/v1/automations', [
            'name' => 'Board A: give it to the admin',
            'trigger_key' => 'task.status_changed',
            'conditions' => ['all' => [['field' => 'board_id', 'op' => 'eq', 'value' => $a->id]]],
            'actions' => [['type' => 'task.assign', 'user_field' => 'user:'.$admin->id]],
        ])->assertCreated()->json('data.id');
        $this->signedInAs($admin)->patchJson("/api/v1/automations/{$ruleId}", ['is_active' => true])->assertOk();

        $onA = $this->signedInAs($admin)->postJson('/api/v1/tasks', ['board_id' => $a->id, 'title' => 'On A'])->assertCreated()->json('data.id');
        $onB = $this->signedInAs($admin)->postJson('/api/v1/tasks', ['board_id' => $b->id, 'title' => 'On B'])->assertCreated()->json('data.id');
        $this->signedInAs($admin)->patchJson("/api/v1/tasks/{$onA}", ['status' => 'stuck'])->assertOk();
        $this->signedInAs($admin)->patchJson("/api/v1/tasks/{$onB}", ['status' => 'stuck'])->assertOk();

        $engine = app(AutomationEngine::class);
        $this->inTenant($tenant['org'], function () use ($engine, $a, $b, $onA, $onB, $admin, $ruleId) {
            // The payload names the board, so a rule (or a person) can read it.
            $events = AutomationEvent::query()->where('event_key', 'task.status_changed')->get()->keyBy('subject_id');
            $this->assertSame([$a->id, $a->name], [$events[$onA]->payload['board_id'], $events[$onA]->payload['board_name']]);
            $this->assertSame([$b->id, $b->name], [$events[$onB]->payload['board_id'], $events[$onB]->payload['board_name']]);

            AutomationEvent::query()->whereNull('delivered_at')->orderBy('occurred_at')->get()
                ->each(fn (AutomationEvent $e) => $engine->deliver($e));

            $this->assertSame($admin->id, Task::find($onA)->assignee_user_id);
            $this->assertNull(Task::find($onB)->assignee_user_id);

            $ran = AutomationRun::query()->where('automation_id', $ruleId)
                ->where('status', AutomationRun::SUCCEEDED)->pluck('event_id')->all();
            $this->assertSame([$events[$onA]->id], $ran);
        });
    }
}
