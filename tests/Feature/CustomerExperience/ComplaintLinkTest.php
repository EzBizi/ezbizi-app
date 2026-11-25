<?php

namespace Tests\Feature\CustomerExperience;

use App\Models\Complaint;
use App\Models\Lead;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Support\Tasks\ComplaintLink;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose 11-25 (CX, one source): the branch task and the complaint's card are joined; status, owner and updates go both ways. */
class ComplaintLinkTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private ?Task $jobTask = null;

    public function test_a_new_complaint_joins_the_branch_task_with_its_phone_and_changes_go_both_ways(): void
    {
        $t = $this->provisionTenant('Alpha');
        $natalie = $this->userWithRole($t, 'Natalie', ['tasks.view', 'tasks.edit']);
        $w = fn () => app(TaskWriter::class);

        [$branchTask, $complaint] = $this->inTenant($t['org'], function () use ($t, $w) {
            $board = TaskBoard::query()->where('franchise_id', $t['franchise']->id)->where('kind', 'franchise')->firstOrFail();
            $phone = TaskColumn::query()->create(['organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => 'Phone number', 'type' => 'text', 'position' => 5]);
            // Natalie's task on the branch board, the customer's number on it.
            $cx = \App\Models\TaskGroup::query()->create(['organization_id' => $board->organization_id, 'board_id' => $board->id, 'name' => 'Customer Relations - Natalie', 'position' => 9]);
            $branchTask = $w()->create($board, ['title' => 'Customer Relations: Pat', 'group_id' => $cx->id, 'values' => [$phone->id => '(508) 555-0101']], $t['admin']);
            // The same customer's job task in an ordinary group is not the complaint (11-25: 1,418 such links).
            $this->jobTask = $w()->create($board, ['title' => 'Chimney sweep: Pat', 'values' => [$phone->id => '(508) 555-0101']], $t['admin']);
            $lead = Lead::create(['organization_id' => $t['org']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES, 'remote_id' => 'x1', 'contact_name' => 'Pat',
                'lead_type' => 'PHONE_CALL', 'status' => Lead::STATUS_NEW, 'received_at' => now(), 'phone' => '+15085550101', 'franchise_id' => $t['franchise']->id]);
            $complaint = Complaint::query()->create(['organization_id' => $t['org']->id, 'franchise_id' => $t['franchise']->id, 'kind' => 'call_complaint',
                'source_type' => 'lead', 'source_id' => $lead->id, 'status' => 'new', 'raised_at' => now()]);

            return [$branchTask, $complaint];
        });

        $this->inTenant($t['org'], function () use ($branchTask, $complaint, $natalie, $t, $w) {
            $card = Task::query()->where('external_ref', 'complaint:'.$complaint->id)->firstOrFail();
            $this->assertSame([$card->id], array_map(fn ($c) => $c->id, ComplaintLink::cardsOf($branchTask->fresh())), 'joined by phone');
            $this->assertSame([], ComplaintLink::cardsOf($this->jobTask->fresh()), 'only a customer-relations task');

            // Natalie works the branch task: owner, an update, then done.
            $w()->update($branchTask->fresh()->load('board'), ['assignee_user_ids' => [(string) $natalie->id]], $natalie);
            $w()->comment($branchTask->fresh(), $natalie, 'Called Pat back');
            $done = TaskStatus::query()->where('board_id', $branchTask->board_id)->where('is_done', true)->value('id');
            $w()->update($branchTask->fresh()->load('board'), ['status_id' => $done], $natalie);
        });

        $this->inTenant($t['org'], function () use ($complaint, $natalie) {
            $card = Task::query()->where('external_ref', 'complaint:'.$complaint->id)->firstOrFail();
            $this->assertSame('Solved', TaskStatus::query()->find($card->status_id)->name);
            $this->assertSame([(string) $natalie->id], $card->owners()->pluck('user_id')->map(fn ($i) => (string) $i)->all());
            $this->assertStringContainsString('Called Pat back', (string) TaskUpdate::query()->where('task_id', $card->id)->value('body'));
            $c = $complaint->fresh();
            $this->assertSame('solved', $c->status, 'the complaint itself, through its card');
            $this->assertSame((string) $natalie->id, (string) $c->owner_user_id);
        });
    }
}
