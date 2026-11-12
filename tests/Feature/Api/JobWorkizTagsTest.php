<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** "Add a tag" offers the job's Workiz account's tags as they are now, in their colours (Jose, 2026-11-10). */
class JobWorkizTagsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_job_carries_its_accounts_current_tags_and_their_colours(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->inTenant($alpha['org'], function () use ($alpha) {
            $f = $alpha['franchise'];
            foreach ([['FOLLOW UP', '#e18a2b'], ['DEPOSIT RECEIVED', '#d574e4']] as $i => [$name, $hex]) {
                WorkizRecord::create(['franchise_id' => $f->id, 'type' => 'tag', 'remote_id' => "TAG-{$i}",
                    'payload' => ['name' => $name, 'color' => $hex], 'synced_at' => now()]);
            }
            $c = Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-1', 'name' => 'A']);

            // An old EzBizi-only tag sits on the job; it is shown, never offered.
            $job = Job::create(['franchise_id' => $f->id, 'customer_id' => $c->id, 'number' => 'J-1', 'title' => 'J',
                'status' => 'Submitted', 'currency' => 'USD', 'tags' => ['🟢 Paid – Estimated', 'Deposit Received']]);
            $w = \App\Models\WorkizJob::create(['franchise_id' => $f->id, 'remote_id' => 'W1', 'status' => 'x',
                'payload' => ['JobNotes' => "Sweep \$299\nWill let you know when we schedule"], 'synced_at' => now()]);
            $w->job_id = $job->id;
            $w->save();

            return $job->id;
        });

        $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$id}")->assertOk()
            ->assertJsonPath('data.workiz_tags', [
                ['name' => 'DEPOSIT RECEIVED', 'colour' => '#D574E4'],
                ['name' => 'FOLLOW UP', 'colour' => '#E18A2B'],
            ])
            ->assertJsonPath('data.tag_colours', ['Deposit Received' => '#D574E4'])
            // The Notes tab shows Workiz's notes, read only (Jose, 2026-11-10).
            ->assertJsonPath('data.workiz_notes', "Sweep \$299\nWill let you know when we schedule");
    }
}
