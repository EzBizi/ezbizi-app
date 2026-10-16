<?php

namespace Tests\Feature\Field;

use App\Models\Customer;
use App\Models\FieldPresence;
use App\Models\FieldRecording;
use App\Models\Job;
use App\Models\User;
use App\Support\Files\FileStore;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Field safety recordings, ported from A1 Tools (2026-10-16). */
class FieldRecordingsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private User $tech;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 14:00:00');
        Storage::fake(app(FileStore::class)->defaultDisk());

        $this->t = $this->provisionTenant();
        $this->tech = $this->userWithRole($this->t, 'Technician', ['field.view', 'field.tracked', 'field.record']);
        $this->manager = $this->userWithRole($this->t, 'Dispatcher', ['field.view', 'field.listen', 'field.manage']);
        $this->set('field.recording_enabled', true);
    }

    private function set(string $key, mixed $value): void
    {
        $this->inTenant($this->t['org'], fn () => app(SettingsResolver::class)->set($key, $this->t['org'], $value));
    }

    /** A job at a customer in [$state], with the technician standing at it. */
    private function jobIn(string $state): Job
    {
        return $this->inTenant($this->t['org'], function () use ($state) {
            $customer = Customer::factory()->create(['owning_franchise_id' => $this->t['franchise']->id, 'state' => $state]);
            $job = Job::factory()->create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id]);
            FieldPresence::query()->updateOrCreate(['user_id' => $this->tech->id], [
                'latitude' => 40, 'longitude' => -75, 'last_seen_at' => now(),
                'anchor_latitude' => 40, 'anchor_longitude' => -75, 'anchored_at' => now(), 'at_job_id' => $job->id,
            ]);

            return $job;
        });
    }

    private function record(User $who): string
    {
        return $this->signedInAs($who)->postJson('/api/v1/field/recordings', [
            'started_at' => '2026-10-16T13:00:00Z', 'disclosure_confirmed' => true,
        ])->assertCreated()->json('data.id');
    }

    private function chunk(string $id, int $seq, string $bytes = 'audio-bytes', ?string $sha = null)
    {
        return $this->post("/api/v1/field/recordings/{$id}/chunks", [
            'seq' => $seq, 'started_at' => '2026-10-16T13:00:00Z', 'duration_ms' => 60000,
            'sha256' => $sha ?? hash('sha256', $bytes),
            'audio' => UploadedFile::fake()->createWithContent("{$seq}.m4a", $bytes),
        ], ['Accept' => 'application/json']);
    }

    public function test_a_technician_records_in_chunks_and_hears_their_own(): void
    {
        $id = $this->record($this->tech);

        $this->chunk($id, 0)->assertCreated();
        $this->chunk($id, 0)->assertOk()->assertJsonPath('data.stored', true);
        $this->chunk($id, 1, 'second', str_repeat('0', 64))->assertStatus(422);
        $this->chunk($id, 1, 'second')->assertCreated();

        $this->postJson("/api/v1/field/recordings/{$id}/finish", [
            'ended_at' => '2026-10-16T13:02:00Z', 'stop_reason' => 'done',
            'events' => [['at' => '2026-10-16T13:01:00Z', 'type' => 'gap_phone_call']],
        ])->assertOk()
            ->assertJsonPath('data.chunks', 2)
            ->assertJsonPath('data.duration_s', 120)
            ->assertJsonPath('data.events.0.type', 'gap_phone_call')
            ->assertJsonPath('data.delete_on', '2027-01-14');

        $this->getJson('/api/v1/field/recordings')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('second', $this->get("/api/v1/field/recordings/{$id}/audio/1")->assertOk()->streamedContent());
    }

    public function test_nobody_else_adds_to_a_recording(): void
    {
        $id = $this->record($this->tech);
        $other = $this->userWithRole($this->t, 'Tech2', ['field.view', 'field.record']);

        $this->signedInAs($other);
        $this->chunk($id, 0)->assertForbidden();
    }

    public function test_listening_to_others_needs_the_grant_and_the_view(): void
    {
        $id = $this->record($this->tech);
        $this->chunk($id, 0)->assertCreated();

        $this->signedInAs($this->manager)->getJson('/api/v1/field/recordings')->assertJsonCount(1, 'data');
        $this->get("/api/v1/field/recordings/{$id}/audio/0")->assertOk();

        $peer = $this->userWithRole($this->t, 'Peer', ['field.view', 'field.record']);
        $this->signedInAs($peer)->getJson('/api/v1/field/recordings')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/field/recordings/{$id}/audio/0")->assertForbidden();

        $elsewhere = $this->userWithRole($this->t, 'Far', ['field.view', 'field.listen'],
            onlyAt: $this->extraFranchise($this->t, 'F2'));
        $this->signedInAs($elsewhere)->getJson("/api/v1/field/recordings/{$id}/audio/0")->assertForbidden();
    }

    public function test_after_ninety_days_a_recording_and_its_audio_go_unless_kept(): void
    {
        $gone = $this->record($this->tech);
        $this->chunk($gone, 0)->assertCreated();
        $kept = $this->record($this->tech);
        $this->chunk($kept, 0)->assertCreated();
        $this->signedInAs($this->manager)->postJson("/api/v1/field/recordings/{$kept}/flag", ['flagged' => true, 'reason' => 'incident'])
            ->assertOk()->assertJsonPath('data.delete_on', null);

        $this->travel(91)->days();
        $this->artisan('ezbizi:field-sweep')->assertSuccessful();

        $ids = $this->inTenant($this->t['org'], fn () => FieldRecording::query()->pluck('id')->all());
        $this->assertSame([$kept], $ids);
        $disk = Storage::disk(app(FileStore::class)->defaultDisk());
        $this->assertSame([], $disk->allFiles("field-audio/{$this->t['org']->id}/{$gone}"));
        $this->assertCount(1, $disk->allFiles("field-audio/{$this->t['org']->id}/{$kept}"));
    }

    public function test_recording_is_off_until_a_company_turns_it_on(): void
    {
        $this->set('field.recording_enabled', false);

        $this->signedInAs($this->tech)->getJson('/api/v1/field/me')->assertJsonPath('data.recording.allowed', false);
        $this->postJson('/api/v1/field/recordings', ['started_at' => '2026-10-16T13:00:00Z', 'disclosure_confirmed' => true])
            ->assertForbidden();
    }

    public function test_the_technician_must_confirm_the_disclosure(): void
    {
        $this->signedInAs($this->tech)->postJson('/api/v1/field/recordings', ['started_at' => '2026-10-16T13:00:00Z'])
            ->assertStatus(422)->assertJsonValidationErrors('disclosure_confirmed');
    }

    public function test_an_all_party_state_blocks_by_default_or_needs_the_customer_told(): void
    {
        $job = $this->jobIn('wa');
        $body = ['started_at' => '2026-10-16T13:00:00Z', 'disclosure_confirmed' => true, 'job_id' => $job->id];

        $this->signedInAs($this->tech)->getJson('/api/v1/field/me')
            ->assertJsonPath('data.at_job.id', $job->id)
            ->assertJsonPath('data.recording.allowed', false);
        $this->postJson('/api/v1/field/recordings', $body)->assertForbidden();

        $this->set('field.recording_all_party_rule', 'announce');
        $this->getJson('/api/v1/field/me')
            ->assertJsonPath('data.recording.allowed', true)
            ->assertJsonPath('data.recording.announce', true)
            ->assertJsonPath('data.recording.notice', 'This visit may be recorded for safety and quality.');
        $this->postJson('/api/v1/field/recordings', $body)->assertStatus(422);
        $this->postJson('/api/v1/field/recordings', $body + ['customer_told' => true])->assertCreated();
    }

    public function test_a_one_party_state_records_without_notice(): void
    {
        $job = $this->jobIn('tx');

        $this->signedInAs($this->tech)->getJson('/api/v1/field/me')
            ->assertJsonPath('data.recording.allowed', true)
            ->assertJsonPath('data.recording.announce', false);
        $this->postJson('/api/v1/field/recordings', [
            'started_at' => '2026-10-16T13:00:00Z', 'disclosure_confirmed' => true, 'job_id' => $job->id,
        ])->assertCreated();
        $this->signedInAs($this->manager)->getJson('/api/v1/field/me')->assertJsonPath('data.recording.allowed', false);
    }

    public function test_keeping_a_recording_needs_manage(): void
    {
        $id = $this->record($this->tech);

        $this->postJson("/api/v1/field/recordings/{$id}/flag", ['flagged' => true])->assertForbidden();
    }
}
