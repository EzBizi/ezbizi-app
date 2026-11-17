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

    /** Jose, 2026-11-17: "i cannot edit notes". Its author, or an administrator; and it says "edited". */
    public function test_a_note_is_changed_or_deleted_by_its_author_or_an_administrator_only(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $shoval = $this->userWithRole($alpha, 'Project Manager', ['jobs.view', 'jobs.edit']);
        $other = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view', 'jobs.edit']);
        $note = $this->signedInAs($shoval)->postJson("/api/v1/jobs/{$job}/notes", ['body' => 'Call 30 min before.'])
            ->assertCreated()->assertJsonPath('data.can_change', true)->json('data.id');

        $this->signedInAs($other)->patchJson("/api/v1/jobs/{$job}/notes/{$note}", ['body' => 'Mine now'])->assertForbidden();
        $this->signedInAs($other)->getJson("/api/v1/jobs/{$job}")->assertJsonPath('data.notes.0.can_change', false);

        $this->signedInAs($shoval)->patchJson("/api/v1/jobs/{$job}/notes/{$note}", ['body' => 'Call 1 hour before.'])
            ->assertOk()->assertJsonPath('data.body', 'Call 1 hour before.');
        $this->assertNotNull($this->signedInAs($shoval)->getJson("/api/v1/jobs/{$job}")->json('data.notes.0.edited_at'));

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/jobs/{$job}/notes/{$note}")->assertOk();
        $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$job}")->assertJsonCount(0, 'data.notes');
    }

    /** Workiz's own job notes are edited here and written back to Workiz; a refusal says why. */
    public function test_workiz_notes_are_written_back_to_workiz_and_a_refusal_keeps_ours(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $fixture = require base_path('tests/Fixtures/workiz/v2_job.php');
        \Illuminate\Support\Facades\Http::fake([
            'api.workiz.com/crm/api/v2/jobs/JOB-1' => \Illuminate\Support\Facades\Http::sequence()
                // Read before the write: still what the editor opened.
                ->push(array_replace($fixture, ['id' => 'JOB-1', 'description' => 'Level 2 $149']))
                ->push(array_replace($fixture, ['id' => 'JOB-1', 'description' => 'Level 2 $149, call 30 mins before']))
                // Then an inspection link was appended in Workiz meanwhile.
                ->push(array_replace($fixture, ['id' => 'JOB-1', 'description' => "Level 2 \$149, call 30 mins before\nReport: https://x"])),
        ]);
        $this->inTenant($alpha['org'], function () use ($alpha, $job, $fixture) {
            $c = new \App\Models\SocialConnection;
            $c->forceFill(['organization_id' => $alpha['org']->id, 'scope_type' => \App\Models\SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $alpha['franchise']->id, 'provider' => \App\Support\Social\SocialProvider::Workiz->value,
                'remote_id' => $alpha['franchise']->id, 'display_name' => 'NY']);
            $c->connect(['api_token' => 'v2key_NY', 'refresh_token' => 'v2key_NY', 'api_version' => 'v2']);
            $c->save();
            \App\Models\WorkizJob::land($c, \App\Support\Workiz\WorkizV2::job(array_replace($fixture, ['id' => 'JOB-1', 'description' => 'Level 2 $149'])));
            \App\Models\WorkizJob::query()->sole()->forceFill(['job_id' => $job])->save();
        });
        $api = $this->signedInAs($alpha['admin']);

        $api->putJson("/api/v1/jobs/{$job}/workiz-notes", ['text' => 'Level 2 $149, call 30 mins before', 'was' => 'Level 2 $149'])
            ->assertOk()->assertJsonPath('data.workiz_notes', 'Level 2 $149, call 30 mins before');
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['description'] === 'Level 2 $149, call 30 mins before');
        $api->getJson("/api/v1/jobs/{$job}")->assertJsonPath('data.workiz_notes', 'Level 2 $149, call 30 mins before');

        $api->putJson("/api/v1/jobs/{$job}/workiz-notes", ['text' => 'Lost?', 'was' => 'Level 2 $149, call 30 mins before'])
            ->assertStatus(422)->assertJsonPath('current', "Level 2 \$149, call 30 mins before\nReport: https://x");
        \Illuminate\Support\Facades\Http::assertSentCount(3);
        $api->getJson("/api/v1/jobs/{$job}")->assertJsonPath('data.workiz_notes', 'Level 2 $149, call 30 mins before');
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
