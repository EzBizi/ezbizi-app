<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Twilio\Security\RequestValidator;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Which of the people it rang actually picked up.
 *
 * ── The report ───────────────────────────────────────────────────
 *
 * Jose asked to see who answered a call in Recent calls. Nothing anywhere
 * knew: `user_id` is who PLACED a call and is null on every inbound one, and
 * Twilio's dial callback reports that the dial completed without naming the
 * leg that won.
 *
 * `<Client url>` is the mechanism that does — fetched on the answering leg and
 * no other — so the tests below come in two halves: the dial asks each client
 * to report, and the report writes the person down.
 */
class PhoneAnsweredByTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const AUTH_TOKEN = 'answered-by-auth-token';

    /** @return array{0: array<string, mixed>, 1: PhoneNumber, 2: \App\Models\User} */
    private function lineRinging(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view']);

        $number = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => self::AUTH_TOKEN,
            ]);

            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $desk->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);

            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$desk->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550117',
                'ring_group_id' => $group->id,
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return $number;
        });

        return [$t, $number, $desk];
    }

    private function callArriving(array $t, PhoneNumber $number, string $sid, ?string $answeredBy = null): PhoneCall
    {
        return $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => $sid,
            'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => '+13055550100',
            'to_e164' => $number->e164,
            'status' => PhoneCall::STATUS_COMPLETED,
            'answered_by' => $answeredBy,
        ]));
    }

    /**
     * The callback as Twilio would send it: signed, or it is a 403.
     *
     * @param  array<string, string>  $params
     */
    private function reportAnswer(PhoneNumber $number, string $identity, array $params): \Illuminate\Testing\TestResponse
    {
        $url = url('/api/v1/phone/answered/'.$number->organization_id
            .'/'.$number->connection_id.'/'.$identity);

        return $this->post($url, $params, [
            'X-Twilio-Signature' => (new RequestValidator(self::AUTH_TOKEN))
                ->computeSignature($url, $params),
        ]);
    }

    /** @return array<string, mixed> the one row the history returns */
    private function firstRow($desk): array
    {
        return $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls')
            ->assertOk()
            ->json('data')[0];
    }

    /**
     * The dial asks whoever answers to say so.
     *
     * Without the `url`, the leg that picks up is indistinguishable from the
     * ones that did not, and no later callback names it.
     */
    public function test_each_rung_client_carries_a_url_that_names_it(): void
    {
        [$t, $number, $desk] = $this->lineRinging();

        $xml = $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound($number->fresh(), []));

        $this->assertStringContainsString('<Client', $xml);
        $this->assertStringContainsString(
            '/phone/answered/',
            $xml,
            'nothing on this dial can report who answered it',
        );
        $this->assertStringContainsString(ClientIdentity::of($desk), $xml);
    }

    /**
     * The report writes the person down, on the call they answered.
     *
     * Driven through the route rather than the controller, because the path
     * carries the identity and a route that took it in a different position
     * would record the wrong person while a unit test still passed.
     */
    public function test_answering_records_who_answered(): void
    {
        [$t, $number, $desk] = $this->lineRinging();
        $call = $this->callArriving($t, $number, 'CAparent0000000000000000000000001');

        $this->reportAnswer($number, ClientIdentity::of($desk), [
            'ParentCallSid' => 'CAparent0000000000000000000000001',
            'CallSid' => 'CAchild00000000000000000000000001',
        ])->assertOk();

        $this->assertSame(
            (string) $desk->id,
            (string) $this->inTenant($t['org'], fn () => $call->fresh()->answered_by),
            'the call still says nobody answered it',
        );
    }

    /**
     * And the history says the name, not the id.
     *
     * The id is what the column holds; a person reading Recent calls needs the
     * person. This is the assertion that fails if the resolver is wired to the
     * identity key rather than the plain one.
     */
    public function test_the_history_names_whoever_answered(): void
    {
        [$t, $number, $desk] = $this->lineRinging();
        $this->callArriving($t, $number, 'CA'.bin2hex(random_bytes(16)), (string) $desk->id);

        $row = $this->firstRow($desk);

        $this->assertSame('Adeline Ruiz', $row['answered_by_name']);
        $this->assertNotNull($row['created_at'], 'a call with no time cannot be placed in the day');
    }

    /**
     * A call nobody answered says so, rather than naming somebody.
     *
     * The control. A resolver that fell back to any name would pass every test
     * above and label every missed call with whoever sorted first.
     */
    public function test_an_unanswered_call_names_nobody(): void
    {
        [$t, $number, $desk] = $this->lineRinging();
        $this->callArriving($t, $number, 'CA'.bin2hex(random_bytes(16)));

        $row = $this->firstRow($desk);

        $this->assertNull($row['answered_by']);
        $this->assertNull($row['answered_by_name']);
    }

    /** The first person to pick up keeps the credit. */
    public function test_a_second_report_does_not_rewrite_the_first(): void
    {
        [$t, $number, $desk] = $this->lineRinging();
        $other = $this->userWithRole($t, 'Someone Else', ['phone.view']);
        $call = $this->callArriving($t, $number, 'CAparent0000000000000000000000002');

        foreach ([$desk, $other] as $who) {
            $this->reportAnswer($number, ClientIdentity::of($who), [
                'ParentCallSid' => 'CAparent0000000000000000000000002',
            ])->assertOk();
        }

        $this->assertSame(
            (string) $desk->id,
            (string) $this->inTenant($t['org'], fn () => $call->fresh()->answered_by),
        );
    }

    /** An identity naming nobody writes nothing rather than a stray string. */
    public function test_an_identity_that_names_nobody_is_not_recorded(): void
    {
        [$t, $number] = $this->lineRinging();
        $call = $this->callArriving($t, $number, 'CAparent0000000000000000000000003');

        $this->reportAnswer($number, str_repeat('f', 32), [
            'ParentCallSid' => 'CAparent0000000000000000000000003',
        ])->assertOk();

        $this->assertNull($this->inTenant($t['org'], fn () => $call->fresh()->answered_by));
    }

    /**
     * An identity carrying a device still names the person.
     *
     * Every identity a live client answers on has one, so a lookup that only
     * understood the bare form would record nobody in production while every
     * test above passed.
     */
    public function test_a_device_identity_still_names_the_person(): void
    {
        [$t, $number, $desk] = $this->lineRinging();
        $call = $this->callArriving($t, $number, 'CAparent0000000000000000000000004');

        $this->reportAnswer($number, ClientIdentity::of($desk, 'aaaa1111'), [
            'ParentCallSid' => 'CAparent0000000000000000000000004',
        ])->assertOk();

        $this->assertSame(
            (string) $desk->id,
            (string) $this->inTenant($t['org'], fn () => $call->fresh()->answered_by),
        );
    }
}
