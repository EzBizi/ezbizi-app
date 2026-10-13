<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Task Board steps (2026-10-13): a rule adds a task, gives it to somebody and
 * moves its status, written as the rule's creator through TaskWriter, with no
 * request or top-bar view behind the run.
 */
class TaskStepsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_rule_adds_assigns_and_moves_a_task_and_a_viewer_rule_is_refused(): void
    {
        $t = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($t, 'Viewer', ['tasks.view']);

        $this->inTenant($t['org'], function () use ($t, $viewer) {
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'task.created', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $runner = app(ActionRunner::class);
            $event = new AutomationEvent(['event_key' => 'job.created', 'payload' => [],
                'scope_snapshot' => ['type' => 'franchise', 'id' => $t['franchise']->id, 'company_chain' => []]]);

            $made = $runner->run(['type' => 'task.create', 'title' => 'Call back {{ missing }}'], $event, null, automation: $rule);
            $this->assertTrue($made['ok'], $made['detail']);
            $task = Task::query()->sole();
            $this->assertSame(TaskBoard::query()->where('franchise_id', $t['franchise']->id)->value('id'), $task->board_id);
            $this->assertSame($rule->id, $task->created_by_automation_id);

            $given = $runner->run(['type' => 'task.assign', 'user_field' => 'user:'.$viewer->id], $event, $task, automation: $rule);
            $this->assertTrue($given['ok'], $given['detail']);
            $this->assertSame($viewer->id, $task->fresh()->assignee_user_id);

            $moved = $runner->run(['type' => 'change_status', 'value' => 'done'], $event, $task->fresh(), automation: $rule);
            $this->assertTrue($moved['ok'], $moved['detail']);
            $this->assertSame('done', $task->fresh()->status);

            // A rule may do nothing its creator could not: a viewer cannot edit
            // a franchise board by hand, so their rule is refused, not obeyed.
            $theirs = Automation::create(['name' => 'V', 'trigger_key' => 'task.created', 'is_active' => false, 'created_by' => $viewer->id]);
            $refused = $runner->run(['type' => 'change_status', 'value' => 'stuck'], $event, $task->fresh(), automation: $theirs);
            $this->assertFalse($refused['ok']);
            $this->assertSame('done', $task->fresh()->status);
        });
    }

    public function test_a_notification_can_go_to_whoever_the_task_is_given_to(): void
    {
        $t = $this->provisionTenant('Alpha');
        $worker = $this->userWithRole($t, 'Viewer', ['tasks.view']);

        $this->inTenant($t['org'], function () use ($t, $worker) {
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'task.created', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $runner = app(ActionRunner::class);
            $step = ['type' => 'notify', 'message' => 'A task for you', 'to_assignee' => true];

            $given = new AutomationEvent(['event_key' => 'task.created', 'payload' => ['assignee_user_id' => $worker->id, 'after' => []]]);
            $told = $runner->run($step, $given, null, automation: $rule);
            $this->assertTrue($told['ok'], $told['detail']);
            $this->assertSame([$worker->id], Notification::query()->pluck('user_id')->unique()->values()->all());

            // Nobody assigned and nobody else named is skipped, never "tell everybody".
            $nobody = $runner->run($step, new AutomationEvent(['event_key' => 'task.created', 'payload' => ['after' => []]]), null, automation: $rule);
            $this->assertTrue($nobody['skipped'] ?? false);
            $this->assertSame(1, Notification::query()->count());
        });
    }
}
