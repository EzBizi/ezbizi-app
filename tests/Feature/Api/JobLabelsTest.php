<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A job's sub-status, type and tags, native (Jose, 2026-10-20: "whatever it is
 * that we build, we need to have it's equivalent system in ezbizi ... If
 * workiz has tags, we need to have tags"). The words are the organization's
 * own; the pickers offer what its jobs already carry.
 */
class JobLabelsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_job_is_created_and_edited_with_its_labels_and_tidy_tags(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->create($alpha, [
            'job_type' => 'Chimney Sweep',
            'sub_status' => 'Client Requested Cancellation',
            'tags' => [' DONT CHANGE TECH', 'DONT CHANGE TECH', '', "\u{2060}Paid – Estimated"],
        ])->assertCreated()->json('data.id');

        $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$id}")->assertOk()
            ->assertJsonPath('data.job_type', 'Chimney Sweep')
            ->assertJsonPath('data.sub_status', 'Client Requested Cancellation')
            ->assertJsonPath('data.tags', ['DONT CHANGE TECH', 'Paid – Estimated']);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/jobs/{$id}", ['tags' => [], 'sub_status' => null])->assertOk()
            ->assertJsonPath('data.tags', [])
            ->assertJsonPath('data.sub_status', null)
            ->assertJsonPath('data.job_type', 'Chimney Sweep');
    }

    public function test_the_list_filters_by_tag_and_type_and_offers_the_words_in_use(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->create($alpha, ['title' => 'Sweep', 'job_type' => 'Chimney Sweep', 'tags' => ['RESCHEDULE']]);
        $this->create($alpha, ['title' => 'Look', 'job_type' => 'Chimney Inspection', 'tags' => ['On Monday', 'RESCHEDULE']]);
        $this->create($alpha, ['title' => 'Plain']);

        $titles = fn (string $q) => $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs?{$q}")->assertOk()->json('data.*.title');
        $this->assertEqualsCanonicalizing(['Sweep', 'Look'], $titles('tag='.urlencode('RESCHEDULE')));
        $this->assertSame(['Look'], $titles('job_type='.urlencode('Chimney Inspection')));
        // Lists (queue 118): any of the tags, any of the types, and the two together.
        $this->assertEqualsCanonicalizing(['Sweep', 'Look'], $titles('tag[]=On+Monday&tag[]=RESCHEDULE'));
        $this->assertSame(['Look'], $titles('tag[]=On+Monday&tag[]=nothing'));
        $this->assertEqualsCanonicalizing(['Sweep', 'Look'], $titles('job_type[]=Chimney+Sweep&job_type[]=Chimney+Inspection'));
        $this->assertSame(['Sweep'], $titles('job_type[]=Chimney+Sweep&tag[]=RESCHEDULE'));

        $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs')->assertOk()
            ->assertJsonPath('meta.labels.job_types', ['Chimney Inspection', 'Chimney Sweep'])
            ->assertJsonPath('meta.labels.tags', ['On Monday', 'RESCHEDULE']);
    }

    /** History from Workiz fills only what nobody has set, so a person's edit survives a re-run. */
    public function test_the_backfill_copies_workiz_labels_onto_empty_jobs_only(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $fresh = $this->mirrored($alpha, 'A1', ['JobType' => 'Free Estimate ', 'SubStatus' => 'Duplicate Job', 'Tags' => ['DEPOSIT RECIEVED', '']]);
        $edited = $this->mirrored($alpha, 'A2', ['JobType' => 'Redo', 'Tags' => ['x']]);
        $this->inTenant($alpha['org'], fn () => Job::query()->whereKey($edited)->update(['job_type' => 'Masonry Work']));

        $this->artisan('jobs:backfill-workiz-labels')->assertSuccessful();

        $read = fn (string $id) => $this->inTenant($alpha['org'], fn () => Job::query()->findOrFail($id));
        $this->assertSame(['Free Estimate', 'Duplicate Job', ['DEPOSIT RECIEVED']],
            [$read($fresh)->job_type, $read($fresh)->sub_status, $read($fresh)->tags]);
        $this->assertSame('Masonry Work', $read($edited)->job_type, "a person's word stays");
    }

    private function create(array $alpha, array $fields): \Illuminate\Testing\TestResponse
    {
        $customer = $this->inTenant($alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => 'A Customer',
        ])->id);

        return $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs', $fields + [
            'franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer, 'title' => 'A job', 'status' => 'scheduled',
        ]);
    }

    private function mirrored(array $alpha, string $remote, array $payload): string
    {
        $id = $this->create($alpha, [])->json('data.id');
        $this->inTenant($alpha['org'], function () use ($alpha, $remote, $payload, $id) {
            $row = WorkizJob::create(['franchise_id' => $alpha['franchise']->id, 'remote_id' => $remote, 'status' => 'Done',
                'payload' => $payload, 'synced_at' => now()]);
            $row->job_id = $id;
            $row->save();
        });

        return $id;
    }
}
