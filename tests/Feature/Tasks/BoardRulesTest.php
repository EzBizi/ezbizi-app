<?php

namespace Tests\Feature\Tasks;

use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Management's board rules (Jose approved 2026-11-17). */
class BoardRulesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function branch(array $t): array
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $board = app(TaskBoards::class)->createCustom($t['admin'], 'NJ - New Jersey', TaskBoard::MAIN, null, $t['franchise']);
            $g = [];
            foreach (['Field Manager - Lucas', 'Customer Relations - Natalie', 'Accounting - Esti', 'General Manager - Meni'] as $i => $name) {
                $g[$name] = TaskGroup::create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'name' => $name, 'position' => 10 + $i]);
            }
            TaskStatus::create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'name' => 'Approved', 'is_done' => false, 'position' => 9]);

            return [$board, $g];
        });
    }

    public function test_misfiled_items_move_within_the_board_and_unclear_ones_are_flagged(): void
    {
        $t = $this->provisionTenant('Alpha');
        $staff = $this->userWithRole($t, 'Dispatcher', ['tasks.view', 'tasks.edit']);
        [$board, $g] = $this->branch($t);

        $this->inTenant($t['org'], function () use ($board, $g, $staff) {
            $w = app(TaskWriter::class);
            $group = fn (Task $task) => $task->fresh()->group->name;

            $refund = $w->create($board, ['title' => 'Refund for Smith', 'group_id' => $g['Field Manager - Lucas']->id], $staff);
            $this->assertSame('Customer Relations - Natalie', $group($refund));
            $this->assertSame('Moved to Customer Relations: refunds start there', Notification::query()->where('user_id', $staff->id)->value('title'));

            // Approved in Customer Relations: on to Accounting.
            $w->update($refund->fresh(), ['status' => 'Approved'], $staff);
            $this->assertSame('Accounting - Esti', $group($refund));

            $dispute = $w->create($board, ['title' => 'Chargeback evidence for Ann', 'group_id' => $g['Customer Relations - Natalie']->id], $staff);
            $this->assertSame('General Manager - Meni', $group($dispute));

            // No Project Manager on this board, and Accounting holding a non-refund: flagged, not moved.
            $deposit = $w->create($board, ['title' => 'Deposit job 4411', 'group_id' => $g['Field Manager - Lucas']->id], $staff);
            $this->assertSame('Field Manager - Lucas', $group($deposit));
            $odd = $w->create($board, ['title' => 'Call the bank', 'group_id' => $g['Accounting - Esti']->id], $staff);
            $this->assertSame('Accounting - Esti', $group($odd));
            // Nothing to say about an ordinary Field Manager item.
            $plain = $w->create($board, ['title' => 'Reschedule ETA', 'group_id' => $g['Field Manager - Lucas']->id], $staff);
            $this->assertSame('Field Manager - Lucas', $group($plain));

            // Made by EzBizi itself (no person, no rule): counted as EzBizi, never as a person (desk 11-18).
            $w->create($board, ['title' => 'Refund for Lee', 'group_id' => $g['Field Manager - Lucas']->id], null);

            $this->assertSame(4, AuditEvent::query()->where('action', 'task.board_rule.moved')->count());
            $this->assertSame(2, AuditEvent::query()->where('action', 'task.board_rule.flagged')->count());
        });

        // One line per organization: the counts, then who made them.
        $this->artisan('ezbizi:tasks-misfiled-report', ['--dry-run' => true])
            ->expectsOutputToContain("4 moved, 2 flagged. {$staff->name} 5, EzBizi (automation) 1")
            ->assertSuccessful();
    }

    /** Desk 11-18: a complaint rule's task stays in Customer Relations; a person's Approved still moves it on. */
    public function test_a_complaint_rules_task_stays_in_customer_relations_until_a_person_approves_it(): void
    {
        $t = $this->provisionTenant('Alpha');
        $staff = $this->userWithRole($t, 'Dispatcher', ['tasks.view', 'tasks.edit']);
        [$board, $g] = $this->branch($t);
        $rule = $this->inTenant($t['org'], fn () => Automation::query()->create(['organization_id' => $t['org']->id, 'name' => 'Complaint call', 'trigger_key' => 'call.transcribed', 'is_active' => true]));

        $this->inTenant($t['org'], function () use ($board, $g, $staff, $rule) {
            $w = app(TaskWriter::class);
            $c = $w->create($board, ['title' => 'Complaint: wants a refund, mentions a chargeback claim', 'group_id' => $g['Customer Relations - Natalie']->id], null, $rule->id);
            $this->assertSame('Customer Relations - Natalie', $c->fresh()->group->name, 'not moved to the GM board');
            $this->assertSame(0, AuditEvent::query()->where('target_id', $c->id)->where('action', 'like', 'task.board_rule.%')->count(), 'nor flagged');

            $w->update($c->fresh(), ['status' => 'Approved'], $staff);
            $this->assertNotSame('Customer Relations - Natalie', $c->fresh()->group->name, "a person's Approved moves it on");

            // A real chargeback a person files still goes to the General Manager.
            $p = $w->create($board, ['title' => 'Chargeback evidence for Ann', 'group_id' => $g['Customer Relations - Natalie']->id], $staff);
            $this->assertSame('General Manager - Meni', $p->fresh()->group->name);
        });
    }

    public function test_with_moves_off_a_clear_case_is_only_flagged(): void
    {
        config(['ezbizi.task_board_moves' => false]);
        $t = $this->provisionTenant('Alpha');
        [$board, $g] = $this->branch($t);

        $this->inTenant($t['org'], function () use ($t, $board, $g) {
            $task = app(TaskWriter::class)->create($board, ['title' => 'Refund please', 'group_id' => $g['Field Manager - Lucas']->id], $t['admin']);
            $this->assertSame('Field Manager - Lucas', $task->fresh()->group->name);
            $this->assertSame(1, AuditEvent::query()->where('action', 'task.board_rule.flagged')->count());
        });
    }
}
