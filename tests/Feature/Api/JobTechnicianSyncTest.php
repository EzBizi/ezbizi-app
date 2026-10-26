<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\WorkizJob;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-22: a card's Technician is its job's, "yes and viceversa". */
class JobTechnicianSyncTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_card_and_job_technician_follow_each_other(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $ron = $this->userWithRole($alpha, 'Tech', ['tasks.view']);
        $dana = $this->userWithRole($alpha, 'Tech2', ['tasks.view']);

        $this->inTenant($alpha['org'], function () use ($alpha, $ron, $dana) {
            $board = TaskBoard::query()->where('franchise_id', $alpha['franchise']->id)->firstOrFail();
            $col = TaskColumn::query()->create(['organization_id' => $board->organization_id, 'board_id' => $board->id,
                'name' => 'Technician', 'type' => 'person', 'position' => 1]);
            $c = Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'A Customer']);
            $mk = fn (string $n) => Job::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $c->id, 'number' => $n,
                'title' => $n, 'status' => 'scheduled', 'currency' => 'USD']);
            $native = $mk('N-1');
            $workiz = $mk('W-1');
            $w = WorkizJob::create(['franchise_id' => $alpha['franchise']->id, 'remote_id' => 'JOB-W1', 'status' => 'x', 'payload' => [], 'synced_at' => now()]);
            $w->job_id = $workiz->id;
            $w->save();

            $writer = app(TaskWriter::class);
            $card = fn (Job $j) => $writer->create($board, ['title' => 'Paid: '.$j->number, 'about_type' => 'job', 'about_id' => $j->id], $alpha['admin']);
            $value = fn ($task) => $task->values()->where('column_id', $col->id)->value('value_user_id');

            // Card → job.
            $a = $card($native);
            $writer->update($a, ['values' => [$col->id => $ron->id]], $alpha['admin']);
            $this->assertSame($ron->id, $native->fresh()->performed_by_user_id);

            // A Workiz job whose technician has no Workiz link: refused, nothing saved on either side.
            $b = $card($workiz);
            try {
                $writer->update($b, ['values' => [$col->id => $ron->id]], $alpha['admin']);
                $this->fail('An unlinked technician was put on a Workiz job.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('is not linked to anyone on this branch\'s Workiz', collect($e->errors())->flatten()->first());
            }
            $this->assertNull($value($b->fresh()));
            $this->assertNull($workiz->fresh()->performed_by_user_id);

            // Job → card: the technician changes on the job (a person, or the Workiz sync).
            $native->fresh()->forceFill(['performed_by_user_id' => $dana->id])->save();
            $this->assertSame($dana->id, $value($a->fresh()));
        });
    }
}
