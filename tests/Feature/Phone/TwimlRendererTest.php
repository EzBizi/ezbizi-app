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

    public function test_a_menu_flow_gathers_instead_of_dialing(): void
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
        $this->assertStringNotContainsString('<Dial', $xml);
        $this->assertStringNotContainsString('PhoneFlow', $xml);
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
