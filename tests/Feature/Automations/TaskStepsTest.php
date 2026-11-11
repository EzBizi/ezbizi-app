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

            // A board's own status, by name in any case; one it lacks is refused.
            \App\Models\TaskStatus::create(['organization_id' => $t['org']->id, 'board_id' => $task->board_id,
                'name' => 'Waiting on parts', 'position' => 9, 'is_done' => false]);
            $custom = $runner->run(['type' => 'change_status', 'value' => 'waiting ON parts'], $event, $task->fresh(), automation: $rule);
            $this->assertTrue($custom['ok'], $custom['detail']);
            $unknown = $runner->run(['type' => 'change_status', 'value' => 'Invented'], $event, $task->fresh(), automation: $rule);
            $this->assertFalse($unknown['ok']);
            $this->assertStringContainsString('no status called Invented', $unknown['detail']);

            // A rule may do nothing its creator could not: a viewer cannot edit
            // a franchise board by hand, so their rule is refused, not obeyed.
            $theirs = Automation::create(['name' => 'V', 'trigger_key' => 'task.created', 'is_active' => false, 'created_by' => $viewer->id]);
            $before = $task->fresh()->status_id;
            $refused = $runner->run(['type' => 'change_status', 'value' => 'stuck'], $event, $task->fresh(), automation: $theirs);
            $this->assertFalse($refused['ok']);
            $this->assertSame($before, $task->fresh()->status_id, 'a refused rule changes nothing');
        });
    }

    /** Jose, 2026-10-22: the card filled from the job, owned by EzBizi, the technician tagged in a column. */
    public function test_a_job_card_carries_the_jobs_client_and_technician_and_no_owner(): void
    {
        $t = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($t, 'Tech', ['tasks.view']);

        $this->inTenant($t['org'], function () use ($t, $tech) {
            $customer = \App\Models\Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-1',
                'name' => 'Ann Client', 'phone' => '+13055550123', 'address_line1' => '1 Main St', 'city' => 'Columbus']);
            $job = \App\Models\Job::create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-7',
                'title' => 'Chimney Sweep', 'status' => 'scheduled', 'currency' => 'USD', 'performed_by_user_id' => $tech->id]);
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'job.payment_received', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $event = new AutomationEvent(['event_key' => 'job.payment_received', 'payload' => [],
                'scope_snapshot' => ['type' => 'franchise', 'id' => $t['franchise']->id, 'company_chain' => []]]);

            $made = app(ActionRunner::class)->run(['type' => 'task.create', 'title' => 'Paid: {{number}}', 'group' => 'Project Management',
                'values' => ['Client name' => '{{customer_name}}', 'Phone number' => '{{customer_phone}}',
                    'Description' => '{{title}} · {{customer_address}}', 'Technician' => '{{technician_user_id}}', 'Empty' => '{{nothing}}']],
                $event, $job, automation: $rule);

            $this->assertTrue($made['ok'], $made['detail']);
            $task = Task::query()->sole();
            $this->assertSame([], $task->owners()->pluck('user_id')->all(), 'EzBizi made it; no person owns it');
            $cols = \App\Models\TaskColumn::query()->where('board_id', $task->board_id)->pluck('type', 'name');
            $this->assertSame('person', $cols['Technician']);
            $this->assertArrayNotHasKey('Empty', $cols->all(), 'a blank value makes no column');
            $value = fn (string $name) => $task->values()->where('column_id', \App\Models\TaskColumn::query()
                ->where('board_id', $task->board_id)->where('name', $name)->value('id'))->first();
            $this->assertSame('Ann Client', $value('Client name')->value_text);
            $this->assertSame('+13055550123', $value('Phone number')->value_text);
            $this->assertSame('Chimney Sweep · 1 Main St, Columbus', $value('Description')->value_text);
            $this->assertSame($tech->id, $value('Technician')->value_user_id);
        });
    }

    public function test_a_rule_moves_a_task_to_a_group_on_its_own_board_only(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'task.status_changed', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $runner = app(ActionRunner::class);
            $event = new AutomationEvent(['event_key' => 'job.created', 'payload' => [],
                'scope_snapshot' => ['type' => 'franchise', 'id' => $t['franchise']->id, 'company_chain' => []]]);
            $runner->run(['type' => 'task.create', 'title' => 'T'], $event, null, automation: $rule);
            $task = Task::query()->sole();
            $finished = \App\Models\TaskGroup::create(['organization_id' => $t['org']->id, 'board_id' => $task->board_id, 'name' => 'Finished', 'position' => 9]);
            $elsewhere = \App\Models\TaskGroup::create(['organization_id' => $t['org']->id,
                'board_id' => TaskBoard::create(['organization_id' => $t['org']->id, 'kind' => 'personal', 'owner_user_id' => $t['admin']->id, 'name' => 'Mine'])->id,
                'name' => 'Other', 'position' => 9]);

            $moved = $runner->run(['type' => 'task.move_group', 'group_id' => $finished->id], $event, $task, automation: $rule);
            $this->assertTrue($moved['ok'], $moved['detail']);
            $this->assertSame($finished->id, $task->fresh()->group_id);

            $again = $runner->run(['type' => 'task.move_group', 'group_id' => $finished->id], $event, $task->fresh(), automation: $rule);
            $this->assertTrue($again['skipped'] ?? false, 'already there is a skip, not a failure');

            $refused = $runner->run(['type' => 'task.move_group', 'group_id' => $elsewhere->id], $event, $task->fresh(), automation: $rule);
            $this->assertFalse($refused['ok'], 'a group on another board is refused');
            $this->assertSame($finished->id, $task->fresh()->group_id);
        });
    }

    /** Jose, 2026-10-20: one complaint task with both owners, in Customer Experience, its columns filled. */
    public function test_one_task_has_every_owner_its_group_and_its_columns(): void
    {
        $t = $this->provisionTenant('Alpha');
        $second = $this->userWithRole($t, 'Viewer', ['tasks.view']);

        $this->inTenant($t['org'], function () use ($t, $second) {
            $board = TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first();
            $group = \App\Models\TaskGroup::query()->create(['board_id' => $board->id, 'name' => 'Customer Experience', 'position' => 9]);
            $col = \App\Models\TaskColumn::query()->create(['board_id' => $board->id, 'name' => 'Phone number', 'type' => 'text', 'position' => 1]);
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'lead.received', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $event = new AutomationEvent(['event_key' => 'lead.received', 'payload' => ['after' => ['phone' => '+13055550123']],
                'scope_snapshot' => ['type' => 'franchise', 'id' => $t['franchise']->id, 'company_chain' => []]]);

            $made = app(ActionRunner::class)->run(['type' => 'task.create', 'title' => 'Complaint',
                'assignees' => ['user:'.$t['admin']->id, 'user:'.$second->id], 'group' => 'Customer Experience',
                'values' => ['Phone number' => '{{phone}}']], $event, null, automation: $rule);

            $this->assertTrue($made['ok'], $made['detail']);
            $task = Task::query()->sole();
            $this->assertSame([$t['admin']->id, $second->id], $task->owners()->pluck('user_id')->all());
            $this->assertSame($group->id, $task->group_id);
            $this->assertSame('+13055550123', $task->values()->where('column_id', $col->id)->value('value_text'));

            // A group the board lacks is made there, once (PM Action 1, 2026-10-22).
            foreach ([1, 2] as $_) {
                $again = app(ActionRunner::class)->run(['type' => 'task.create', 'title' => 'Paid', 'assignee' => 'user:'.$t['admin']->id,
                    'group' => 'Project Management'], $event, null, automation: $rule);
                $this->assertTrue($again['ok'], $again['detail']);
            }
            $made = $board->groups()->where('name', 'Project Management')->sole();
            $this->assertSame(2, Task::query()->where('group_id', $made->id)->count());
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

    /** Bar's edit, 2026-10-13: named people saved as "a,b" told neither of them. */
    public function test_named_people_saved_as_one_joined_string_are_all_told(): void
    {
        $t = $this->provisionTenant('Alpha');
        $one = $this->userWithRole($t, 'Viewer', ['tasks.view']);
        $two = $this->userWithRole($t, 'Clerk', ['tasks.view']);

        $this->inTenant($t['org'], function () use ($t, $one, $two) {
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'task.created', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $told = app(ActionRunner::class)->run(
                ['type' => 'notify', 'message' => 'Hi', 'user_ids' => $one->id.','.$two->id],
                new AutomationEvent(['event_key' => 'task.created', 'payload' => []]), null, automation: $rule,
            );

            $this->assertTrue($told['ok'], $told['detail']);
            $this->assertEqualsCanonicalizing([$one->id, $two->id], Notification::query()->pluck('user_id')->unique()->values()->all());
        });
    }

    /**
     * Jose, 2026-10-13: complaint tasks for Adeline and Jonathan. A personal
     * board is its owner's alone, so the task goes on the place's board and
     * is given to them, rather than being refused on theirs.
     */
    public function test_a_task_given_to_somebody_else_lands_on_the_places_board(): void
    {
        $t = $this->provisionTenant('Alpha');
        $dispatcher = $this->userWithRole($t, 'Viewer', ['tasks.view']);

        $this->inTenant($t['org'], function () use ($t, $dispatcher) {
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'lead.call_complaint', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $event = new AutomationEvent(['event_key' => 'lead.call_complaint', 'payload' => [],
                'scope_snapshot' => ['type' => 'company', 'id' => $t['company']->id, 'company_chain' => [$t['company']->id]]]);

            $made = app(ActionRunner::class)->run(['type' => 'task.create', 'title' => 'Complaint', 'assignee' => 'user:'.$dispatcher->id], $event, null, automation: $rule);

            $this->assertTrue($made['ok'], $made['detail']);
            $task = Task::query()->sole();
            $this->assertSame($dispatcher->id, $task->assignee_user_id);
            $this->assertSame(TaskBoard::query()->where('kind', 'company')->where('company_id', $t['company']->id)->value('id'), $task->board_id);
        });
    }

    public function test_a_sub_companys_task_lands_on_its_parents_board_with_every_owner(): void
    {
        $t = $this->provisionTenant('Alpha');
        $second = $this->userWithRole($t, 'Viewer', ['tasks.view']);

        $this->inTenant($t['org'], function () use ($t, $second) {
            $sub = \App\Models\Company::query()->create(['name' => 'Sub', 'parent_company_id' => $t['company']->id]);
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'lead.call_complaint', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $event = new AutomationEvent(['event_key' => 'lead.call_complaint', 'payload' => [],
                'scope_snapshot' => ['type' => 'company', 'id' => $sub->id, 'company_chain' => [$sub->id, $t['company']->id]]]);

            // Was the first owner's personal board, which refused the second (Complaint call, 11-10).
            $made = app(ActionRunner::class)->run(['type' => 'task.create', 'title' => 'Complaint',
                'assignees' => ['user:'.$second->id, 'user:'.$t['admin']->id]], $event, null, automation: $rule);

            $this->assertTrue($made['ok'], $made['detail']);
            $task = Task::query()->sole();
            $this->assertSame(TaskBoard::query()->where('kind', 'company')->where('company_id', $t['company']->id)->value('id'), $task->board_id);
            $this->assertSame([$second->id, $t['admin']->id], $task->owners()->pluck('user_id')->all());
        });
    }

    public function test_one_rule_adds_one_task_per_record_in_ten_minutes(): void
    {
        // Review 2026-11-02: one call read as two transcripts made two
        // identical complaint tasks 3 seconds apart; one payment made two.
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $customer = \App\Models\Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-1', 'name' => 'Ann Client']);
            $job = \App\Models\Job::create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-7',
                'title' => 'Chimney Sweep', 'status' => 'scheduled', 'currency' => 'USD']);
            $other = \App\Models\Job::create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-8',
                'title' => 'Chimney Sweep', 'status' => 'scheduled', 'currency' => 'USD']);
            $rule = Automation::create(['name' => 'R', 'trigger_key' => 'job.payment_received', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $second = Automation::create(['name' => 'S', 'trigger_key' => 'job.payment_received', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $event = new AutomationEvent(['event_key' => 'job.payment_received', 'payload' => [],
                'scope_snapshot' => ['type' => 'franchise', 'id' => $t['franchise']->id, 'company_chain' => []]]);
            $run = fn ($job, $rule) => app(ActionRunner::class)->run(['type' => 'task.create', 'title' => 'Paid: {{number}}'], $event, $job, automation: $rule);

            $this->assertTrue($run($job, $rule)['ok']);
            $again = $run($job, $rule);
            $this->assertTrue($again['ok']);
            $this->assertTrue($again['skipped'] ?? false, 'the same rule, the same job, seconds later');
            $this->assertArrayNotHasKey('skipped', $run($other, $rule), 'another job is its own task');
            $this->assertArrayNotHasKey('skipped', $run($job, $second), 'another rule is its own task');
            $this->assertSame(3, Task::query()->count());

            $this->travel(11)->minutes();
            $this->assertArrayNotHasKey('skipped', $run($job, $rule), 'after the window, a new event is a new task');
            $this->assertSame(4, Task::query()->count());
        });
    }
}
