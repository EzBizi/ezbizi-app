<?php

namespace Tests\Feature\Phone;

use App\Models\AutomationEvent;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Modules\ModuleRegistry;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Recording / transcription webhooks and granted playback (Task 11).
 *
 * Audio stays on Twilio. We store the SID, stream through EzBizi with the
 * connection credentials, and refuse play without a number grant.
 */
class RecordingWebhookTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_recording_callback_stores_the_recording_sid(): void
    {
        $t = $this->provisionTenant();
        [$connection, $call, $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->seedCall($t, e164: '+12035552001', callSid: 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        });

        $this->signedPost($this->recordingUrl($t['org']->id, $connection->id), [
            'CallSid' => 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'RecordingSid' => 'REbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'RecordingStatus' => 'completed',
            'RecordingDuration' => '12',
            'RecordingSource' => 'DialVerb',
        ], $authToken)->assertOk();

        $this->inTenant($t['org'], function () use ($call) {
            $row = PhoneCall::query()->find($call->id);
            $this->assertNotNull($row);
            $this->assertSame('REbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $row->recording_sid);
            $this->assertFalse($row->voicemail);
            $this->assertSame(0, AutomationEvent::query()
                ->where('event_key', 'phone.voicemail.left')
                ->count());
        });
    }

    public function test_voicemail_record_sets_voicemail_and_emits_left(): void
    {
        $t = $this->provisionTenant();
        [$connection, $call, $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->seedCall(
                $t,
                e164: '+12035552002',
                callSid: 'CAcccccccccccccccccccccccccccccccc',
                status: 'no-answer',
            );
        });

        $this->signedPost($this->recordingUrl($t['org']->id, $connection->id), [
            'CallSid' => 'CAcccccccccccccccccccccccccccccccc',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'RecordingSid' => 'REdddddddddddddddddddddddddddddddd',
            'RecordingStatus' => 'completed',
            'RecordingDuration' => '8',
            'RecordingSource' => 'RecordVerb',
        ], $authToken)->assertOk();

        $this->inTenant($t['org'], function () use ($call) {
            $row = PhoneCall::query()->find($call->id);
            $this->assertNotNull($row);
            $this->assertSame('REdddddddddddddddddddddddddddddddd', $row->recording_sid);
            $this->assertTrue($row->voicemail);

            $event = AutomationEvent::query()
                ->where('event_key', 'phone.voicemail.left')
                ->first();
            $this->assertNotNull($event);
            $this->assertSame($call->id, $event->subject_id);
            $this->assertSame(PhoneCall::class, $event->subject_type);
        });
    }

    public function test_play_streams_through_the_gateway_when_the_number_is_granted(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Desk play', ['phone.view']);

        $call = $this->inTenant($t['org'], function () use ($t, $viewer) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => 'play-auth',
            ]);
            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035552003',
            ]);
            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $viewer->role_id,
            ]);

            return PhoneCall::create([
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'twilio_sid' => 'CAeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
                'direction' => 'inbound',
                'from_e164' => '+15550001111',
                'to_e164' => '+12035552003',
                'status' => 'completed',
                'recording_sid' => 'REffffffffffffffffffffffffffffffff',
            ]);
        });

        $response = $this->signedInAs($viewer)
            ->get('/api/v1/phone/calls/'.$call->id.'/recording')
            ->assertOk();

        $this->assertSame('fake-recording-bytes', $response->getContent());
        $this->assertStringContainsString('audio/', (string) $response->headers->get('Content-Type'));

        $fake = $this->app->make(TwilioGateway::class);
        $this->assertInstanceOf(FakeTwilioGateway::class, $fake);
        $media = collect($fake->calls)->firstWhere('method', 'recordingMedia');
        $this->assertNotNull($media);
        $this->assertSame('REffffffffffffffffffffffffffffffff', $media['args'][1]);
    }

    public function test_play_is_forbidden_without_a_number_grant(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'No grant play', ['phone.view']);

        $call = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => 'no-grant-auth',
            ]);
            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035552004',
            ]);

            return PhoneCall::create([
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'twilio_sid' => 'CA11111111111111111111111111111111',
                'direction' => 'inbound',
                'from_e164' => '+15550002222',
                'to_e164' => '+12035552004',
                'status' => 'completed',
                'recording_sid' => 'RE22222222222222222222222222222222',
            ]);
        });

        $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/calls/'.$call->id.'/recording')
            ->assertForbidden();

        $fake = $this->app->make(TwilioGateway::class);
        $this->assertInstanceOf(FakeTwilioGateway::class, $fake);
        $this->assertNull(collect($fake->calls)->firstWhere('method', 'recordingMedia'));
    }

    public function test_transcription_finals_concatenate_onto_the_call(): void
    {
        $t = $this->provisionTenant();
        [$connection, $call, $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->seedCall($t, e164: '+12035552005', callSid: 'CA33333333333333333333333333333333');
        });

        $url = $this->transcriptionUrl($t['org']->id, $connection->id);

        $this->signedPost($url, [
            'CallSid' => 'CA33333333333333333333333333333333',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'TranscriptionSid' => 'GT44444444444444444444444444444444',
            'TranscriptionEvent' => 'transcription-content',
            'TranscriptionData' => json_encode(['transcript' => 'Hello there.', 'confidence' => 0.9]),
            'Final' => 'false',
            'Track' => 'inbound_track',
            'SequenceId' => '1',
        ], $authToken)->assertOk();

        $this->signedPost($url, [
            'CallSid' => 'CA33333333333333333333333333333333',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'TranscriptionSid' => 'GT44444444444444444444444444444444',
            'TranscriptionEvent' => 'transcription-content',
            'TranscriptionData' => json_encode(['transcript' => 'Hello there.', 'confidence' => 0.95]),
            'Final' => 'true',
            'Track' => 'inbound_track',
            'SequenceId' => '2',
        ], $authToken)->assertOk();

        $this->signedPost($url, [
            'CallSid' => 'CA33333333333333333333333333333333',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'TranscriptionSid' => 'GT44444444444444444444444444444444',
            'TranscriptionEvent' => 'transcription-content',
            'TranscriptionData' => json_encode(['transcript' => 'Can you come Thursday?', 'confidence' => 0.97]),
            'Final' => 'true',
            'Track' => 'inbound_track',
            'SequenceId' => '3',
        ], $authToken)->assertOk();

        $this->inTenant($t['org'], function () use ($call) {
            $row = PhoneCall::query()->find($call->id);
            $this->assertNotNull($row);
            $this->assertSame('GT44444444444444444444444444444444', $row->transcription_sid);
            $this->assertSame("Hello there.\nCan you come Thursday?", $row->transcript);
        });
    }

    public function test_phone_voicemail_left_is_raised(): void
    {
        $module = app(ModuleRegistry::class)->get('phone');
        $this->assertTrue($module->trigger('phone.voicemail.left')->isRaised);
    }

    public function test_a_bad_recording_signature_writes_nothing(): void
    {
        $t = $this->provisionTenant();
        [$connection, $call] = $this->inTenant($t['org'], function () use ($t) {
            return $this->seedCall($t, e164: '+12035552006', callSid: 'CA55555555555555555555555555555555');
        });

        $this->post($this->recordingUrl($t['org']->id, $connection->id), [
            'CallSid' => 'CA55555555555555555555555555555555',
            'RecordingSid' => 'RE66666666666666666666666666666666',
            'RecordingStatus' => 'completed',
            'RecordingSource' => 'RecordVerb',
        ], [
            'X-Twilio-Signature' => 'not-a-real-signature',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This request was not from Twilio.');

        $this->inTenant($t['org'], function () use ($call) {
            $row = PhoneCall::query()->find($call->id);
            $this->assertNull($row->recording_sid);
            $this->assertFalse($row->voicemail);
            $this->assertSame(0, AutomationEvent::query()->count());
        });
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: PhoneConnection, 1: PhoneCall, 2: string}
     */
    private function seedCall(array $tenant, string $e164, string $callSid, string $status = 'completed'): array
    {
        $authToken = 'recording-auth-'.$e164;
        $connection = PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'auth_token' => $authToken,
        ]);
        $number = PhoneNumber::factory()->create([
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'e164' => $e164,
        ]);
        $call = PhoneCall::create([
            'connection_id' => $connection->id,
            'number_id' => $number->id,
            'twilio_sid' => $callSid,
            'direction' => 'inbound',
            'from_e164' => '+15551110000',
            'to_e164' => $e164,
            'status' => $status,
        ]);

        return [$connection, $call, $authToken];
    }

    /** @param  array<string, string>  $params */
    private function signedPost(string $url, array $params, string $authToken): \Illuminate\Testing\TestResponse
    {
        $signature = (new RequestValidator($authToken))->computeSignature($url, $params);

        return $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ]);
    }

    private function recordingUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/recording/'.$organizationId.'/'.$connectionId);
    }

    private function transcriptionUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/transcription/'.$organizationId.'/'.$connectionId);
    }
}
