<?php

namespace Tests\Feature\Phone;

use App\Models\AutomationEvent;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneFlow;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use DOMDocument;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;
use App\Models\PhoneExtension;

/**
 * Public Voice / status webhooks write call rows and raise automations (Task 10).
 *
 * Signature is still the gate. A valid inbound inserts a ringing PhoneCall,
 * returns TwiML, and emits phone.call.started in the same transaction.
 */
class VoiceWebhookTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * A browser call that Twilio describes as inbound is still outbound.
     *
     * Direction arrives as "inbound" for a browser-originated call — from
     * Twilio's side the client IS calling in — so detection rested entirely on
     * From beginning with "client:". When that inference did not hold, an
     * outbound call was treated as inbound and answered by the line's own
     * voicemail: somebody pressed Call and was invited to leave themselves a
     * message.
     *
     * The client now states the direction outright. This posts the payload
     * shape that broke it — Direction inbound, From NOT a client: identity —
     * and asserts the call dials rather than records.
     */
    public function test_the_client_saying_outbound_beats_what_twilio_calls_the_call(): void
    {
        $t = $this->provisionTenant();

        [$connection, $authToken] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, , $authToken] = $this->openLine($t, e164: '+12035551008');

            return [$connection, $authToken];
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);

        $xml = $this->signedPost($url, [
            'CallSid' => 'CA33333333333333333333333333333333',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',

            // Exactly the shape the old inference got wrong.
            'Direction' => 'inbound',
            'From' => '+12035551008',
            'To' => '+13057242199',
            'CallerId' => '+12035551008',
            'EzBiziDirection' => 'outbound',
            'CallStatus' => 'ringing',
        ], $authToken)->assertOk()->getContent();

        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString('+13057242199', $xml);

        // The symptom that was reported: the caller's own line answering them.
        $this->assertStringNotContainsString('<Record', $xml);
        $this->assertStringNotContainsString('leave a message', $xml);
    }

    /**
     * A person calls out from the number assigned to them on Team.
     *
     * Before this, the caller ID was whatever the Call tab had selected — and
     * nobody ever changed it, so every outbound call in the business went out
     * under the first line on the account. Four people ringing four customers
     * all showed the same number, and every callback landed on whoever
     * answered that one line.
     *
     * The client normally sends its selection and that is honoured. This
     * covers the case that actually fails: a call arriving with NO usable
     * caller ID. Without the assignment it goes out as `client:<identity>`,
     * which is not a number the account owns, so the carrier rejects it — a
     * call that never connects and never says why.
     */
    public function test_an_outbound_call_uses_the_number_assigned_to_the_caller(): void
    {
        $t = $this->provisionTenant();

        [$connection, $authToken, $identity] = $this->inTenant($t['org'], function () use ($t) {
            // Two lines, so "the assigned one" is a real choice rather than
            // the only thing it could have picked.
            [$connection, , $authToken, $identity] = $this->openLine($t, e164: '+12035551020');

            $mine = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035559999',
            ]);

            $caller = User::query()->get()->first(
                fn (User $user) => ClientIdentity::of($user) === $identity,
            );

            PhoneExtension::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'user_id' => $caller->id,
                'number_id' => $mine->id,
            ]);

            return [$connection, $authToken, $identity];
        });

        $xml = $this->signedPost($this->voiceUrl($t['org']->id, $connection->id), [
            'CallSid' => 'CA55555555555555555555555555555555',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Direction' => 'inbound',
            'From' => 'client:'.$identity,
            'To' => '+13057242199',
            'EzBiziDirection' => 'outbound',
            'CallStatus' => 'ringing',
        ], $authToken)->assertOk()->getContent();

        $this->assertStringContainsString('+13057242199', $xml);
        $this->assertStringContainsString(
            'callerId="+12035559999"',
            $xml,
            'the call went out under some other line than the one assigned',
        );

        // Not the account's first line, which is what it used to fall to.
        $this->assertStringNotContainsString('callerId="+12035551020"', $xml);

        // The row names who placed it: without that the hang-up "what was this
        // call about?" found no call, and no call was ever tagged (2026-11-02).
        $this->inTenant($t['org'], fn () => $this->assertSame(
            ClientIdentity::uuidOf($identity),
            PhoneCall::query()->where('twilio_sid', 'CA55555555555555555555555555555555')->value('user_id'),
        ));
    }

    /**
     * Somebody with no assignment must not borrow a colleague's number.
     *
     * Picking "some line on the account" would put one person's calls out
     * under another person's number, and the customer rings back the wrong
     * colleague — worse than a call that does not connect, because it looks
     * like it worked.
     */
    public function test_an_unassigned_caller_does_not_borrow_somebody_elses_number(): void
    {
        $t = $this->provisionTenant();

        [$connection, $authToken, $identities] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, , $authToken, , $identities] = $this->openLine(
                $t, e164: '+12035551030', desks: 2,
            );

            $mine = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035558888',
            ]);

            // Assigned to the FIRST desk only.
            $assigned = User::query()->get()->first(
                fn (User $user) => ClientIdentity::of($user) === $identities[0],
            );

            PhoneExtension::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'user_id' => $assigned->id,
                'number_id' => $mine->id,
            ]);

            return [$connection, $authToken, $identities];
        });

        // The SECOND desk calls out, holding no assignment of their own.
        $xml = $this->signedPost($this->voiceUrl($t['org']->id, $connection->id), [
            'CallSid' => 'CA66666666666666666666666666666666',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Direction' => 'inbound',
            'From' => 'client:'.$identities[1],
            'To' => '+13057242199',
            'EzBiziDirection' => 'outbound',
            'CallStatus' => 'ringing',
        ], $authToken)->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'callerId="+12035558888"',
            $xml,
            "an unassigned caller went out under a colleague's number",
        );
    }

    /**
     * Ringing a customer must not end in your own voicemail.
     *
     * ── The bug Jose reported twice, reproduced end to end ───────────
     *
     * Press Call, hear "This call may be recorded" — correct, that is the
     * outbound TwiML — then hear "please leave a message after the tone" in
     * your own greeting. Both fixes before this one touched the FIRST request,
     * which was already right, so the symptom survived them both.
     *
     * The second request is where it went wrong. Twilio does not resend custom
     * parameters to a `<Dial>` action URL, so the `EzBiziDirection` the client
     * sent is gone, and `afterDial()` called `ringOrVoicemail()` for every
     * call regardless of direction — which on an outbound leg means the
     * caller's OWN line answers them.
     *
     * This drives the real two-request exchange rather than asserting on
     * afterDial() directly, because the thing that was broken is what Twilio
     * is told to come back to, and a unit test on the renderer would have
     * passed against the bug.
     */
    public function test_an_outbound_call_that_is_not_answered_does_not_reach_your_own_voicemail(): void
    {
        $t = $this->provisionTenant();

        [$connection, $authToken, $identity] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, , $authToken, $identity] = $this->openLine($t, e164: '+12035551040');

            return [$connection, $authToken, $identity];
        });

        $first = $this->signedPost($this->voiceUrl($t['org']->id, $connection->id), [
            'CallSid' => 'CA77777777777777777777777777777777',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Direction' => 'inbound',
            'From' => 'client:'.$identity,
            'To' => '+13057242199',
            'CallerId' => '+12035551040',
            'EzBiziDirection' => 'outbound',
            'CallStatus' => 'ringing',
        ], $authToken)->assertOk()->getContent();

        // Twilio comes back to whatever WE put in the action attribute, so the
        // test follows it rather than assuming the shape.
        $this->assertMatchesRegularExpression('/action="([^"]+)"/', $first);
        preg_match('/action="([^"]+)"/', $first, $matches);
        $action = html_entity_decode($matches[1]);

        $this->assertStringContainsString(
            'direction=outbound',
            $action,
            'the action URL does not carry the direction, so the second '
                .'request has no way to know this call was outbound',
        );

        // The second request, exactly as Twilio sends it: its own parameters
        // only, and NO EzBiziDirection.
        $second = $this->signedPost($action, [
            'CallSid' => 'CA77777777777777777777777777777777',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => 'client:'.$identity,
            'To' => '+13057242199',
            'CallStatus' => 'in-progress',
            'DialCallStatus' => 'no-answer',
        ], $authToken)->assertOk()->getContent();

        $this->assertStringNotContainsString('<Record', $second,
            'the caller was put through to voicemail on their own outgoing call');
        $this->assertStringNotContainsString('leave a message', $second);
        $this->assertStringContainsString('There was no answer', $second);
    }

    /** An INBOUND call that nobody answers still takes a message, as before. */
    public function test_an_inbound_call_that_is_not_answered_still_reaches_voicemail(): void
    {
        $t = $this->provisionTenant();

        [$connection, $authToken] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, , $authToken] = $this->openLine($t, e164: '+12035551050');

            return [$connection, $authToken];
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id).'?batch=1';

        $xml = $this->signedPost($url, [
            'CallSid' => 'CA88888888888888888888888888888888',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Direction' => 'inbound',
            'From' => '+15551110000',
            'To' => '+12035551050',
            'CallStatus' => 'in-progress',
            'DialCallStatus' => 'no-answer',
        ], $authToken)->assertOk()->getContent();

        $this->assertStringContainsString('<Record', $xml,
            'a customer nobody answered was not offered voicemail');
    }

    /**
     * A caller ID this account does not hold is refused before the dial.
     *
     * Twilio requires the `callerId` on an outbound `<Dial>` to be a number
     * the account owns or has verified, and refuses the moment the dial
     * starts otherwise. The refusal is not something we ever see: it reaches
     * the caller as a call that connects to nothing and explains nothing, and
     * then — before this — as their own voicemail.
     *
     * The browser's selection is checked rather than trusted for the same
     * reason anything else from a client is: the pad's choice can be stale,
     * and a line can move between accounts while somebody has the tab open.
     *
     * A-1 Tools, which has a working softphone across web, desktop and mobile,
     * validates the same thing in the same place. This was the piece EzBizi
     * did not have.
     */
    public function test_a_caller_id_this_account_does_not_hold_is_refused(): void
    {
        $t = $this->provisionTenant();

        [$connection, $authToken, $identity] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, , $authToken, $identity] = $this->openLine($t, e164: '+12035551060');

            return [$connection, $authToken, $identity];
        });

        $xml = $this->signedPost($this->voiceUrl($t['org']->id, $connection->id), [
            'CallSid' => 'CA99999999999999999999999999999999',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Direction' => 'inbound',
            'From' => 'client:'.$identity,
            'To' => '+13057242199',

            // A perfectly well-formed number that this account does not hold.
            'CallerId' => '+14045550000',
            'EzBiziDirection' => 'outbound',
            'CallStatus' => 'ringing',
        ], $authToken)->assertOk()->getContent();

        $this->assertStringNotContainsString('<Dial', $xml,
            'dialled with a caller ID Twilio would have rejected');
        $this->assertStringContainsString('not one this phone account holds', $xml);
        $this->assertStringNotContainsString('leave a message', $xml);
    }

    /**
     * A failed call records WHY, not just that it failed.
     *
     * Outbound calling was reported broken four times across two days and
     * every round was a guess, because the system threw the evidence away.
     * Twilio said why both times it could — `DialCallStatus` on the dial's
     * action request and `ErrorCode` on the status callback — and the module
     * read both and kept neither: the code only to decide whether the whole
     * LINE was broken, which a caller-ID fault is not.
     *
     * So "failed" and "nobody picked up" left identical rows. On an outbound
     * call that is the difference between "they were out" and "this could
     * never have worked", which is the entire question being asked.
     */
    public function test_a_dial_that_fails_records_why(): void
    {
        $t = $this->provisionTenant();

        [$connection, $authToken, $identity] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, , $authToken, $identity] = $this->openLine($t, e164: '+12035551070');

            return [$connection, $authToken, $identity];
        });

        $sid = 'CAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $first = $this->signedPost($this->voiceUrl($t['org']->id, $connection->id), [
            'CallSid' => $sid,
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Direction' => 'inbound',
            'From' => 'client:'.$identity,
            'To' => '+13057242199',
            'CallerId' => '+12035551070',
            'EzBiziDirection' => 'outbound',
            'CallStatus' => 'ringing',
        ], $authToken)->assertOk()->getContent();

        preg_match('/action="([^"]+)"/', $first, $matches);
        $action = html_entity_decode($matches[1]);

        $this->signedPost($action, [
            'CallSid' => $sid,
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => 'client:'.$identity,
            'To' => '+13057242199',
            'CallStatus' => 'in-progress',

            // Twilio refused it — the shape a bad caller ID takes.
            'DialCallStatus' => 'failed',
        ], $authToken)->assertOk();

        $call = $this->inTenant(
            $t['org'],
            fn () => PhoneCall::query()->where('twilio_sid', $sid)->first(),
        );

        $this->assertNotNull($call, 'the outbound call was never recorded at all');
        $this->assertSame(
            'dial:failed',
            $call->error_code,
            'a refused call is indistinguishable from one nobody answered',
        );
    }

    public function test_inbound_creates_a_call_row_and_returns_twiml(): void
    {
        $t = $this->provisionTenant();
        [$connection, $number, $authToken, $identity] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551001');
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            from: '+15551112222',
            to: '+12035551001',
        );

        $response = $this->signedPost($url, $params, $authToken);

        $response->assertOk();
        $this->assertSame('text/xml; charset=utf-8', $response->headers->get('Content-Type'));
        $xml = $response->getContent();
        $this->assertStringContainsString('<Response', $xml);
        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString('>'.$identity.'</Client>', $xml);
        $this->assertStringContainsString('answerOnBridge="true"', $xml);
        $this->assertStringNotContainsString('VoiceWebhookController', $xml);
        $this->assertStringNotContainsString('PhoneCall', $xml);

        $call = $this->inTenant($t['org'], fn () => PhoneCall::query()->first());
        $this->assertNotNull($call);
        $this->assertSame('CAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $call->twilio_sid);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame('+15551112222', $call->from_e164);
        $this->assertSame('+12035551001', $call->to_e164);
        $this->assertSame('ringing', $call->status);
        $this->assertSame($number->id, $call->number_id);
        $this->assertSame($connection->id, $call->connection_id);
    }

    /**
     * What the caller pressed is kept on the call. Jose, 2026-10-05: a report
     * of "what client pressed on the ivr menu" — the digits reached here on
     * every press and were dropped.
     */
    public function test_the_key_pressed_and_the_extension_typed_are_kept(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], fn () => $this->openLine($t, e164: '+12035551009'));

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams('CAkeyskeyskeyskeyskeyskeyskeyskeys', '+15551113333', '+12035551009');

        $this->signedPost($url, $params, $authToken)->assertOk();
        $this->signedPost($url, $params + ['Digits' => '2'], $authToken)->assertOk();

        $call = fn () => $this->inTenant($t['org'], fn () => PhoneCall::query()->firstOrFail());
        $this->assertSame('2', $call()->menu_key);
        $this->assertNull($call()->extension_dialed);

        // Typed at the extension prompt: the same field, a different URL.
        $this->signedPost($url.'?extension=1', $params + ['Digits' => '0101'], $authToken)->assertOk();
        $this->assertSame('0101', $call()->extension_dialed);
        $this->assertSame('2', $call()->menu_key, 'the extension overwrote the menu key');
    }

    public function test_a_bad_signature_is_still_forbidden_and_writes_nothing(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->inTenant($t['org'], function () use ($t) {
            [$connection] = $this->openLine($t, e164: '+12035551002');

            return $connection;
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            from: '+15551112222',
            to: '+12035551002',
        );

        $this->post($url, $params, [
            'X-Twilio-Signature' => 'not-a-real-signature',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'This request was not from Twilio.');

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, PhoneCall::query()->count());
            $this->assertSame(0, AutomationEvent::query()->count());
        });
    }

    public function test_inbound_emits_phone_call_started_in_the_outbox(): void
    {
        $t = $this->provisionTenant();
        [$connection, $number, $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551003');
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAcccccccccccccccccccccccccccccccc',
            from: '+15550001111',
            to: '+12035551003',
        );

        $this->signedPost($url, $params, $authToken)->assertOk();

        $this->inTenant($t['org'], function () use ($number) {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);

            $event = AutomationEvent::query()
                ->where('event_key', 'phone.call.started')
                ->first();
            $this->assertNotNull($event);
            $this->assertSame($call->id, $event->subject_id);
            $this->assertSame(PhoneCall::class, $event->subject_type);

            $this->assertSame(1, AutomationEvent::query()->count());
            $this->assertSame($number->id, $call->number_id);
        });
    }

    public function test_a_second_post_for_the_same_call_does_not_emit_again(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551004');
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAdddddddddddddddddddddddddddddddd',
            from: '+15550002222',
            to: '+12035551004',
        );

        $this->signedPost($url, $params, $authToken)->assertOk();
        $this->signedPost($url, $params, $authToken)->assertOk();

        $this->inTenant($t['org'], function () {
            $this->assertSame(1, PhoneCall::query()->count());
            $this->assertSame(1, AutomationEvent::query()
                ->where('event_key', 'phone.call.started')
                ->count());
        });
    }

    /**
     * A GROUP REACHED FROM A MENU KEY stays that group after its ring round.
     *
     * The key's group travelled only in the request that rang it. Twilio's
     * dial action carries no Digits, so the next hop fell back to the line's
     * own group — its overflow, its queue, its voicemail — and a caller who
     * pressed 1 for Appointments was handled as the line's default team.
     */
    public function test_a_menu_key_group_is_still_the_group_after_nobody_answers(): void
    {
        $t = $this->provisionTenant();
        [$connection, $number, $authToken] = $this->inTenant($t['org'], fn () => $this->openLine($t, e164: '+12035551011'));

        $appointments = $this->inTenant($t['org'], function () use ($connection, $number) {
            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Appointments',
                'mode' => 'simultaneous',
                'timeout_seconds' => 20,
                'member_user_ids' => PhoneRingGroup::query()->find($number->ring_group_id)->member_user_ids,
                'member_role_ids' => [],
                'overflow' => 'forward',
                'overflow_number' => '+15550009999',
            ]);
            $flow = \App\Models\PhoneFlow::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Main menu',
                'nodes' => ['type' => 'menu', 'prompt' => 'Press 1.', 'options' => [
                    ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                ]],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return $group;
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams('CAgrpsgrpsgrpsgrpsgrpsgrpsgrpsgrps', '+15550004444', '+12035551011');

        $this->signedPost($url, $params, $authToken)->assertOk();
        $rang = $this->signedPost($url, $params + ['Digits' => '1'], $authToken)->assertOk()->getContent();

        // The ring round is followed where its action says, with no Digits.
        $next = $this->signedPost($this->dialAction($rang), $params + ['DialCallStatus' => 'no-answer'], $authToken)
            ->assertOk()->getContent();

        $this->assertStringContainsString('+15550009999', $next, 'Appointments overflow was not followed');
        $this->assertNotNull($appointments);
    }

    public function test_dial_no_answer_requests_the_next_batch(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken, , $identities] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551005', desks: 11);
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            from: '+15550003333',
            to: '+12035551005',
        );

        $first = $this->signedPost($url, $params, $authToken);
        $first->assertOk();
        $xml = $first->getContent();

        $this->assertSame(10, $this->clientCount($xml));
        $this->assertStringContainsString('>'.$identities[0].'</Client>', $xml);
        $this->assertStringNotContainsString('>'.$identities[10].'</Client>', $xml);

        $action = $this->dialAction($xml);
        $this->assertNotSame('', $action);
        $this->assertStringContainsString('batch=', $action);

        $second = $this->signedPost($action, $params + [
            'DialCallStatus' => 'no-answer',
        ], $authToken);
        $second->assertOk();
        $next = $second->getContent();

        $this->assertSame(1, $this->clientCount($next));
        $this->assertStringContainsString('>'.$identities[10].'</Client>', $next);
        $this->assertStringNotContainsString('Please leave a message after the tone.', $next);
    }

    public function test_dial_no_answer_after_the_last_batch_goes_to_voicemail_and_is_missed(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551006');
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CAffffffffffffffffffffffffffffffff',
            from: '+15550004444',
            to: '+12035551006',
        );

        $first = $this->signedPost($url, $params, $authToken);
        $first->assertOk();
        $action = $this->dialAction($first->getContent());
        $this->assertNotSame('', $action, 'The first Dial must ask Twilio for the next step.');

        $this->signedPost($action, $params + [
            'DialCallStatus' => 'no-answer',
        ], $authToken)
            ->assertOk()
            ->assertSee('Please leave a message after the tone.', false)
            ->assertSee('<Record', false);

        $this->inTenant($t['org'], function () {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame('no-answer', $call->status);
            $this->assertTrue($call->voicemail);

            $keys = AutomationEvent::query()->pluck('event_key')->all();
            $this->assertEqualsCanonicalizing(['phone.call.started', 'phone.call.missed'], $keys);
        });
    }

    public function test_zero_available_inbound_goes_to_voicemail_and_is_missed(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551009', desks: 0);
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CA55555555555555555555555555555555',
            from: '+15550008888',
            to: '+12035551009',
        );

        $this->signedPost($url, $params, $authToken)
            ->assertOk()
            ->assertSee('Please leave a message after the tone.', false)
            ->assertSee('<Record', false)
            ->assertDontSee('<Dial', false);

        $this->inTenant($t['org'], function () {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame('no-answer', $call->status);
            $this->assertTrue($call->voicemail);

            $keys = AutomationEvent::query()->pluck('event_key')->all();
            $this->assertEqualsCanonicalizing(['phone.call.started', 'phone.call.missed'], $keys);
        });
    }

    public function test_status_completed_after_voicemail_keeps_the_miss(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551010', desks: 0);
        });

        $params = $this->inboundParams(
            callSid: 'CA66666666666666666666666666666666',
            from: '+15550009999',
            to: '+12035551010',
        );
        $this->signedPost($this->voiceUrl($t['org']->id, $connection->id), $params, $authToken)
            ->assertOk();

        $this->signedPost($this->statusUrl($t['org']->id, $connection->id), array_merge($params, [
            'CallStatus' => 'completed',
            'CallDuration' => '18',
        ]), $authToken)->assertOk();

        $this->inTenant($t['org'], function () {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame('no-answer', $call->status);
            $this->assertTrue($call->voicemail);
            $this->assertSame(18, $call->duration_seconds);

            $keys = AutomationEvent::query()->pluck('event_key')->all();
            $this->assertContains('phone.call.started', $keys);
            $this->assertContains('phone.call.missed', $keys);
            $this->assertContains('phone.call.ended', $keys);
            $this->assertSame(1, AutomationEvent::query()
                ->where('event_key', 'phone.call.missed')
                ->count());
            $this->assertSame(1, AutomationEvent::query()
                ->where('event_key', 'phone.call.ended')
                ->count());
        });
    }

    public function test_status_completed_stores_duration_and_emits_ended(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551007');
        });

        $voice = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CA11111111111111111111111111111111',
            from: '+15550005555',
            to: '+12035551007',
        );
        $this->signedPost($voice, $params, $authToken)->assertOk();

        $status = $this->statusUrl($t['org']->id, $connection->id);
        $this->signedPost($status, array_merge($params, [
            'CallStatus' => 'completed',
            'CallDuration' => '42',
        ]), $authToken)->assertOk();

        $this->inTenant($t['org'], function () {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame('completed', $call->status);
            $this->assertSame(42, $call->duration_seconds);

            $keys = AutomationEvent::query()->orderBy('occurred_at')->pluck('event_key')->all();
            $this->assertContains('phone.call.started', $keys);
            $this->assertContains('phone.call.ended', $keys);
            $this->assertSame(1, AutomationEvent::query()
                ->where('event_key', 'phone.call.ended')
                ->count());
        });
    }

    public function test_terminal_status_survives_delayed_callbacks_and_duplicate_completion(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            return $this->openLine($t, e164: '+12035551007');
        });

        $voice = $this->voiceUrl($t['org']->id, $connection->id);
        $params = $this->inboundParams(
            callSid: 'CA11111111111111111111111111111111',
            from: '+15550005555',
            to: '+12035551007',
        );
        $this->signedPost($voice, $params, $authToken)->assertOk();

        $status = $this->statusUrl($t['org']->id, $connection->id);
        $this->signedPost($status, array_merge($params, [
            'CallStatus' => 'completed',
            'CallDuration' => '42',
        ]), $authToken)->assertOk();

        foreach (['ringing', 'in-progress', 'busy', 'completed'] as $late) {
            $this->signedPost($status, array_merge($params, [
                'CallStatus' => $late,
                'CallDuration' => '0',
            ]), $authToken)->assertOk();
        }

        $this->inTenant($t['org'], function () {
            $call = PhoneCall::query()->first();
            $this->assertNotNull($call);
            $this->assertSame('completed', $call->status);
            $this->assertSame(42, $call->duration_seconds);

            $keys = AutomationEvent::query()->orderBy('occurred_at')->pluck('event_key')->all();
            $this->assertContains('phone.call.started', $keys);
            $this->assertContains('phone.call.ended', $keys);
            $this->assertSame(1, AutomationEvent::query()
                ->where('event_key', 'phone.call.ended')
                ->count());
        });
    }

    public function test_outbound_from_the_twiml_app_skips_hours_and_ivr(): void
    {
        $t = $this->provisionTenant();
        [$connection, , $authToken] = $this->inTenant($t['org'], function () use ($t) {
            [$connection, $number, $authToken, $identity] = $this->openLine($t, e164: '+12035551008');
            $schedule = PhoneHoursSchedule::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Never open',
                'timezone' => 'America/New_York',
                'weeks' => [],
                'exceptions' => null,
            ]);
            $flow = PhoneFlow::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk.',
                ],
            ]);
            $number->forceFill([
                'hours_schedule_id' => $schedule->id,
                'flow_id' => $flow->id,
                'after_hours' => 'voicemail',
            ])->save();

            return [$connection, $number, $authToken, $identity];
        });

        $url = $this->voiceUrl($t['org']->id, $connection->id);
        $params = [
            'CallSid' => 'CA22222222222222222222222222222222',
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => 'client:deskuser',
            'To' => '+15559876543',
            'CallerId' => '+12035551008',
            'Direction' => 'outbound-api',
            'CallStatus' => 'ringing',
        ];

        $xml = $this->signedPost($url, $params, $authToken)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Press 1 for the desk.', $xml);
        $this->assertStringNotContainsString('<Gather', $xml);
        $this->assertStringNotContainsString('This line is closed.', $xml);
        $this->assertStringNotContainsString('Please leave a message after the tone.', $xml);
        $this->assertStringContainsString('<Dial', $xml);
        // The number is dialled, and it now carries a whisper url — TwiML
        // played to the CALLEE announcing the recording, which on an outbound
        // call is the only party who never heard it. Asserted as the dialled
        // number plus the attribute rather than as exact markup, so adding a
        // second attribute later is not a test failure.
        $this->assertStringContainsString('>+15559876543</Number>', $xml);
        $this->assertStringContainsString('/phone/whisper/', $xml);

        $call = $this->inTenant($t['org'], fn () => PhoneCall::query()->first());
        $this->assertNotNull($call);
        $this->assertSame('outbound', $call->direction);
        $this->assertSame('+12035551008', $call->from_e164);
        $this->assertSame('+15559876543', $call->to_e164);
        $this->assertSame('ringing', $call->status);
    }

    public function test_calls_list_covers_every_line_in_reach(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Desk records', ['phone.view']);

        [$visible, $hidden] = $this->inTenant($t['org'], function () use ($t, $viewer) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => 'records-auth-token',
            ]);

            $granted = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551901',
                'label' => 'Granted',
            ]);
            $other = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035551902',
                'label' => 'Hidden',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $granted->id,
                'role_id' => $viewer->role_id,
            ]);

            $visible = PhoneCall::create([
                'connection_id' => $connection->id,
                'number_id' => $granted->id,
                'twilio_sid' => 'CA33333333333333333333333333333333',
                'direction' => 'inbound',
                'from_e164' => '+15550006666',
                'to_e164' => '+12035551901',
                'status' => 'completed',
                'duration_seconds' => 12,
            ]);
            $hidden = PhoneCall::create([
                'connection_id' => $connection->id,
                'number_id' => $other->id,
                'twilio_sid' => 'CA44444444444444444444444444444444',
                'direction' => 'inbound',
                'from_e164' => '+15550007777',
                'to_e164' => '+12035551902',
                'status' => 'completed',
            ]);

            return [$visible, $hidden];
        });

        $rows = $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/calls')
            ->assertOk()
            ->json('data');

        // Both calls. The second sat on a line this role had no grant for,
        // which is no longer a reason to hide anything — both lines are in
        // this company and this person stands over it.
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$visible->id, $hidden->id],
            array_column($rows, 'id'),
        );
        $this->assertContains($hidden->id, array_column($rows, 'id'));
        $this->assertArrayHasKey('customer_id', $rows[0]);
        $this->assertArrayHasKey('voicemail', $rows[0]);
        $this->assertArrayNotHasKey('credentials', $rows[0]);
        $this->assertArrayNotHasKey('auth_token', $rows[0]);
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: PhoneConnection, 1: PhoneNumber, 2: string, 3: string, 4: list<string>}
     */
    private function openLine(array $tenant, string $e164, int $desks = 1): array
    {
        $authToken = 'voice-auth-token-'.$e164;
        $connection = PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'auth_token' => $authToken,
        ]);

        $ids = [];
        $identities = [];
        for ($i = 0; $i < $desks; $i++) {
            $user = $this->deskMember($tenant, 'Desk '.$e164.' '.$i);
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $user->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);
            $ids[] = $user->id;
            $identities[] = ClientIdentity::of($user);
        }

        $group = PhoneRingGroup::query()->create([
            'connection_id' => $connection->id,
            'name' => 'Front desk',
            'mode' => 'simultaneous',
            'timeout_seconds' => 30,
            'member_user_ids' => $ids,
            'member_role_ids' => [],
            'overflow' => 'voicemail',
        ]);

        $number = PhoneNumber::factory()->create([
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'e164' => $e164,
            'ring_group_id' => $group->id,
            'record' => true,
        ]);

        return [$connection, $number, $authToken, $identities[0] ?? '', $identities];
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     */
    private function deskMember(array $tenant, string $name): User
    {
        $email = strtolower(preg_replace('/[^a-z0-9]/i', '', $name)).'@'.$tenant['org']->slug.'.test';

        $role = Role::query()->firstOrCreate(
            ['organization_id' => $tenant['org']->id, 'name' => 'Dial members'],
            ['is_system' => false],
        );

        return User::create([
            'organization_id' => $tenant['org']->id,
            'role_id' => $role->id,
            'name' => $name,
            'email' => $email,
            'password' => 'a-real-password',
            'is_active' => true,
        ]);
    }

    /** @return array<string, string> */
    private function inboundParams(string $callSid, string $from, string $to): array
    {
        return [
            'CallSid' => $callSid,
            'AccountSid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'From' => $from,
            'To' => $to,
            'Direction' => 'inbound',
            'CallStatus' => 'ringing',
        ];
    }

    /** @param  array<string, string>  $params */

    /**
     * The whisper endpoint says the line's own notice, to the callee.
     *
     * The renderer putting a `url` on `<Number>` proves only that a URL was
     * written into the document. What the callee actually hears is whatever
     * this endpoint returns, and a whisper that returns an empty document is
     * the same silence as before with a route in front of it.
     */
    public function test_the_whisper_says_the_lines_recording_notice(): void
    {
        $t = $this->provisionTenant();

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => 'whisper-token',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035558822',
                'record' => true,
                'recording_notice' => 'A-1 Chimney records its calls.',
            ]);

            return [$connection, $number];
        });

        $url = url("/api/v1/phone/whisper/{$t['org']->id}/{$connection->id}/{$number->id}");

        $response = $this->signedPost($url, [], 'whisper-token');

        $response->assertOk();
        $this->assertStringContainsString(
            'A-1 Chimney records its calls.',
            $response->getContent(),
            'the callee is bridged in silence',
        );
    }

    /**
     * And it says nothing when the line is not recording.
     *
     * The whisper is fetched from a `url` written when the call started. If
     * recording was switched off in between, announcing it anyway tells the
     * callee something untrue about the call they are on.
     */
    public function test_the_whisper_is_silent_when_the_line_does_not_record(): void
    {
        $t = $this->provisionTenant();

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => 'whisper-token-2',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035558823',
                'record' => false,
            ]);

            return [$connection, $number];
        });

        $url = url("/api/v1/phone/whisper/{$t['org']->id}/{$connection->id}/{$number->id}");

        $response = $this->signedPost($url, [], 'whisper-token-2');

        $response->assertOk();
        $this->assertStringNotContainsString('<Say', $response->getContent());
    }

    /** An unsigned request is not from Twilio, and this one is public. */
    public function test_the_whisper_refuses_an_unsigned_request(): void
    {
        $t = $this->provisionTenant();

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'auth_token' => 'whisper-token-3',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+12035558824',
                'record' => true,
            ]);

            return [$connection, $number];
        });

        $this->post(
            "/api/v1/phone/whisper/{$t['org']->id}/{$connection->id}/{$number->id}",
        )->assertForbidden();
    }

    private function signedPost(string $url, array $params, string $authToken): \Illuminate\Testing\TestResponse
    {
        $signature = (new RequestValidator($authToken))->computeSignature($url, $params);

        return $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ]);
    }

    private function voiceUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/voice/'.$organizationId.'/'.$connectionId);
    }

    private function statusUrl(string $organizationId, string $connectionId): string
    {
        return url('/api/v1/phone/status/'.$organizationId.'/'.$connectionId);
    }

    private function dialAction(string $xml): string
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $dial = $document->getElementsByTagName('Dial')->item(0);
        if (! $dial instanceof DOMElement) {
            return '';
        }

        return html_entity_decode($dial->getAttribute('action'));
    }

    private function clientCount(string $xml): int
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $count = 0;
        foreach ($document->getElementsByTagName('Client') as $client) {
            $count++;
        }

        return $count;
    }
}
