<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** @mentions and unread notes (monday port, Jose 2026-11-09). */
class NoteActivityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // After NoteActivity::COUNTS_FROM, so new notes count.
        $this->travelTo('2026-12-01 12:00:00');
    }

    public function test_only_people_who_can_see_the_job_can_be_mentioned(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $viewer = $this->userWithRole($alpha, 'Viewer', ['jobs.view']);
        $outsider = $this->userWithRole($alpha, 'Outsider', []);

        $this->signedInAs($alpha['admin'])->getJson("/api/v1/mentionable?type=job&id={$job}")->assertOk()
            ->assertJsonFragment(['id' => $viewer->id])
            ->assertJsonMissing(['id' => $outsider->id]);

        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/{$job}/notes", ['body' => '@Outsider look', 'mentions' => [$outsider->id]])
            ->assertStatus(422)->assertJsonValidationErrors('mentions');
        $this->assertSame(0, $this->inTenant($alpha['org'], fn () => Notification::query()->where('user_id', $outsider->id)->count()));

        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/{$job}/notes", ['body' => '@Viewer look', 'mentions' => [$viewer->id]])
            ->assertCreated()->assertJsonPath('data.mentions', [$viewer->id]);

        $this->signedInAs($viewer)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.kind', 'mentioned')
            ->assertJsonPath('data.0.meta.module', 'jobs')
            ->assertJsonPath('data.0.meta.record', ['type' => 'job', 'id' => $job]);
    }

    public function test_a_job_counts_notes_by_others_until_they_are_read(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $viewer = $this->userWithRole($alpha, 'Viewer', ['jobs.view']);

        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/{$job}/notes", ['body' => 'One'])->assertCreated();
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/{$job}/notes", ['body' => 'Two'])->assertCreated();

        $this->signedInAs($viewer)->getJson('/api/v1/jobs')->assertOk()->assertJsonPath('data.0.unread_notes', 2);
        // Your own notes are never unread to you.
        $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs')->assertOk()->assertJsonPath('data.0.unread_notes', 0);

        $this->travel(1)->minutes();
        $this->signedInAs($viewer)->postJson('/api/v1/note-reads', ['type' => 'job', 'id' => $job])->assertOk();
        $this->signedInAs($viewer)->getJson('/api/v1/jobs')->assertOk()->assertJsonPath('data.0.unread_notes', 0);

        $this->travel(1)->minutes();
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/{$job}/notes", ['body' => 'Three'])->assertCreated();
        $this->signedInAs($viewer)->getJson('/api/v1/jobs')->assertOk()->assertJsonPath('data.0.unread_notes', 1);
    }

    public function test_a_lead_note_is_unread_once_and_its_mentions_clear_with_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $reader = $this->userWithRole($alpha, 'Reader', ['leads.view', 'leads.edit', 'leads.'.Lead::SOURCE_WEBSITE_CHAT.'.view']);
        $lead = $this->inTenant($alpha['org'], fn () => Lead::create([
            'company_id' => $alpha['company']->getKey(), 'source' => Lead::SOURCE_WEBSITE_CHAT,
            'remote_id' => 'chat-1', 'status' => Lead::STATUS_NEW, 'contact_name' => 'Ann', 'received_at' => now(),
        ])->id);

        $this->signedInAs($reader)->getJson('/api/v1/leads')->assertOk()->assertJsonPath('data.0.unread_notes', 0);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/leads/{$lead}", ['note' => 'Call back', 'mentions' => [$reader->id]])->assertOk();
        $this->assertSame([$reader->id], $this->inTenant($alpha['org'], fn () => Lead::query()->find($lead)->note_mentions));
        $this->signedInAs($reader)->getJson('/api/v1/leads')->assertOk()->assertJsonPath('data.0.unread_notes', 1);

        $this->travel(1)->minutes();
        $this->signedInAs($reader)->postJson('/api/v1/note-reads', ['type' => 'lead', 'id' => $lead])->assertOk();
        $this->signedInAs($reader)->getJson('/api/v1/leads')->assertOk()->assertJsonPath('data.0.unread_notes', 0);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/leads/{$lead}", ['note' => null])->assertOk();
        $this->assertNull($this->inTenant($alpha['org'], fn () => Lead::query()->find($lead)->note_mentions));
    }

    public function test_reading_needs_sight_of_the_record(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $outsider = $this->userWithRole($alpha, 'Outsider', []);

        $this->signedInAs($outsider)->postJson('/api/v1/note-reads', ['type' => 'job', 'id' => $job])->assertForbidden();
        $this->signedInAs($outsider)->getJson("/api/v1/mentionable?type=job&id={$job}")->assertForbidden();
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
