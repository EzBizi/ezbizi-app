<?php

namespace Tests\Feature\Phone;

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

        [$broken, $working] = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->lineWithAvailableDesk($t, record: true);
            $renderer = new TwimlRenderer;

            return [
                // Outbound, but the destination never arrived.
                $renderer->inbound($number->fresh(), ['outbound' => true]),
                $renderer->inbound($number->fresh(), [
                    'outbound' => true,
                    'dial_to' => '+13057242199',
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
        $this->assertStringContainsString('+13057242199', $working);
        $this->assertStringNotContainsString('<Record', $working);
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
                'fallback_number' => '+17867869060',
            ]);

            $bare = $this->number($t, $connection, ['ring_group_id' => null]);

            return [
                $renderer->inbound($number, ['batch' => 0]),
                $renderer->inbound($number, ['batch' => 1]),
                $renderer->inbound($bare, ['batch' => 0]),
            ];
        });

        // First pass: the fallback is dialled, and no message is taken yet.
        $this->assertStringContainsString('+17867869060', $first);
        $this->assertStringContainsString('<Dial', $first);
        $this->assertStringNotContainsString('<Record', $first);

        // Second pass: voicemail, and crucially NOT the fallback again.
        $this->assertStringNotContainsString('+17867869060', $second);
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
        $this->assertStringContainsString('<Client>'.$identity.'</Client>', $xml);
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
        $this->assertStringContainsString('<Client>', $xml);
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
        $this->assertStringNotContainsString('<Client>', $xml);
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
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: PhoneNumber, 1: User}
     */
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
     * @param  array{org: \App\Models\Organization}  $tenant
     */
    private function connection(array $tenant): PhoneConnection
    {
        return PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
        ]);
    }

    /**
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
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
     * @param  array{org: \App\Models\Organization, franchise: \App\Models\Franchise}  $tenant
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
}
