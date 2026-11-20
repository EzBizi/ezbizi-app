<?php

namespace Tests\Feature\CustomerExperience;

use App\Models\Complaint;
use App\Models\ComplaintNote;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Shoval and Eric 2026-11-20: every complaint is a card on Task Board's "Reviews & Complaints", both ways. */
class ComplaintBoardMirrorTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_complaint_is_a_card_in_its_market_group_and_edits_flow_both_ways(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->signedInAs($t['admin'])->postJson('/api/v1/complaints', ['kind' => 'bbb', 'title' => 'BBB case 77',
            'franchise_id' => $t['franchise']->id])->assertCreated();

        $this->inTenant($t['org'], function () use ($t) {
            $c = Complaint::query()->firstOrFail();
            $task = Task::query()->where('external_ref', 'complaint:'.$c->id)->firstOrFail();
            $this->assertSame('BBB: BBB case 77', $task->title);
            $this->assertSame($t['franchise']->name, $task->group?->name ?? \App\Models\TaskGroup::query()->find($task->group_id)?->name);
            $this->assertSame('New', TaskStatus::query()->find($task->status_id)->name);
            $this->assertSame($c->due_at->format('Y-m-d'), optional($task->due_on)->format('Y-m-d') ?? substr((string) $task->due_on, 0, 10));

            // CX → card
            $c->update(['status' => 'in_progress']);
            $this->assertSame('In progress', TaskStatus::query()->find($task->fresh()->status_id)->name);

            // card → CX: a person solves it on the board and writes an update
            $solved = TaskStatus::query()->where('board_id', $task->board_id)->where('name', 'Solved')->value('id');
            app(TaskWriter::class)->update($task->fresh(), ['status_id' => $solved], $t['admin']);
            app(TaskWriter::class)->comment($task->fresh(), $t['admin'], 'Called the BBB back');
        });

        $this->inTenant($t['org'], function () {
            $c = Complaint::query()->firstOrFail();
            $this->assertSame('solved', $c->status);
            $this->assertNotNull($c->solved_at);
            $this->assertSame(['Called the BBB back'], ComplaintNote::query()->pluck('body')->all(), 'once, no echo back as a second update');
            $this->assertSame(1, \App\Models\TaskUpdate::query()->count());
        });
    }
}
