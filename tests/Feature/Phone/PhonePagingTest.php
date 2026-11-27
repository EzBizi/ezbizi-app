<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\User;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Speaking to a whole team's handsets at once.
 *
 * ── What a page is ───────────────────────────────────────────────
 *
 * One of the RingCentral features Jose named. One person talks, the group
 * hears it, nobody answers anything — the intercom, not a conference call.
 *
 * ── What is asserted ─────────────────────────────────────────────
 *
 * The TwiML each handset is given, because that is the whole behaviour: a
 * listener that is not muted turns twelve phones into twelve open
 * microphones, and a page that does not end when the speaker hangs up leaves
 * every one of them in a silent conference until Twilio times it out.
 */
class PhonePagingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private FakeTwilioGateway $twilio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->twilio = new FakeTwilioGateway;
        $this->app->instance(TwilioGateway::class, $this->twilio);
    }

    /**
     * Every available handset is called, muted, into one conference.
     */
    public function test_a_page_calls_the_team_muted_into_one_conference(): void
    {
        [$t, , , $pager, $group] = $this->team();

        $response = $this->signedInAs($pager)
            ->postJson('/api/v1/phone/page', ['ring_group_id' => $group->id])
            ->assertOk();

        $created = $this->created();

        $this->assertCount(1, $created, 'the colleague was not paged, or was paged twice');

        [, , $to, $twiml] = $created[0];

        $this->assertStringStartsWith('client:', $to);
        $this->assertStringContainsString('<Conference', $twiml);
        $this->assertStringContainsString('muted="true"', $twiml);

        // The listener's arrival must not begin the page — otherwise whoever
        // is called first sits in a live conference hearing the others being
        // connected one at a time.
        $this->assertStringContainsString('startConferenceOnEnter="false"', $twiml);

        // Silence rather than Twilio's hold music. A handset that starts
        // playing music because somebody is about to speak is worse than one
        // that stays quiet.
        $this->assertStringContainsString('waitUrl=""', $twiml);

        // And the speaker is handed a destination rather than being called:
        // their microphone is at their desk, not on the server.
        $response->assertJsonPath('data.devices', 1);
        $this->assertStringStartsWith(
            'page:ezbizi-page-'.$group->id,
            $response->json('data.speak_to'),
        );
    }

    /**
     * Speaking ends the page, and the speaker is not paged.
     *
     * `endConferenceOnExit` on the speaker's leg is what makes hanging up
     * finish an announcement. Without it every handset stays in a silent
     * conference. And paging yourself plays your own voice back a half-second
     * late, which is unusable — so the pager is never in their own page.
     */
    public function test_the_speaker_ends_the_page_and_is_not_in_it(): void
    {
        [$t, , $number, $pager, $group] = $this->team();

        $this->signedInAs($pager)
            ->postJson('/api/v1/phone/page', ['ring_group_id' => $group->id])
            ->assertOk();

        foreach ($this->created() as $call) {
            $this->assertStringNotContainsString(
                str_replace('-', '', (string) $pager->id),
                $call[3],
                'the person paging was paged',
            );
        }

        // The speaker's own leg, rendered when the desk dials the destination.
        $xml = $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound(
            $number->fresh(),
            [
                'outbound' => true,
                'dial_to' => 'page:ezbizi-page-test',
                'caller_id' => $number->e164,
            ],
        ));

        $this->assertStringContainsString('<Conference', $xml);
        $this->assertStringContainsString('endConferenceOnExit="true"', $xml);
        $this->assertStringContainsString('startConferenceOnEnter="true"', $xml);
        $this->assertStringNotContainsString('muted="true"', $xml);
    }

    /**
     * Somebody mid-call is skipped, and NAMED.
     *
     * The case that can distinguish. Paging into a live call plays the
     * announcement into somebody's ear while a customer is talking, and the
     * customer hears half of it. Silently dropping them is nearly as bad in
     * the other direction: the person paging walks away believing the room
     * heard them.
     */
    public function test_somebody_on_a_call_is_skipped_and_named(): void
    {
        [$t, $connection, , $pager, $group, $mate] = $this->team();

        $this->inTenant($t['org'], fn () => PhonePresence::query()
            ->where('user_id', $mate->id)
            ->update(['state' => PhonePresence::STATE_ON_CALL]));

        $this->signedInAs($pager)
            ->postJson('/api/v1/phone/page', ['ring_group_id' => $group->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Nobody in that group has a device to page right now.');

        $this->assertSame(
            [],
            $this->created(),
            'a page was played into a live call',
        );
    }

    /** @return array{0: array<string,mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: User, 4: PhoneRingGroup, 5: User} */
    private function team(): array
    {
        $t = $this->provisionTenant();
        $pager = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view']);
        $mate = $this->userWithRole($t, 'Bruno Vega', ['phone.view']);

        [$connection, $number, $group] = $this->inTenant($t['org'], function () use ($t, $pager, $mate) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            foreach ([$pager, $mate] as $person) {
                PhonePresence::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $person->id,
                    'state' => PhonePresence::STATE_REGISTERED,
                ]);

                PhoneDeviceRegistration::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $person->id,
                    'device_id' => 'desk-'.$person->id,
                    'platform' => 'web',
                    'last_seen_at' => now(),
                ]);
            }

            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$pager->id, $mate->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
                'ring_group_id' => $group->id,
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $pager->role_id,
            ]);

            return [$connection, $number, $group];
        });

        return [$t, $connection, $number, $pager, $group, $mate];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> */
    private function created(): array
    {
        $out = [];

        foreach ($this->twilio->calls as $entry) {
            if (($entry['method'] ?? null) === 'createCall') {
                $out[] = $entry['args'];
            }
        }

        return $out;
    }
}
