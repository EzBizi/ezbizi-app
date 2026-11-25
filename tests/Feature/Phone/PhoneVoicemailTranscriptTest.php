<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * The message somebody left, kept apart from the call it was left on.
 *
 * ── The report ───────────────────────────────────────────────────
 *
 * Jose asked why transcripts were showing up among the voicemails. They were
 * transcripts — of the whole CALL. Two different Twilio features write here:
 *
 *   <Start><Transcription>   the live conversation, one POST per utterance,
 *                            both tracks, so it includes our own greeting,
 *                            the recording notice and the IVR prompt
 *   <Record transcribe>      the message left afterwards, one POST, once
 *
 * Both were pointed at the same callback and the same column. Worse, the
 * callback required a `TranscriptionEvent` field that the recording flavour
 * does not send — so it answered 200 and dropped every voicemail
 * transcription Twilio ever produced, while the screen explained the silence
 * as Twilio failing to make the audio out.
 */
class PhoneVoicemailTranscriptTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const AUTH_TOKEN = 'voicemail-transcript-token';

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: \App\Models\User} */
    private function line(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Voicemail desk', ['phone.view']);

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => self::AUTH_TOKEN,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550115',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return [$connection, $number];
        });

        return [$t, $connection, $number, $desk];
    }

    private function callWithVoicemail(array $t, PhoneNumber $number, string $sid): PhoneCall
    {
        return $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => $sid,
            'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => '+13055550100',
            'to_e164' => $number->e164,
            'status' => PhoneCall::STATUS_NO_ANSWER,
            'voicemail' => true,
        ]));
    }

    /**
     * @param  array<string, string>  $params
     */
    private function transcriptionCallback(array $t, PhoneConnection $connection, array $params): \Illuminate\Testing\TestResponse
    {
        $url = url('/api/v1/phone/transcription/'.$t['org']->id.'/'.$connection->id);

        return $this->post($url, $params, [
            'X-Twilio-Signature' => (new RequestValidator(self::AUTH_TOKEN))
                ->computeSignature($url, $params),
        ]);
    }

    /**
     * The message Twilio wrote down is kept.
     *
     * This is the whole defect in one assertion: the callback used to answer
     * 200 and store nothing, so a green "the endpoint accepted it" proved
     * exactly nothing. The artefact is the column.
     */
    public function test_a_voicemail_transcription_is_stored(): void
    {
        [$t, $connection, $number] = $this->line();
        $call = $this->callWithVoicemail($t, $number, 'CAvoicemail00000000000000000001');

        $this->transcriptionCallback($t, $connection, [
            'CallSid' => 'CAvoicemail00000000000000000001',
            'RecordingSid' => 'RE'.str_repeat('a', 32),
            'TranscriptionSid' => 'TR'.str_repeat('b', 32),
            'TranscriptionStatus' => 'completed',
            'TranscriptionText' => 'Hi, this is Maria about the chimney inspection.',
        ])->assertOk();

        $this->assertSame(
            'Hi, this is Maria about the chimney inspection.',
            $this->inTenant($t['org'], fn () => $call->fresh()->voicemail_transcript),
            'the voicemail transcription was accepted and thrown away',
        );
    }

    /**
     * And it does not land in the live transcript, nor the live one in it.
     *
     * Two columns because one call has both. Without this, "stored somewhere"
     * passes and the Voicemail screen goes on reading the caller their own IVR
     * prompt back.
     */
    public function test_the_live_transcript_and_the_message_do_not_mix(): void
    {
        [$t, $connection, $number] = $this->line();
        $call = $this->callWithVoicemail($t, $number, 'CAvoicemail00000000000000000002');

        // The live stream, mid-call: this is the flavour that captures what WE
        // say as well as the caller.
        $this->transcriptionCallback($t, $connection, [
            'CallSid' => 'CAvoicemail00000000000000000002',
            'TranscriptionEvent' => 'transcription-content',
            'Final' => 'true',
            'TranscriptionData' => json_encode(['transcript' => 'This call may be recorded.']),
        ])->assertOk();

        // The message, afterwards.
        $this->transcriptionCallback($t, $connection, [
            'CallSid' => 'CAvoicemail00000000000000000002',
            'TranscriptionStatus' => 'completed',
            'TranscriptionText' => 'Please call me back on the mobile.',
        ])->assertOk();

        $fresh = $this->inTenant($t['org'], fn () => $call->fresh());

        $this->assertSame('This call may be recorded.', $fresh->transcript);
        $this->assertSame('Please call me back on the mobile.', $fresh->voicemail_transcript);
    }

    /**
     * Twilio giving up is not a transcript.
     *
     * `failed` arrives with empty text, and storing it would make "we could
     * not make this out" look identical to a message that said nothing.
     */
    public function test_a_failed_transcription_stores_nothing(): void
    {
        [$t, $connection, $number] = $this->line();
        $call = $this->callWithVoicemail($t, $number, 'CAvoicemail00000000000000000003');

        $this->transcriptionCallback($t, $connection, [
            'CallSid' => 'CAvoicemail00000000000000000003',
            'TranscriptionStatus' => 'failed',
            'TranscriptionText' => '',
        ])->assertOk();

        $this->assertNull($this->inTenant($t['org'], fn () => $call->fresh()->voicemail_transcript));
    }

    /** A retry writes the same message once, not twice. */
    public function test_a_repeated_callback_does_not_double_the_message(): void
    {
        [$t, $connection, $number] = $this->line();
        $call = $this->callWithVoicemail($t, $number, 'CAvoicemail00000000000000000004');

        for ($i = 0; $i < 2; $i++) {
            $this->transcriptionCallback($t, $connection, [
                'CallSid' => 'CAvoicemail00000000000000000004',
                'TranscriptionStatus' => 'completed',
                'TranscriptionText' => 'Ring me back.',
            ])->assertOk();
        }

        $this->assertSame(
            'Ring me back.',
            $this->inTenant($t['org'], fn () => $call->fresh()->voicemail_transcript),
        );
    }

    /** The screen is fed the message, and the call transcript stays its own. */
    public function test_the_api_carries_both_separately(): void
    {
        [$t, $connection, $number, $desk] = $this->line();
        $call = $this->callWithVoicemail($t, $number, 'CAvoicemail00000000000000000005');

        $this->inTenant($t['org'], fn () => $call->forceFill([
            'transcript' => 'Thank you for calling. Press one.',
            'voicemail_transcript' => 'It is Maria again.',
        ])->save());

        $row = $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls?voicemail_only=1')
            ->assertOk()
            ->json('data')[0];

        $this->assertSame('It is Maria again.', $row['voicemail_transcript']);
        $this->assertSame('Thank you for calling. Press one.', $row['transcript']);
    }
}
