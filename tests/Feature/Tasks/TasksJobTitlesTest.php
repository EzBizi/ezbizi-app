<?php

namespace Tests\Feature\Tasks;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\WorkizJob;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Row 41 (Jose 11-23): "600025660 Albert", "Jade 400595628": the number to its columns, the title the name, wherever they are. */
class TasksJobTitlesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_title_with_a_real_job_number_is_cleaned_and_an_unknown_one_left(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$a, $b, $c] = $this->inTenant($t['org'], function () use ($t) {
            $board = TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first();
            $customer = Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => 'Debby Bloom']);
            foreach (['600025660', '400595628'] as $i => $serial) {
                $job = Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id]);
                WorkizJob::create(['franchise_id' => $t['franchise']->id, 'remote_id' => 'R'.$i, 'serial_id' => $serial, 'status' => 'x', 'payload' => [], 'synced_at' => now(), 'job_id' => $job->id]);
            }
            $w = app(TaskWriter::class);

            return [$w->create($board, ['title' => '600025660 Albert'], $t['admin']), $w->create($board, ['title' => 'Jade 400595628'], $t['admin']),
                $w->create($board, ['title' => '999999999 Bob'], $t['admin'])];
        });

        $this->artisan('ezbizi:tasks-job-titles', ['--org' => $t['org']->id])->assertSuccessful();

        $this->inTenant($t['org'], function () use ($a, $b, $c) {
            $a = Task::query()->find($a->id);
            $this->assertSame(['Debby Bloom · Albert', 'job'], [$a->title, $a->about_type]);
            $this->assertContains('600025660', $a->values()->pluck('value_text')->all());
            $this->assertSame('Debby Bloom · Jade', Task::query()->find($b->id)->title);
            $this->assertSame('999999999 Bob', Task::query()->find($c->id)->title, 'no such job: left');
        });
    }
}
