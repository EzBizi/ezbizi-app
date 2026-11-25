<?php

namespace Tests\Feature\Phone;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\PhoneConnection;
use App\Models\PhoneFlow;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\RecordingNotice;
use App\Support\Phone\TwimlRenderer;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Inbound TwiML: transcription, recording notice, hours, Gather or Dial (Task 8).
 */
class TwimlRendererTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Pressing Call and hearing your own voicemail.
     *
     * The outbound branch used to require a dial target and, when it was
     * missing, fall through to ringOrVoicemail — so the caller heard the
     * recording notice and then "please leave a message after the tone",
     * which is their own line answering them. It reads exactly like a routing
     * fault and leaves nothing behind saying otherwise.
     *
     * Nobody rings out in order to leave themselves a message, so a missing
     * destination is a fault and must say so.
     */
    public function test_an_outbound_call_with_no_destination_says_so_rather_than_taking_a_message(): void
    {
        $t = $this->provisionTenant();

        [$broken, $working, $noCallerId] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);
            $renderer = new TwimlRenderer;

            return [
                // Outbound, but the destination never arrived.
                $renderer->inbound($number->fresh(), ['outbound' => true]),

                // A real outbound call carries BOTH a destination and a
                // caller ID. Twilio refuses a <Dial> whose callerId is not a
                // number the account holds, so a call without one never
                // reaches anybody — which is why the renderer declines it
                // rather than making the attempt.
                $renderer->inbound($number->fresh(), [
                    'outbound' => true,
                    'dial_to' => '+13055550107',
                    'caller_id' => $number->e164,
                ]),

                // Destination, but nothing usable to call out from.
                $renderer->inbound($number->fresh(), [
                    'outbound' => true,
                    'dial_to' => '+13055550107',
                ]),
            ];
        });

        // The failure is spoken, and nothing is recorded.
        $this->assertStringContainsString('could not be placed', $broken);
        $this->assertStringContainsString('<Hangup', $broken);
        $this->assertStringNotContainsString('<Record', $broken);
        $this->assertStringNotContainsString('leave a message', $broken);

        // And a real outbound call still dials.
        $this->assertStringContainsString('<Dial', $working);
        $this->assertStringContainsString('+13055550107', $working);
        $this->assertStringNotContainsString('<Record', $working);

        // A caller ID this account does not hold is refused BEFORE the dial.
        // Twilio would reject it the moment the dial started, and that
        // rejection reaches the caller as a call that connects to nothing and
        // explains nothing — so it is declined here, where it can say why.
        $this->assertStringNotContainsString('<Dial', $noCallerId);
        $this->assertStringContainsString('not one this phone account holds', $noCallerId);
        $this->assertStringNotContainsString('leave a message', $noCallerId);
    }

    /**
     * A team call the caller can hear ringing.
     *
     * ── The defect ───────────────────────────────────────────────────
     *
     * `answerOnBridge` holds the caller's leg unanswered until the callee
     * picks up. That is what keeps an inbound PSTN caller hearing their
     * carrier's ringing — but a BROWSER caller has no carrier, and no media
     * flows on an unanswered leg. So calling a colleague was silence: Jose
     * could not tell whether it was dialling, had failed, or had already been
     * answered.
     *
     * Asserted on the ATTRIBUTE rather than on audio, which no test here can
     * hear. `ringTone` is the only thing that puts sound in that leg before an
     * answer, so its presence is the whole claim.
     */
    public function test_an_outbound_call_carries_a_ringback_for_the_caller(): void
    {
        $t = $this->provisionTenant();

        [$toColleague, $toCustomer, $inbound] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);
            $renderer = new TwimlRenderer;

            return [
                $renderer->inbound($number->fresh(), [
                    'outbound' => true,
                    'dial_to' => 'client:someidentity',
                    'caller_id' => $number->e164,
                ]),
                $renderer->inbound($number->fresh(), [
                    'outbound' => true,
                    'dial_to' => '+13055550107',
                    'caller_id' => $number->e164,
                ]),

                // The control, and the reason this is not set in
                // dialAttributes: an inbound caller already hears their own
                // network's ringing, and overriding it would change a path
                // that works to fix one that does not.
                $renderer->inbound($number->fresh()),
            ];
        });

        $this->assertStringContainsString('ringTone="us"', $toColleague);
        $this->assertStringContainsString('<Client', $toColleague);

        // A call out to a real number gets it too — same silent leg, same
        // caller, and the PSTN side does not supply ringback to a browser.
        $this->assertStringContainsString('ringTone="us"', $toCustomer);

        $this->assertStringNotContainsString(
            'ringTone',
            $inbound,
            'an inbound call now overrides the ringing the caller already had',
        );

        // And the thing it must not have cost: the leg still waits for a real
        // answer rather than connecting the caller to a ringing phone.
        $this->assertStringContainsString('answerOnBridge="true"', $toColleague);
    }

    public function test_recording_notice_is_spoken_when_the_number_records(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);

            return (new TwimlRenderer)->inbound($number, []);
        });

        $this->assertStringContainsString(RecordingNotice::sentence(), $xml);
        $this->assertStringContainsString('This call may be recorded.', $xml);
        $this->assertStringContainsString('record-from-answer-dual', $xml);
        $this->assertStringContainsString('answerOnBridge="true"', $xml);
    }

    public function test_recording_notice_is_absent_when_the_number_does_not_record(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);

            return (new TwimlRenderer)->inbound($number, []);
        });

        $this->assertStringNotContainsString('may be recorded', $xml);
        $this->assertStringNotContainsString('record-from-answer', $xml);
    }

    /**
     * Writing down a voicemail is a different Twilio charge and a different
     * switch from writing down the live call. The setting existed on the row
     * and in the API before the renderer read it, which is the worst shape a
     * setting can be in: it saves, it shows as on, and it does nothing.
     */
    public function test_voicemail_is_transcribed_only_when_the_line_asks_for_it(): void
    {
        $t = $this->provisionTenant();

        [$off, $on] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);

            // No group, so the renderer goes to voicemail rather than Dial.
            $number->forceFill(['ring_group_id' => null])->save();

            $context = ['transcription_status_callback' => 'https://ezbizi.test/hook'];
            $renderer = new TwimlRenderer;

            $before = $renderer->inbound($number->fresh(), $context);

            $number->forceFill(['voicemail_transcribe' => true])->save();

            return [$before, $renderer->inbound($number->fresh(), $context)];
        });

        $this->assertStringContainsString('<Record', $off);
        $this->assertStringNotContainsString('transcribe="true"', $off);
        $this->assertStringNotContainsString('transcribeCallback', $off);

        $this->assertStringContainsString('transcribe="true"', $on);
        $this->assertStringContainsString('transcribeCallback="https://ezbizi.test/hook"', $on);

        // The live-call stream is a separate switch and must stay off.
        $this->assertStringNotContainsString('<Start>', $on);
    }

    /**
     * ONE SWITCH FOR THE WHOLE COMPANY, because thirteen was the same as none.
     *
     * Voicemail transcription was per line and nothing else. Measured on the
     * live account 2026-09-14: thirteen numbers, every one of them off, and 0
     * of 92 voicemails ever transcribed — not because anybody decided against
     * it, but because turning it on meant thirteen visits to thirteen screens.
     *
     * Jose: "we should be able to turn them all on or all off at the same
     * time, not per line."
     *
     * `record` and live transcription already worked this way and this flag
     * had simply been left out of the mechanism: `resolvedFlag()` looks for
     * `default_<key>` on the connection, and the voicemail branch was reading
     * the raw column instead. So the fix is one default and one resolver call.
     *
     * The line stays OFF throughout. If this test set the line's own flag it
     * would pass with the company default ignored entirely, which is the
     * defect it exists to catch.
     */
    public function test_a_company_default_transcribes_voicemail_on_every_line(): void
    {
        $t = $this->provisionTenant();

        [$off, $on, $lineFlag] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);
            $number->forceFill(['ring_group_id' => null])->save();

            $renderer = new TwimlRenderer;
            $before = $renderer->inbound($number->fresh(), []);

            $number->connection->forceFill([
                'default_voicemail_transcribe' => true,
            ])->save();

            return [
                $before,
                $renderer->inbound($number->fresh()->load('connection'), []),
                (bool) $number->fresh()->voicemail_transcribe,
            ];
        });

        $this->assertFalse(
            $lineFlag,
            'the line turned its own flag on, so this proves nothing about '
            .'the company default',
        );

        $this->assertStringNotContainsString('transcribe="true"', $off);
        $this->assertStringContainsString(
            'transcribe="true"',
            $on,
            'the company said to write voicemails down and this line did not, '
            .'so it is still thirteen switches wearing one',
        );
    }

    /**
     * The Call Flow screen reads describe(); the caller hears inbound(). If
     * those two ever disagree the screen becomes a liar, which is exactly the
     * failure that let five production lines sit on ring_group_id = null while
     * the Numbers screen reported them as ordinary.
     *
     * So this asserts the pair AGREE, and it does so on the case that can
     * actually drift: a ring group that EXISTS but can reach nobody. Checking
     * only the null-group case proves nothing — both sides read the same
     * column there and would agree even if the availability rule were dropped
     * from one of them. Verified by deleting that rule from describe() and
     * watching this fail.
     */
    public function test_the_trace_agrees_with_what_the_caller_actually_gets(): void
    {
        $t = $this->provisionTenant();

        $answers = static fn (array $steps) => collect($steps)->firstWhere('step', 'answer');

        // One connection, two lines on it — a second connection in the same
        // scope violates the uniqueness the module depends on.
        [$dndSteps, $dndXml, $liveSteps, $liveXml] = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $renderer = new TwimlRenderer;

            // Set, but its only member is at Do Not Disturb. Anything reading
            // ring_group_id alone would call this line healthy.
            $away = $this->member($t, 'Away', 'away@'.$t['org']->slug.'.test');
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $away->id,
                'state' => PhonePresence::STATE_DND,
            ]);
            $quiet = $this->number($t, $connection, [
                'ring_group_id' => $this->group($connection, [$away->id])->id,
            ]);

            $desk = $this->member($t, 'Desk', 'desk@'.$t['org']->slug.'.test');
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $desk->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);
            $live = $this->number($t, $connection, [
                'ring_group_id' => $this->group($connection, [$desk->id])->id,
            ]);

            return [
                $renderer->describe($quiet), $renderer->inbound($quiet, []),
                $renderer->describe($live), $renderer->inbound($live, []),
            ];
        });

        $this->assertFalse(
            $answers($dndSteps)['usable'],
            'the group is set but nobody is available, and the trace called it usable',
        );
        $this->assertStringNotContainsString('<Dial', $dndXml);
        $this->assertStringContainsString('<Record', $dndXml);

        $this->assertTrue($answers($liveSteps)['usable']);
        $this->assertStringContainsString('<Dial', $liveXml);
    }

    /**
     * The consent sentence is the one setting here with legal weight, and it
     * was hardcoded for every organization in every jurisdiction. A business
     * that had to name itself, or say why, could not — and recorded anyway.
     */
    public function test_a_line_speaks_its_own_consent_sentence_or_the_default(): void
    {
        $t = $this->provisionTenant();

        [$default, $own, $blank] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);
            $renderer = new TwimlRenderer;

            $first = $renderer->inbound($number->fresh(), []);

            $number->forceFill([
                'recording_notice' => 'A-1 Chimney records calls for training.',
            ])->save();
            $second = $renderer->inbound($number->fresh(), []);

            // Blank must fall back to the default, never to silence.
            $number->forceFill(['recording_notice' => '   '])->save();
            $third = $renderer->inbound($number->fresh(), []);

            return [$first, $second, $third];
        });

        $this->assertStringContainsString('This call may be recorded.', $default);

        $this->assertStringContainsString('A-1 Chimney records calls for training.', $own);
        $this->assertStringNotContainsString('This call may be recorded.', $own);

        $this->assertStringContainsString('This call may be recorded.', $blank);
    }

    /**
     * A fallback number rings once and then takes a message.
     *
     * ── Why the second pass matters more than the first ──────────
     *
     * The Dial carries an `action`, so when it ends Twilio posts back and the
     * renderer reaches this branch AGAIN. Dialling unconditionally would ring
     * the fallback forever, billed per attempt, for as long as the caller held
     * on. The batch counter in the context is the guard, and this asserts it.
     */
    public function test_a_fallback_number_is_dialled_once_and_then_becomes_voicemail(): void
    {
        $t = $this->provisionTenant();

        [$first, $second, $noFallback] = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $renderer = new TwimlRenderer;

            // No ring group: the steps above reach nobody.
            $number = $this->number($t, $connection, [
                'ring_group_id' => null,
                'fallback_number' => '+17865550103',
            ]);

            $bare = $this->number($t, $connection, ['ring_group_id' => null]);

            return [
                $renderer->inbound($number, ['batch' => 0]),
                $renderer->inbound($number, ['batch' => 1]),
                $renderer->inbound($bare, ['batch' => 0]),
            ];
        });

        // First pass: the fallback is dialled, and no message is taken yet.
        $this->assertStringContainsString('+17865550103', $first);
        $this->assertStringContainsString('<Dial', $first);
        $this->assertStringNotContainsString('<Record', $first);

        // Second pass: voicemail, and crucially NOT the fallback again.
        $this->assertStringNotContainsString('+17865550103', $second);
        $this->assertStringNotContainsString('<Dial', $second);
        $this->assertStringContainsString('<Record', $second);

        // Unchanged for a line with no fallback set.
        $this->assertStringNotContainsString('<Dial', $noFallback);
        $this->assertStringContainsString('<Record', $noFallback);
    }

    public function test_hours_closed_goes_to_voicemail_without_dialing(): void
    {
        $t = $this->provisionTenant();
        $at = new DateTimeImmutable('2026-08-24 17:30:00', new DateTimeZone('America/New_York'));

        $xml = $this->inTenant($t['org'], function () use ($t, $at) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);
            $schedule = PhoneHoursSchedule::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Weekdays',
                'timezone' => 'America/New_York',
                'weeks' => ['mon' => [['09:00', '17:00']]],
                'exceptions' => null,
            ]);
            $number->forceFill([
                'hours_schedule_id' => $schedule->id,
                'after_hours' => 'voicemail',
            ])->save();

            return (new TwimlRenderer)->inbound($number->fresh(), ['at' => $at]);
        });

        $this->assertStringContainsString('This call may be recorded.', $xml);
        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringContainsString('playBeep="true"', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
        $this->assertStringNotContainsString('<Client', $xml);
        $this->assertStringNotContainsString('TwimlRenderer', $xml);
        $this->assertStringNotContainsString('PhoneNumber', $xml);
    }

    public function test_open_hours_dials_available_clients(): void
    {
        $t = $this->provisionTenant();
        $at = new DateTimeImmutable('2026-08-24 12:00:00', new DateTimeZone('America/New_York'));

        [$xml, $identity] = $this->inTenant($t['org'], function () use ($t, $at) {
            [$number, $user] = $this->lineWithAvailableDesk($t, record: true);
            $schedule = PhoneHoursSchedule::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Weekdays',
                'timezone' => 'America/New_York',
                'weeks' => ['mon' => [['09:00', '17:00']]],
                'exceptions' => null,
            ]);
            $number->forceFill(['hours_schedule_id' => $schedule->id])->save();

            return [
                (new TwimlRenderer)->inbound($number->fresh(), ['at' => $at]),
                ClientIdentity::of($user),
            ];
        });

        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString('>'.$identity.'</Client>', $xml);
        $this->assertStringContainsString('answerOnBridge="true"', $xml);
        $this->assertStringContainsString('record="record-from-answer-dual"', $xml);
        $this->assertStringNotContainsString('<Gather', $xml);
    }

    public function test_zero_available_members_skips_dial_and_goes_to_voicemail(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $user = $this->member($t, 'Away');
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $user->id,
                'state' => PhonePresence::STATE_DND,
            ]);
            $group = $this->group($connection, [$user->id]);
            $number = $this->number($t, $connection, [
                'ring_group_id' => $group->id,
                'record' => true,
            ]);

            return (new TwimlRenderer)->inbound($number, []);
        });

        $this->assertStringNotContainsString('<Dial', $xml);
        $this->assertStringNotContainsString('<Client', $xml);
        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringContainsString('Please leave a message after the tone.', $xml);
    }

    public function test_each_dial_has_at_most_ten_clients(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);
            $ids = [];
            for ($i = 0; $i < 11; $i++) {
                $user = $this->member($t, 'Member '.$i, 'member'.$i.'@'.$t['org']->slug.'.test');
                PhonePresence::query()->create([
                    'connection_id' => $connection->id,
                    'user_id' => $user->id,
                    'state' => PhonePresence::STATE_REGISTERED,
                ]);
                $ids[] = $user->id;
            }
            $group = $this->group($connection, $ids);
            $number = $this->number($t, $connection, ['ring_group_id' => $group->id]);

            return (new TwimlRenderer)->inbound($number, []);
        });

        $document = new DOMDocument;
        $document->loadXML($xml);
        $dials = $document->getElementsByTagName('Dial');
        $this->assertGreaterThanOrEqual(1, $dials->length);

        $totalClients = 0;
        foreach ($dials as $dial) {
            $clients = 0;
            foreach ($dial->childNodes as $child) {
                if ($child instanceof DOMElement && $child->tagName === 'Client') {
                    $clients++;
                    $totalClients++;
                }
            }
            $this->assertLessThanOrEqual(10, $clients);
            $this->assertSame('true', $dial->getAttribute('answerOnBridge'));
        }

        $this->assertSame(10, $totalClients);
        $this->assertSame(1, $dials->length);
    }

    public function test_transcription_starts_when_the_number_transcribes(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);
            $number->forceFill(['transcribe' => true])->save();

            return (new TwimlRenderer)->inbound($number->fresh(), []);
        });

        $this->assertStringContainsString('<Start>', $xml);
        $this->assertStringContainsString('<Transcription', $xml);
        $this->assertStringContainsString('inboundTrackLabel', $xml);
        $this->assertStringContainsString('outboundTrackLabel', $xml);
        $this->assertTrue(
            strpos($xml, '<Start>') < strpos($xml, 'This call may be recorded.'),
            'Transcription must precede the recording notice.',
        );
    }

    /**
     * A menu asks, and then still reaches somebody.
     *
     * This used to assert there was no Dial at all, which described a real
     * defect: a Gather with no `action` and nothing after it means Twilio
     * plays the prompt, hears nothing, runs out of document and hangs up. A
     * caller who says nothing must land on the ring group, not in silence.
     */
    public function test_a_menu_flow_gathers_and_still_reaches_somebody(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);
            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk.',
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return (new TwimlRenderer)->inbound($number->fresh(), []);
        });

        $this->assertStringContainsString('<Gather', $xml);
        $this->assertStringContainsString('Press 1 for the desk.', $xml);
        $this->assertStringContainsString('numDigits="1"', $xml);
        $this->assertStringNotContainsString('PhoneFlow', $xml);

        // The Dial comes AFTER the Gather: pressed keys route above, silence
        // falls through to here.
        $this->assertLessThan(
            strpos($xml, '<Dial'),
            strpos($xml, '<Gather'),
            'The fallthrough Dial must follow the Gather, not replace it.',
        );
    }

    public function test_pressing_a_menu_key_rings_the_group_that_key_names(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number, $group] = $this->lineWithAvailableDesk($t, record: false);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk, 2 to leave a message.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                        ['digit' => '2', 'action' => 'voicemail'],
                    ],
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return (new TwimlRenderer)->inbound($number->fresh(), ['digits' => '1']);
        });

        // Routed, not re-prompted.
        $this->assertStringNotContainsString('<Gather', $xml);
        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString('<Client', $xml);
    }

    public function test_pressing_the_voicemail_key_records_instead_of_ringing(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number, $group] = $this->lineWithAvailableDesk($t, record: false);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk, 2 to leave a message.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                        ['digit' => '2', 'action' => 'voicemail'],
                    ],
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return (new TwimlRenderer)->inbound($number->fresh(), ['digits' => '2']);
        });

        $this->assertStringContainsString('<Record', $xml);

        // '<Client' and not '<Client>': the tag carries a url attribute now,
        // so the closed form would be absent from a document that dialled the
        // whole ring group and this assertion would pass on the defect.
        $this->assertStringNotContainsString('<Client', $xml);
    }

    /**
     * A key that is not on the menu must not hang up on somebody.
     *
     * The commonest reason for a stray digit is a caller pressing anything to
     * reach a person, so an unknown key behaves like no key: it reaches the
     * line's ring group.
     */
    public function test_a_key_that_is_not_on_the_menu_still_reaches_the_line(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number, $group] = $this->lineWithAvailableDesk($t, record: false);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                    ],
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return (new TwimlRenderer)->inbound(
                $number->fresh(),
                ['digits' => '9', 'skip_menu' => true],
            );
        });

        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringNotContainsString('<Hangup', $xml);
    }

    public function test_hours_closed_forwards_when_configured(): void
    {
        $t = $this->provisionTenant();
        $at = new DateTimeImmutable('2026-08-24 17:30:00', new DateTimeZone('America/New_York'));

        $xml = $this->inTenant($t['org'], function () use ($t, $at) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);
            $schedule = PhoneHoursSchedule::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Weekdays',
                'timezone' => 'America/New_York',
                'weeks' => ['mon' => [['09:00', '17:00']]],
                'exceptions' => null,
            ]);
            $number->forceFill([
                'hours_schedule_id' => $schedule->id,
                'after_hours' => 'forward',
                'after_hours_number' => '+12035550100',
            ])->save();

            return (new TwimlRenderer)->inbound($number->fresh(), ['at' => $at]);
        });

        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString('<Number>+12035550100</Number>', $xml);
        $this->assertStringNotContainsString('<Client', $xml);
        $this->assertStringContainsString('record="record-from-answer-dual"', $xml);
    }

    public function test_hours_closed_says_the_line_is_closed(): void
    {
        $t = $this->provisionTenant();
        $at = new DateTimeImmutable('2026-08-24 17:30:00', new DateTimeZone('America/New_York'));

        $xml = $this->inTenant($t['org'], function () use ($t, $at) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);
            $schedule = PhoneHoursSchedule::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Weekdays',
                'timezone' => 'America/New_York',
                'weeks' => ['mon' => [['09:00', '17:00']]],
                'exceptions' => null,
            ]);
            $number->forceFill([
                'hours_schedule_id' => $schedule->id,
                'after_hours' => 'closed',
            ])->save();

            return (new TwimlRenderer)->inbound($number->fresh(), ['at' => $at]);
        });

        $this->assertStringContainsString('This line is closed.', $xml);
        $this->assertStringContainsString('<Hangup', $xml);
        $this->assertStringNotContainsString('may be recorded', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
    }

    /**
     * "Press 9 to hear these options again", and it plays them again.
     *
     * ── Why this is not just another routing branch ──────────────
     *
     * Every phone menu has a repeat key and this one did not, so a caller who
     * missed which key was which had to hang up and ring back. Jose asked for
     * it while looking at the four actions a key could take.
     *
     * The awkward part is the recording notice, not the menu. The notice is
     * said on the pass that does NOT play a menu — that is what stopped it
     * being said twice — and a repeat press arrives with Digits, which is
     * exactly the shape of the pass that connects somebody. So a repeat has to
     * be recognised before the notice is decided, or pressing 9 three times
     * tells the caller three more times that the call may be recorded.
     */
    public function test_a_repeat_key_plays_the_menu_again(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            $number = $this->lineWithRepeat($t);

            return (new TwimlRenderer)->inbound(
                $number->fresh(),
                ['digits' => '9', 'skip_menu' => true],
            );
        });

        $this->assertStringContainsString('<Gather', $xml);
        $this->assertStringContainsString('Press 1 for the desk, 9 to hear this again.', $xml);
    }

    /** And it says nothing about recording, because it is a menu pass. */
    public function test_a_repeat_does_not_say_the_notice_again(): void
    {
        $t = $this->provisionTenant();

        [$first, $repeat] = $this->inTenant($t['org'], function () use ($t) {
            $number = $this->lineWithRepeat($t);
            $renderer = new TwimlRenderer;

            return [
                $renderer->inbound($number->fresh(), []),
                $renderer->inbound(
                    $number->fresh(),
                    ['digits' => '9', 'skip_menu' => true],
                ),
            ];
        });

        $gather = strpos($repeat, '<Gather');
        $this->assertNotFalse($gather, 'the repeat did not play the menu');

        $this->assertStringNotContainsString(
            RecordingNotice::sentence(),
            substr($repeat, 0, $gather),
            'a caller who asks to hear the menu again is told again that the '
            .'call may be recorded, once per press',
        );

        // The control: the first pass is a menu pass too, and the two must be
        // the same document. A repeat that quietly dropped the ring-group
        // fallthrough would leave a caller who then says nothing in silence.
        $this->assertSame(
            substr_count($first, '<Dial'),
            substr_count($repeat, '<Dial'),
            'the repeated menu is not the same menu',
        );
    }

    /** A key that is not the repeat key still routes, and still says it. */
    public function test_an_ordinary_key_is_unaffected_by_the_repeat_option(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            $number = $this->lineWithRepeat($t);

            return (new TwimlRenderer)->inbound(
                $number->fresh(),
                ['digits' => '1', 'skip_menu' => true],
            );
        });

        $this->assertStringNotContainsString('<Gather', $xml);
        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString(RecordingNotice::sentence(), $xml);
    }

    /** A recorded line whose menu offers 1 to ring and 9 to hear it again. */
    private function lineWithRepeat(array $t): PhoneNumber
    {
        [$number, $user] = $this->lineWithAvailableDesk($t, record: true);
        $group = $this->group($this->connectionOf($number), [$user->id]);

        $flow = PhoneFlow::query()->create([
            'connection_id' => $number->connection_id,
            'name' => 'Main menu',
            'nodes' => [
                'type' => 'menu',
                'prompt' => 'Press 1 for the desk, 9 to hear this again.',
                'options' => [
                    ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                    ['digit' => '9', 'action' => 'repeat'],
                ],
            ],
        ]);
        $number->forceFill(['flow_id' => $flow->id])->save();

        return $number;
    }

    /**
     * A menu line with a recorded line, and the two documents a call produces.
     *
     * @return array{0: string, 1: string} the menu pass, then the digit pass
     */
    private function menuCall(array $t, string $digit): array
    {
        return $this->inTenant($t['org'], function () use ($t, $digit) {
            [$number, $user] = $this->lineWithAvailableDesk($t, record: true);
            $group = $this->group($this->connectionOf($number), [$user->id]);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                    ],
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            $renderer = new TwimlRenderer;

            return [
                $renderer->inbound($number->fresh(), []),
                $renderer->inbound($number->fresh(), ['digits' => $digit, 'skip_menu' => true]),
            ];
        });
    }

    private function connectionOf(PhoneNumber $number): PhoneConnection
    {
        return PhoneConnection::query()->findOrFail($number->connection_id);
    }

    /**
     * A caller hears the consent sentence once, not once per document.
     *
     * ── The report ───────────────────────────────────────────────────
     *
     * Jose called a line with a menu and heard: "this call may be recorded",
     * the menu, and then — after pressing 1 — "this call may be recorded"
     * again. Both were true and neither was wrong on its own, which is why it
     * survived: Twilio re-POSTs to the SAME voice URL when a key is pressed,
     * so the document is rendered twice and every top-level <Say> in it is
     * spoken twice.
     *
     * What a caller HEARS is not how often the sentence appears in the XML.
     * The menu document carries it too, but after the <Gather> — reached only
     * when the caller says nothing and the menu times out. So this walks the
     * path Jose walked: what is spoken before the menu, and what the pressed
     * key produces.
     */
    public function test_a_menu_line_says_the_recording_notice_exactly_once(): void
    {
        $t = $this->provisionTenant();
        [$menu, $pressed] = $this->menuCall($t, '1');

        $gather = strpos($menu, '<Gather');
        $this->assertNotFalse($gather, 'this line plays no menu, so it tests nothing');

        $before = substr($menu, 0, $gather);
        $this->assertStringNotContainsString(
            RecordingNotice::sentence(),
            $before,
            'the caller is told about recording, hears the menu, presses a key '
            .'and is told again — which is what Jose reported',
        );

        // And the pass that actually rings somebody still says it, once.
        $this->assertSame(1, substr_count($pressed, RecordingNotice::sentence()));
        $this->assertStringContainsString('<Dial', $pressed);
    }

    /**
     * Waiting the menu out is still recorded, and still warned.
     *
     * The Gather has no action, so silence falls through to the ring group
     * INSIDE the menu document — there is no second pass to carry the notice.
     * Suppressing it on the menu pass without this would record a caller who
     * had been told nothing, which is the failure worth having a law about.
     */
    public function test_a_caller_who_presses_nothing_is_still_told(): void
    {
        $t = $this->provisionTenant();
        [$menu] = $this->menuCall($t, '1');

        $this->assertStringContainsString('<Gather', $menu);
        $this->assertStringContainsString(RecordingNotice::sentence(), $menu);

        // After the menu, not before it: a caller who presses 1 immediately
        // never reaches this line, and that is the whole point.
        $this->assertGreaterThan(
            strpos($menu, '<Gather'),
            strpos($menu, RecordingNotice::sentence()),
        );
    }

    /**
     * A line with no menu still says it, on its one and only pass.
     *
     * The control. Without it, "say it only when there is no Gather ahead"
     * and "never say it at all" are the same green.
     */
    public function test_a_line_with_no_menu_still_says_it_once(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);

            return (new TwimlRenderer)->inbound($number->fresh(), []);
        });

        $this->assertSame(1, substr_count($xml, RecordingNotice::sentence()));
    }

    /**
     * @param  array{org: Organization, franchise: Franchise}  $tenant
     * @return array{0: PhoneNumber, 1: User}
     */
    /**
     * EVERY EDGE LANDS ON A NODE, and every node can be reached.
     *
     * The structural test, and the one worth having: a graph with a dangling
     * edge does not throw, it DRAWS — as an arrow into nothing, or a box
     * floating unconnected — so the failure arrives as a screenshot from Jose
     * rather than as a red test. Nothing else in this suite can see it, because
     * every other assertion here is about XML a caller hears.
     *
     * Run against a line with the lot: hours, a menu with four different kinds
     * of option, a ring group and a fallback. A simpler fixture would pass
     * against a graph that only ever emits the straight line.
     */
    public function test_the_call_graph_has_no_dangling_edges_or_orphan_nodes(): void
    {
        $t = $this->provisionTenant();

        $graph = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);
            $group = PhoneRingGroup::query()->findOrFail($number->ring_group_id);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk, 2 to leave a message.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                        ['digit' => '2', 'action' => 'voicemail'],
                        ['digit' => '3', 'action' => 'forward', 'number' => '+13055550100'],
                        ['digit' => '4', 'action' => 'extension', 'extension' => '204'],
                    ],
                ],
            ]);

            $number->forceFill([
                'flow_id' => $flow->id,
                'fallback_number' => '+13055550199',
            ])->save();

            return (new TwimlRenderer)->graph($number->fresh());
        });

        $ids = array_column($graph['nodes'], 'id');

        $this->assertSame(
            $ids,
            array_values(array_unique($ids)),
            'two nodes share an id, so an edge to it is ambiguous',
        );

        foreach ($graph['edges'] as $edge) {
            $this->assertContains($edge['from'], $ids, 'an edge starts at a node that does not exist');
            $this->assertContains($edge['to'], $ids, 'an edge points at a node that does not exist');
            $this->assertNotSame($edge['from'], $edge['to'], 'a node links to itself');
        }

        // Reachability from the caller, which is the only way in.
        $reached = ['caller'];
        do {
            $before = count($reached);
            foreach ($graph['edges'] as $edge) {
                if (in_array($edge['from'], $reached, true) && ! in_array($edge['to'], $reached, true)) {
                    $reached[] = $edge['to'];
                }
            }
        } while (count($reached) > $before);

        $this->assertEqualsCanonicalizing(
            $ids,
            $reached,
            'a node is drawn that no caller can arrive at',
        );
    }

    /**
     * Two queues on one chart are told apart by their titles.
     *
     * Jose, 2026-09-16, off a screenshot: three boxes reading "Hold in a
     * queue", side by side, one per team. The team name was in the detail,
     * which is a tooltip — so at the zoom he was looking at, the picture said
     * a caller waits in a queue three times and would not say which.
     *
     * TWO groups is what makes this test able to fail. With one queue any
     * title at all looks right, including the old one.
     */
    public function test_each_queue_is_named_after_its_team(): void
    {
        $t = $this->provisionTenant();

        $graph = $this->inTenant($t['org'], function () use ($t) {
            [$number, $user] = $this->lineWithAvailableDesk($t, record: false);

            $appointments = PhoneRingGroup::query()->findOrFail($number->ring_group_id);
            $appointments->forceFill(['name' => 'Appointments', 'overflow' => 'queue'])->save();

            $existing = $this->group($number->connection, [$user->id]);
            $existing->forceFill(['name' => 'Existing clients', 'overflow' => 'queue'])->save();

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 or 2.',
                    'options' => [
                        ['digit' => '2', 'action' => 'ring_group', 'ring_group_id' => $existing->id],
                    ],
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return (new TwimlRenderer)->graph($number->fresh());
        });

        $queues = [];
        foreach ($graph['nodes'] as $node) {
            if ($node['kind'] === 'queue') {
                $queues[] = $node['title'];
            }
        }

        sort($queues);

        $this->assertSame(
            ['Hold for Appointments', 'Hold for Existing clients'],
            $queues,
            'two queues on one chart cannot be told apart',
        );
    }

    /**
     * Clicking a box says where that setting is changed — and the two cases
     * that are easy to get wrong are both asserted.
     *
     * Jose, 2026-09-16: "if i were to click on the ring group at the top it
     * should open a right sidebar with the list of members, so i can assign -
     * unassign". That needs the graph to name the pane, because a screen
     * deriving it from `kind` gets the second case below backwards.
     */
    public function test_a_node_says_which_settings_pane_changes_it(): void
    {
        $t = $this->provisionTenant();

        [$graph, $groupId, $flowId] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);
            $group = PhoneRingGroup::query()->findOrFail($number->ring_group_id);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk, 2 to leave a message.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                        ['digit' => '2', 'action' => 'voicemail'],
                    ],
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return [
                (new TwimlRenderer)->graph($number->fresh()),
                (string) $group->id,
                (string) $flow->id,
            ];
        });

        $byId = [];
        foreach ($graph['nodes'] as $node) {
            $byId[$node['id']] = $node;
        }

        // The box Jose named, opening the group he wants to change the
        // members of — not the line, and not the menu that routed to it.
        $this->assertSame(
            ['pane' => 'ring-groups', 'id' => $groupId],
            $byId['answer']['edit'],
        );

        // AND THE CASE A SCREEN WOULD GET WRONG.
        //
        // This node's kind is `voicemail`, and there is a Voicemail pane, so
        // anything mapping kind to pane sends somebody to a greeting they did
        // not click on. It is a row on a menu, and the menu is where it is
        // changed.
        $this->assertSame(
            ['pane' => 'flows', 'id' => $flowId],
            $byId['key-2']['edit'],
            'a menu option opened the pane its kind suggests rather than the menu',
        );
    }

    /**
     * The chart shows the call being answered, and what happens after.
     *
     * Jose, 2026-09-16: "i also feel like we are missing stuff still like
     * showing what happens when someone does take the call, and afterwards."
     * Every box until now was a way for a call NOT to be answered, so a
     * picture of a phone system ended at its worst outcome.
     *
     * The control is the second half: a line nobody can answer must not draw
     * them, or the chart tells somebody their calls are recorded and filed
     * when nothing ever picks up.
     */
    public function test_the_answered_call_is_drawn_and_only_when_one_can_be(): void
    {
        $t = $this->provisionTenant();

        [$answered, $unanswerable] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);
            $number->connection->forceFill(['require_call_tag' => true])->save();

            // A line that rings nobody: same account, no group at all. One
            // connection per scope, so it has to share this one.
            $bare = $this->number($t, $number->connection, ['ring_group_id' => null]);

            return [
                (new TwimlRenderer)->graph($number->fresh()),
                (new TwimlRenderer)->graph($bare->fresh()),
            ];
        });

        $byId = [];
        foreach ($answered['nodes'] as $node) {
            $byId[$node['id']] = $node;
        }

        $this->assertArrayHasKey('answered', $byId);
        $this->assertArrayHasKey('recorded', $byId);
        $this->assertArrayHasKey('wrap-up', $byId);

        $this->assertSame('Recorded', $byId['recorded']['title']);
        $this->assertStringContainsString(
            'say what the call was about',
            $byId['wrap-up']['detail'],
            'the company requires a tag and the chart does not say so',
        );

        $walk = [];
        foreach ($answered['edges'] as $edge) {
            $walk[] = $edge['from'].' -> '.$edge['to'];
        }

        $this->assertContains('answer -> answered', $walk);
        $this->assertContains('answered -> recorded', $walk);
        $this->assertContains('recorded -> wrap-up', $walk);

        $bareIds = array_column($unanswerable['nodes'], 'id');
        $this->assertNotContains('answered', $bareIds);
        $this->assertNotContains('wrap-up', $bareIds);
    }

    /**
     * A menu is where one call becomes several, and the graph has to show it.
     *
     * This is the whole reason for a second shape: describe() renders four
     * options as one sentence. Four keys must be four edges, and "pressed
     * nothing" is a fifth — the commonest path and the easiest to leave out.
     */
    public function test_each_menu_key_is_its_own_branch(): void
    {
        $t = $this->provisionTenant();

        $graph = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);
            $group = PhoneRingGroup::query()->findOrFail($number->ring_group_id);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 or 2.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                        ['digit' => '2', 'action' => 'voicemail'],
                    ],
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return (new TwimlRenderer)->graph($number->fresh());
        });

        $fromMenu = [];
        foreach ($graph['edges'] as $edge) {
            if ($edge['from'] === 'menu') {
                $fromMenu[] = $edge['label'];
            }
        }

        $this->assertEqualsCanonicalizing(
            ['Press 1', 'Press 2', 'No key pressed'],
            $fromMenu,
        );
    }

    /**
     * A menu that is attached and never played is drawn as skipped.
     *
     * This is the state the screen exists for. A flow can be built, named and
     * given a line, and contain no menu step — at which point callers go
     * straight past it and hear nothing. Nobody complains: the caller does not
     * know they missed a menu and the owner does not know theirs is skipped.
     *
     * Asserted on `state`, not on the node existing — a skipped menu drawn the
     * same as a working one is worse than not drawing it. The second half is
     * the control: without it this passes against a graph that marks EVERY
     * menu skipped.
     */
    public function test_a_menu_that_never_plays_is_marked_skipped(): void
    {
        $t = $this->provisionTenant();

        [$skipped, $played] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);
            $group = PhoneRingGroup::query()->findOrFail($number->ring_group_id);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Empty flow',
                'nodes' => ['type' => 'hangup'],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            $renderer = new TwimlRenderer;
            $before = $renderer->graph($number->fresh());

            $flow->forceFill([
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1.',
                    'options' => [['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $group->id]],
                ],
            ])->save();

            return [$before, $renderer->graph($number->fresh())];
        });

        $state = static function (array $graph): ?string {
            foreach ($graph['nodes'] as $node) {
                if ($node['id'] === 'menu') {
                    return $node['state'];
                }
            }

            return null;
        };

        $this->assertSame('skipped', $state($skipped));
        $this->assertSame('ok', $state($played));
    }

    /**
     * The graph and the list must not disagree about who answers.
     *
     * Two derivations of one question, and this module has already paid once
     * for two derivations drifting: the Numbers screen spent an afternoon
     * telling five lines they were fine while every call went to voicemail.
     * Both read DialPlan::batches(), and this pins that they keep doing so.
     *
     * Asserted on the case that can distinguish — a ring group with NOBODY
     * available, where the honest answer is "dead" and the easy bug is "ok".
     */
    public function test_the_graph_agrees_with_the_list_about_who_answers(): void
    {
        $t = $this->provisionTenant();

        [$steps, $graph] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);
            $group = PhoneRingGroup::query()->findOrFail($number->ring_group_id);

            $group->forceFill(['member_user_ids' => [], 'member_role_ids' => []])->save();

            $renderer = new TwimlRenderer;

            return [$renderer->describe($number->fresh()), $renderer->graph($number->fresh())];
        });

        $answerStep = null;
        foreach ($steps as $step) {
            if ($step['step'] === 'answer') {
                $answerStep = $step;
            }
        }

        $answerNode = null;
        foreach ($graph['nodes'] as $node) {
            if ($node['id'] === 'answer') {
                $answerNode = $node;
            }
        }

        $this->assertNotNull($answerStep);
        $this->assertNotNull($answerNode);

        $this->assertFalse($answerStep['usable'], 'the fixture reaches somebody, so this proves nothing');
        $this->assertSame(
            'dead',
            $answerNode['state'],
            'the list says nobody answers and the picture says somebody does',
        );
    }

    /**
     * ONE BOX PER TEAM, however many keys reach it.
     *
     * Built to match a real line — +1 239 456-0829 — where key 2 points at the
     * very group the line already rings. The chart drew "Ring Existing Client"
     * twice, once for Press 2 and once for no key pressed, and Jose counted two
     * teams off a picture of one.
     *
     * A second route to the same team is a second ARROW, not a second box.
     */
    public function test_a_key_pointing_at_the_lines_own_group_is_not_a_second_node(): void
    {
        $t = $this->provisionTenant();

        $graph = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: false);
            $group = PhoneRingGroup::query()->findOrFail($number->ring_group_id);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 2 for the desk.',
                    'options' => [
                        // The same group the line rings by default.
                        ['digit' => '2', 'action' => 'ring_group', 'ring_group_id' => $group->id],
                    ],
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return (new TwimlRenderer)->graph($number->fresh());
        });

        $teams = array_values(array_filter(
            $graph['nodes'],
            static fn (array $n) => $n['kind'] === 'ring_group',
        ));

        $this->assertCount(
            1,
            $teams,
            'the same team is drawn twice, so the chart shows two teams where there is one',
        );

        // And BOTH routes still arrive, which is what makes one box honest
        // rather than merely tidier.
        $into = [];
        foreach ($graph['edges'] as $edge) {
            if ($edge['to'] === $teams[0]['id']) {
                $into[] = $edge['label'];
            }
        }

        $this->assertContains('Press 2', $into);
        $this->assertContains('No key pressed', $into);
    }

    /**
     * EVERY GROUP HAS ITS OWN QUEUE, and the chart shows each one.
     *
     * The queue used to be drawn once, hanging off the line's default group,
     * while the menu's other groups had none — so "Everyone busy" read as a
     * property of one box when it is a setting on each team. Jose asked
     * directly: does it apply to the whole group or not?
     *
     * They are separate queues, not one shared line: `queueName()` is named
     * after the group, so somebody holding for Appointments is not standing
     * behind somebody holding for Existing Clients.
     */
    public function test_each_queueing_group_gets_its_own_queue(): void
    {
        $t = $this->provisionTenant();

        $graph = $this->inTenant($t['org'], function () use ($t) {
            [$number, $user] = $this->lineWithAvailableDesk($t, record: false);
            $line = PhoneRingGroup::query()->findOrFail($number->ring_group_id);
            $line->forceFill(['overflow' => 'queue'])->save();

            $second = PhoneRingGroup::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Appointment',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$user->id],
                'member_role_ids' => [],
                'overflow' => 'queue',
            ]);

            // A third that does NOT queue — the control. Without it, a build
            // that gives every group a queue regardless passes.
            $third = PhoneRingGroup::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Testing',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$user->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $flow = PhoneFlow::query()->create([
                'connection_id' => $number->connection_id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 or 3.',
                    'options' => [
                        ['digit' => '1', 'action' => 'ring_group', 'ring_group_id' => $second->id],
                        ['digit' => '3', 'action' => 'ring_group', 'ring_group_id' => $third->id],
                    ],
                ],
            ]);
            $number->forceFill(['flow_id' => $flow->id])->save();

            return (new TwimlRenderer)->graph($number->fresh());
        });

        $queues = array_values(array_filter(
            $graph['nodes'],
            static fn (array $n) => $n['kind'] === 'queue',
        ));

        $this->assertCount(
            2,
            $queues,
            'the two queueing groups do not each have a queue, so "Everyone busy" '
            .'points at one team while applying to another',
        );

        // Each queue is reached from its own team and returns to it.
        foreach ($queues as $queue) {
            $from = array_values(array_filter(
                $graph['edges'],
                static fn (array $e) => $e['to'] === $queue['id'] && $e['label'] === 'Everyone busy',
            ));

            $this->assertCount(1, $from, 'a queue is reached from more than one team');

            $back = array_values(array_filter(
                $graph['edges'],
                static fn (array $e) => $e['from'] === $queue['id'] && $e['label'] === 'Somebody free',
            ));

            $this->assertSame(
                $from[0]['from'],
                $back[0]['to'],
                'the queue returns callers to a different team than it took them from',
            );
        }
    }

    private function lineWithAvailableDesk(array $tenant, bool $record): array
    {
        $connection = $this->connection($tenant);
        $user = $this->member($tenant, 'Desk');
        PhonePresence::query()->create([
            'connection_id' => $connection->id,
            'user_id' => $user->id,
            'state' => PhonePresence::STATE_REGISTERED,
        ]);
        $group = $this->group($connection, [$user->id]);
        $number = $this->number($tenant, $connection, [
            'ring_group_id' => $group->id,
            'record' => $record,
        ]);

        return [$number, $user];
    }

    /**
     * @param  array{org: Organization}  $tenant
     */
    private function connection(array $tenant): PhoneConnection
    {
        return PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
        ]);
    }

    /**
     * @param  array{org: Organization, franchise: Franchise}  $tenant
     * @param  array<string, mixed>  $overrides
     */
    private function number(array $tenant, PhoneConnection $connection, array $overrides = []): PhoneNumber
    {
        return PhoneNumber::factory()->create([
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            ...$overrides,
        ]);
    }

    /**
     * @param  array{org: Organization, franchise: Franchise}  $tenant
     */
    private function member(array $tenant, string $name, ?string $email = null): User
    {
        $email ??= strtolower(preg_replace('/[^a-z0-9]/i', '', $name)).'@'.$tenant['org']->slug.'.test';

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

    /**
     * @param  list<string>  $userIds
     */
    private function group(PhoneConnection $connection, array $userIds): PhoneRingGroup
    {
        return PhoneRingGroup::query()->create([
            'connection_id' => $connection->id,
            'name' => 'Front desk',
            'mode' => 'simultaneous',
            'timeout_seconds' => 30,
            'member_user_ids' => $userIds,
            'member_role_ids' => [],
            'overflow' => 'voicemail',
        ]);
    }

    /**
     * A line with nothing of its own answers with the COMPANY's settings.
     *
     * General looked like a company screen and behaved like a per-number
     * editor, so "what happens when somebody rings us" had to be set once per
     * line. Seven numbers meant seven settings, and the eighth — bought next
     * month — silently answered differently from the other seven, with nothing
     * anywhere saying it would.
     *
     * The failure is invisible from inside the app: the new line has a perfect
     * settings screen, all of it empty, and empty renders as "no menu, no
     * hours, no voicemail box" rather than as "not set up". Only the caller
     * finds out.
     *
     * Every value here is set on the CONNECTION and nothing on the number.
     */
    public function test_a_line_with_no_settings_answers_with_the_companys(): void
    {
        $t = $this->provisionTenant();

        $number = $this->inTenant($t['org'], function () use ($t) {
            $connection = $this->connection($t);

            // Somebody who would actually be rung. A group with nobody
            // reachable correctly falls through to voicemail, so an empty one
            // would prove nothing about whether the company default was read.
            $desk = $this->member($t, 'Desk');
            PhonePresence::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $desk->id,
                'state' => PhonePresence::STATE_REGISTERED,
            ]);

            $connection->forceFill([
                'default_ring_group_id' => $this->group($connection, [$desk->id])->id,
                'default_recording_notice' => 'Calls to A-1 Chimney are '
                    .'recorded for training.',
                'default_record' => true,
            ])->save();

            // Nothing of its own. This is every line after the wipe, and every
            // line bought from now on.
            return $this->number($t, $connection, [
                'ring_group_id' => null,
                'recording_notice' => null,
                'record' => false,
            ])->fresh();
        });

        $twiml = $this->inTenant(
            $t['org'],
            fn () => (new TwimlRenderer)->inbound($number, []),
        );

        // Deliberately NOT the built-in sentence.
        //
        // The first version of this test used "This call may be recorded.",
        // which is exactly what RecordingNotice::sentence() falls back to — so
        // it passed with inheritance ripped out, and I only found that by
        // running the sabotage. A test whose expected value is also the
        // fallback asserts nothing at all.
        $this->assertStringContainsString(
            'recorded for training',
            $twiml,
            'the company recording notice never reached the caller',
        );

        $this->assertStringNotContainsString(
            'Please leave a message',
            $twiml,
            'the company ring group was ignored and the caller went straight '
            .'to voicemail',
        );

        $this->assertStringContainsString(
            'record',
            $twiml,
            'the company said record calls and the line did not',
        );

    }

    /**
     * On an OUTBOUND call the recording notice must reach the CUSTOMER.
     *
     * ── Why the obvious code was wrong ───────────────────────────
     *
     * The notice is a top-level <Say>. On an inbound call that is exactly
     * right: Twilio runs this document on the caller's leg, so the customer
     * hears it before anybody picks up.
     *
     * On an outbound browser call the same document runs on the EMPLOYEE's
     * leg — the browser is what Twilio connected — and the customer is then
     * bridged in by <Dial>. So the person being recorded heard nothing, and
     * the person doing the recording heard the warning. Exactly inverted.
     *
     * That is not a cosmetic defect. Eleven US states require every party to
     * consent, and the recording exists to be played back later; a recording
     * made without the other party informed is one that cannot be used and
     * should not have been made.
     *
     * The fix is a whisper: <Number url="..."> plays TwiML to the called party
     * when they answer, before the legs are bridged. So the notice goes to
     * whoever needs to hear it and the employee is not made to sit through it.
     */
    public function test_an_outbound_call_says_the_recording_notice_to_the_person_called(): void
    {
        $t = $this->provisionTenant();

        $number = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);

            return PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'e164' => '+12035558811',
                'record' => true,
                'recording_notice' => 'A-1 Chimney records its calls.',
            ])->fresh();
        });

        $twiml = $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound($number, [
            'outbound' => true,
            'dial_to' => '+13055550199',
            'caller_id' => '+12035558811',
        ]));

        $document = new DOMDocument;
        $document->loadXML($twiml);

        $numbers = $document->getElementsByTagName('Number');
        $this->assertGreaterThan(0, $numbers->length, 'no <Number> was dialled');

        /** @var DOMElement $dialled */
        $dialled = $numbers->item(0);

        $this->assertNotSame(
            '',
            (string) $dialled->getAttribute('url'),
            'the person being called is bridged with no announcement, so the '
            .'recording notice reached only the employee who dialled',
        );

        // And the employee is not read the notice on the way out: the top-level
        // Say belongs to whoever the document runs on, and on an outbound call
        // that is the wrong party.
        $says = [];
        foreach ($document->getElementsByTagName('Say') as $say) {
            $says[] = $say->textContent;
        }

        $this->assertNotContains(
            'A-1 Chimney records its calls.',
            $says,
            'the notice is still being spoken to the employee who dialled',
        );
    }
}
