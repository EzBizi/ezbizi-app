<?php

namespace Tests\Feature\Api;

use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Job statuses are a named pipeline. Changing the stored value has to move
 * jobs that already have it — leaving them on the old word is how a rename
 * orphans work.
 */
class JobStatusSettingsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_renaming_a_status_renames_existing_jobs(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $customer = $this->signedInAs($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Person',
        ])->assertCreated()->json('data.id');

        $jobId = $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id,
            'customer_id'  => $customer,
            'title'        => 'Morning call',
            'status'       => 'scheduled',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($alpha['admin'])
            ->patchJson(
                '/api/v1/settings/jobs.statuses?scope_type=organization&scope_id='.$alpha['org']->id,
                ['value' => [
                    ['value' => 'booked', 'label' => 'Booked', 'was' => 'scheduled'],
                    ['value' => 'in_progress', 'label' => 'In progress'],
                    ['value' => 'completed', 'label' => 'Completed'],
                    ['value' => 'cancelled', 'label' => 'Cancelled'],
                ]],
            )
            ->assertOk();

        $this->inTenant($alpha['org'], function () use ($jobId) {
            $this->assertSame('booked', Job::query()->findOrFail($jobId)->status);
        });

        $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.status_label', 'Booked');
    }

    // Audit 2026-11-27: scheduled→in_progress and in_progress→done in one save
    // used to land both jobs on done.
    public function test_chained_renames_in_one_save_keep_jobs_apart(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $customer = $this->signedInAs($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Person',
        ])->assertCreated()->json('data.id');
        $job = fn (string $status) => $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id,
            'customer_id'  => $customer,
            'title'        => $status,
            'status'       => $status,
        ])->assertCreated()->json('data.id');
        $wasScheduled = $job('scheduled');
        $wasInProgress = $job('in_progress');

        $this->signedInAs($alpha['admin'])
            ->patchJson(
                '/api/v1/settings/jobs.statuses?scope_type=organization&scope_id='.$alpha['org']->id,
                ['value' => [
                    ['value' => 'in_progress', 'label' => 'In progress', 'was' => 'scheduled'],
                    ['value' => 'done', 'label' => 'Done', 'was' => 'in_progress'],
                    ['value' => 'completed', 'label' => 'Completed'],
                    ['value' => 'cancelled', 'label' => 'Cancelled'],
                ]],
            )
            ->assertOk();

        $this->inTenant($alpha['org'], function () use ($wasScheduled, $wasInProgress) {
            $this->assertSame('in_progress', Job::query()->findOrFail($wasScheduled)->status);
            $this->assertSame('done', Job::query()->findOrFail($wasInProgress)->status);
        });
    }

    public function test_the_default_list_has_names_people_can_read(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $timezone = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/settings?scope_type=organization&scope_id='.$alpha['org']->id)
            ->assertOk()
            ->json('data');

        $statuses = collect($timezone)->firstWhere('key', 'jobs.statuses');

        $this->assertSame('list', $statuses['type']);
        $this->assertSame(
            ['value' => 'scheduled', 'label' => 'Scheduled'],
            collect($statuses['value'])->firstWhere('value', 'scheduled'),
        );
        $this->assertSame(
            ['value' => 'in_progress', 'label' => 'In progress'],
            collect($statuses['value'])->firstWhere('value', 'in_progress'),
        );
    }
}
