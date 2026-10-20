<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A job's notes, who wrote each and when (step c of moving off Workiz, Jose
 * 2026-10-20). Append-only, as complaint notes are: a note is a record of what
 * somebody said, so it is not edited afterwards.
 */
class JobNotesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_note_is_added_and_the_job_lists_its_notes_newest_first(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);

        $this->travelTo(now()->subMinute());
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/{$job}/notes", ['body' => 'Called, no answer.'])
            ->assertCreated()->assertJsonPath('data.body', 'Called, no answer.')
            ->assertJsonPath('data.author.id', $alpha['admin']->id);
        $this->travelBack();
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/{$job}/notes", ['body' => '  Booked for Tuesday.  '])->assertCreated();

        $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$job}")->assertOk()
            ->assertJsonPath('data.notes.*.body', ['Booked for Tuesday.', 'Called, no answer.']);
        $this->assertSame(2, $this->inTenant($alpha['org'], fn () => AuditEvent::query()->where('action', 'job.note_added')->count()));
    }

    public function test_reading_the_job_is_not_enough_to_write_on_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $viewer = $this->userWithRole($alpha, 'Viewer', ['jobs.view']);

        $this->signedInAs($viewer)->postJson("/api/v1/jobs/{$job}/notes", ['body' => 'x'])->assertForbidden();
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/{$job}/notes", ['body' => '   '])->assertStatus(422);
    }

    private function aJob(array $alpha): string
    {
        return $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'A Customer']);

            return Job::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-1', 'title' => 'A job', 'status' => 'scheduled', 'currency' => 'USD'])->id;
        });
    }
}
