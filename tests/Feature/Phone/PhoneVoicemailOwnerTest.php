<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\PhoneVoicemailBox;
use App\Models\User;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Jose, 2026-10-05: "voicemail settings for each queue, each user". A box
 * could belong to a ring group or a person and no call ever reached it.
 */
class PhoneVoicemailOwnerTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const CALLBACK = 'https://app.test/api/v1/phone/recording/o/c';

    /**
     * ONE ROUND, THEN VOICEMAIL. A line set to one person rang them again on
     * every return of the dial action and never reached voicemail.
     */
    public function test_a_person_who_does_not_answer_is_not_rung_again(): void
    {
        [$t, $number] = $this->line();

        $xml = $this->afterNoAnswer($t, $number);

        $this->assertStringNotContainsString('<Dial', $xml, 'the person was rung again');
        $this->assertStringContainsString('<Record', $xml);
    }

    /** The person rides the dial action, so the next hop still knows them. */
    public function test_ringing_one_person_carries_them_on_the_dial_action(): void
    {
        [$t, $number, $me] = $this->line();

        $xml = $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound($number->fresh(), [
            'dial_action' => 'https://app.test/api/v1/phone/voice/o/c?batch=1',
        ]));

        $this->assertStringContainsString('person='.$me->id, html_entity_decode($xml));
    }

    public function test_the_persons_own_box_takes_the_message(): void
    {
        [$t, $number, $me] = $this->line();
        $box = $this->box($t, $number, PhoneVoicemailBox::OWNER_USER, $me->id, 'Adeline is away.');

        $xml = $this->afterNoAnswer($t, $number);

        $this->assertStringContainsString('Adeline is away.', $xml);
        $this->assertStringContainsString('box='.$box->id, $xml);
    }

    public function test_a_groups_own_box_takes_the_message_when_nobody_in_it_answers(): void
    {
        [$t, $number, , $group] = $this->line(onePerson: false);
        $this->box($t, $number, PhoneVoicemailBox::OWNER_RING_GROUP, $group->id, 'The front desk is busy.');

        $this->assertStringContainsString('The front desk is busy.', $this->afterNoAnswer($t, $number));
    }

    /** The control: with no box of their own, the line answers as before. */
    public function test_without_an_owners_box_the_line_answers_as_before(): void
    {
        [$t, $number] = $this->line();

        $xml = $this->afterNoAnswer($t, $number);

        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringNotContainsString('box=', $xml);
    }

    /** A message left in a group's or person's box is listed under that box. */
    public function test_the_message_is_listed_under_the_box_it_was_left_in(): void
    {
        [$t, $number, $me] = $this->line();
        $box = $this->box($t, $number, PhoneVoicemailBox::OWNER_USER, $me->id, 'Away.');
        $reader = $this->userWithRole($t, 'Office', ['phone.view']);

        $call = $this->inTenant($t['org'], function () use ($t, $number, $reader) {
            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $reader->role_id,
            ]);
            $number->connection->forceFill(['auth_token' => 'rec-token'])->save();

            return PhoneCall::query()->create([
                'connection_id' => $number->connection_id,
                'number_id' => $number->id,
                'twilio_sid' => 'CAboxboxboxboxboxboxboxboxboxboxbo',
                'direction' => PhoneCall::DIRECTION_INBOUND,
                'from_e164' => '+13055550100',
                'to_e164' => $number->e164,
                'status' => PhoneCall::STATUS_NO_ANSWER,
            ]);
        });

        $url = url('/api/v1/phone/recording/'.$t['org']->id.'/'.$number->connection_id).'?box='.$box->id;
        $params = [
            'CallSid' => $call->twilio_sid,
            'RecordingSid' => 'RE'.str_repeat('a', 32),
            'RecordingStatus' => 'completed',
            'RecordingSource' => 'RecordVerb',
        ];
        $this->post($url, $params, [
            'X-Twilio-Signature' => (new RequestValidator('rec-token'))->computeSignature($url, $params),
        ])->assertOk();

        $this->assertSame($box->id, $this->inTenant($t['org'], fn () => $call->fresh()->voicemail_box_id));

        $listed = $this->signedInAs($reader)
            ->getJson('/api/v1/phone/calls?box_id='.$box->id)
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $listed);
    }

    private function afterNoAnswer(array $t, PhoneNumber $number): string
    {
        return $this->inTenant($t['org'], fn () => (new TwimlRenderer)->afterDial($number->fresh(), [
            'batch' => 1,
            'dial_status' => 'no-answer',
            'recording_status_callback' => self::CALLBACK,
        ]));
    }

    private function box(array $t, PhoneNumber $number, string $type, string $id, string $greeting): PhoneVoicemailBox
    {
        return $this->inTenant($t['org'], fn () => PhoneVoicemailBox::query()->create([
            'connection_id' => $number->connection_id,
            'name' => 'Theirs',
            'owner_type' => $type,
            'owner_id' => $id,
            'greeting_mode' => 'tts',
            'greeting_text' => $greeting,
        ]));
    }

    /** @return array{0: array<string, mixed>, 1: PhoneNumber, 2: User, 3: PhoneRingGroup} */
    private function line(bool $onePerson = true): array
    {
        $t = $this->provisionTenant();
        $me = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view']);

        [$number, $group] = $this->inTenant($t['org'], function () use ($t, $me, $onePerson) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $me->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);
            PhoneDeviceRegistration::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'user_id' => $me->id,
                'device_id' => 'desk',
                'platform' => 'web',
                'last_seen_at' => now(),
            ]);
            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$me->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);
            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550117',
                'ring_group_id' => $group->id,
                'answer_with' => $onePerson ? PhoneNumber::ANSWER_USER : null,
                'answer_user_id' => $onePerson ? $me->id : null,
            ]);

            return [$number, $group];
        });

        return [$t, $number, $me, $group];
    }
}
