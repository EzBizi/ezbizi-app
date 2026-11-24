<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The technician bot's past-job photos (Jose, 2026-11-09). */
class JobPhotosTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Prod, 2026-11-20: "chimney crown repair" is spread over type, title and description, and found 0. */
    public function test_every_word_of_the_type_is_matched_across_type_title_and_description(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Technician', ['jobs.view']);
        $this->inTenant($alpha['org'], function () use ($alpha) {
            $f = $alpha['franchise'];
            $c = Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-1', 'name' => 'A']);
            $job = fn (string $n, string $type, string $title, string $description) => tap(Job::create([
                'franchise_id' => $f->id, 'customer_id' => $c->id, 'number' => $n, 'job_type' => $type, 'title' => $title,
                'description' => $description, 'status' => 'Done', 'currency' => 'USD',
            ]), fn (Job $j) => WorkizJob::create(['franchise_id' => $f->id, 'job_id' => $j->id, 'remote_id' => $n, 'status' => 'Done',
                'city' => $n, 'synced_at' => now(), 'payload' => ['Attachments' => [
                    ['id' => 1, 'Name' => 'before.jpg', 'Url' => "https://st.example/{$n}/before.jpg"],
                    ['id' => 2, 'Name' => 'after.jpg', 'Url' => "https://st.example/{$n}/after.jpg"],
                ]]]));
            $job('A', 'Repair', 'Chimney crown', 'repair needed');
            $job('B', 'Inspection', 'Chimney', 'sweep');
        });

        $photos = $this->signedInAs($viewer)->getJson('/api/v1/jobs/photos?job_type='.urlencode('chimney crown repair'))
            ->assertOk()->json('data');

        $this->assertSame(['A'], array_column($photos, 'city'));
        $this->assertSame(['https://st.example/A/before.jpg', 'https://st.example/A/after.jpg'], $photos[0]['photos']);
    }
}
