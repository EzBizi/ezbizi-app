<?php

namespace Tests\Feature\Phone;

use App\Http\Controllers\Api\Phone\AudioController;
use App\Models\PhoneConnection;
use App\Models\PhoneFlow;
use App\Models\PhoneNumber;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A recorded prompt instead of text-to-speech. Jose, 2026-10-05: "automatic
 * audio replies — text to speech or recorded".
 */
class PhoneAudioTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_an_editor_uploads_a_recording_and_twilio_fetches_it_by_its_signed_link(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Office', ['phone.view', 'phone.edit', 'phone.settings.view']);

        $created = $this->signedInAs($editor)->post('/api/v1/phone/audio', [
            'audio' => UploadedFile::fake()->create('greeting.mp3', 200, 'audio/mpeg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $name = $created->json('data.name');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.mp3$/', $name);
        Storage::disk('local')->assertExists('phone-audio/'.$name);

        $this->app['auth']->forgetGuards();
        $this->get($created->json('data.url'))->assertOk();

        // The link is the permission: the bare path is refused.
        $this->get('/api/v1/phone/audio/'.$name)->assertForbidden();
    }

    public function test_a_viewer_cannot_upload(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Desk', ['phone.view', 'phone.settings.view']);

        $this->signedInAs($viewer)->post('/api/v1/phone/audio', [
            'audio' => UploadedFile::fake()->create('greeting.mp3', 200, 'audio/mpeg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_menu_with_a_recording_plays_it_and_falls_back_to_the_text_without_one(): void
    {
        $t = $this->provisionTenant();
        Storage::disk('local')->put('phone-audio/11111111-2222-3333-4444-555555555555.mp3', 'ID3');

        [$flow, $number] = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
            $flow = PhoneFlow::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Main menu',
                'nodes' => ['type' => 'menu', 'prompt' => 'Press 1 for the desk.', 'options' => []],
            ]);
            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'flow_id' => $flow->id,
            ]);

            return [$flow, $number];
        });

        $xml = fn (string $audio) => $this->inTenant($t['org'], function () use ($flow, $number, $audio) {
            $flow->forceFill(['nodes' => ['prompt_audio' => $audio] + $flow->nodes])->save();

            return (new TwimlRenderer)->inbound($number->fresh());
        });

        $played = $xml('11111111-2222-3333-4444-555555555555.mp3');
        $this->assertStringContainsString('<Play>', $played);
        $this->assertStringContainsString('phone/audio/11111111-2222-3333-4444-555555555555.mp3', $played);
        $this->assertStringNotContainsString('Press 1 for the desk.', $played);

        // A file that is gone is never silence.
        $spoken = $xml('99999999-2222-3333-4444-555555555555.mp3');
        $this->assertStringNotContainsString('<Play>', $spoken);
        $this->assertStringContainsString('Press 1 for the desk.', $spoken);
    }
}
