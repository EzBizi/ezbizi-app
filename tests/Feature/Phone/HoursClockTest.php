<?php

namespace Tests\Feature\Phone;

use App\Models\Assignment;
use App\Models\PhoneConnection;
use App\Models\PhoneHoursSchedule;
use App\Models\PhoneNumber;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone\HoursClock;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Named open-hours schedules: wall clock in the schedule timezone (Task 7).
 *
 * Empty day = closed. Exceptions close a calendar date. Chicago vs New York
 * must disagree at the same absolute instant when close is 17:00 local.
 */
class HoursClockTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weekday_open_inside_an_interval(): void
    {
        $schedule = $this->schedule(
            timezone: 'America/New_York',
            weeks: ['mon' => [['09:00', '17:00']]],
        );

        // Monday 2026-08-24 12:00 ET
        $at = new DateTimeImmutable('2026-08-24 12:00:00', new DateTimeZone('America/New_York'));

        $this->assertTrue(HoursClock::open($schedule, $at));
    }

    public function test_after_hours_is_closed(): void
    {
        $schedule = $this->schedule(
            timezone: 'America/New_York',
            weeks: ['mon' => [['09:00', '17:00']]],
        );

        // Monday 2026-08-24 17:30 ET — after the 17:00 close
        $at = new DateTimeImmutable('2026-08-24 17:30:00', new DateTimeZone('America/New_York'));

        $this->assertFalse(HoursClock::open($schedule, $at));
    }

    public function test_empty_day_is_closed(): void
    {
        $schedule = $this->schedule(
            timezone: 'America/New_York',
            weeks: [
                'mon' => [['09:00', '17:00']],
                'sun' => [],
            ],
        );

        $at = new DateTimeImmutable('2026-08-23 12:00:00', new DateTimeZone('America/New_York'));

        $this->assertFalse(HoursClock::open($schedule, $at));
    }

    public function test_exception_closes_the_calendar_day(): void
    {
        $schedule = $this->schedule(
            timezone: 'America/New_York',
            weeks: ['fri' => [['09:00', '17:00']]],
            exceptions: [['on' => '2026-12-25', 'closed' => true]],
        );

        // Christmas 2026 is a Friday; weeks would be open without the exception.
        $at = new DateTimeImmutable('2026-12-25 12:00:00', new DateTimeZone('America/New_York'));

        $this->assertFalse(HoursClock::open($schedule, $at));
    }

    public function test_same_instant_closed_in_new_york_still_open_in_chicago(): void
    {
        // Freeze "now" at Monday 21:30 UTC = 17:30 ET = 16:30 CT.
        Carbon::setTestNow(Carbon::parse('2026-08-24 21:30:00', 'UTC'));

        $ny = $this->schedule(
            timezone: 'America/New_York',
            weeks: ['mon' => [['09:00', '17:00']]],
        );
        $chicago = $this->schedule(
            timezone: 'America/Chicago',
            weeks: ['mon' => [['09:00', '17:00']]],
        );

        $at = DateTimeImmutable::createFromMutable(Carbon::now('UTC')->toDateTime());

        $this->assertFalse(HoursClock::open($ny, $at));
        $this->assertTrue(HoursClock::open($chicago, $at));
    }

    public function test_creating_from_a_franchise_number_defaults_the_franchise_timezone(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);

        $this->inTenant($t['org'], function () use ($t) {
            $t['franchise']->forceFill(['timezone' => 'America/Chicago'])->save();
        });

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_FRANCHISE,
                'scope_id' => $t['franchise']->id,
                'e164' => '+12035550199',
                'label' => 'Branch',
            ]);

            return [$connection, $number];
        });

        $response = $this->signedInAs($editor)->postJson('/api/v1/phone/hours', [
            'connection_id' => $connection->id,
            'number_id' => $number->id,
            'name' => 'Branch hours',
            'weeks' => [
                'mon' => [['09:00', '17:00']],
                'tue' => [['09:00', '17:00']],
                'wed' => [['09:00', '17:00']],
                'thu' => [['09:00', '17:00']],
                'fri' => [['09:00', '17:00']],
                'sat' => [],
                'sun' => [],
            ],
        ]);

        $response->assertCreated();
        $this->assertSame('America/Chicago', $response->json('data.timezone'));
        $this->assertSame('Branch hours', $response->json('data.name'));

        $this->inTenant($t['org'], function () use ($number, $response) {
            $number->refresh();
            $this->assertSame($response->json('data.id'), $number->hours_schedule_id);
        });
    }

    public function test_hours_crud_lists_updates_and_deletes(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        $created = $this->signedInAs($editor)->postJson('/api/v1/phone/hours', [
            'connection_id' => $connection->id,
            'name' => 'Main',
            'timezone' => 'America/New_York',
            'weeks' => ['mon' => [['09:00', '17:00']]],
            'exceptions' => [['on' => '2026-12-25', 'closed' => true]],
        ])->assertCreated()->json('data');

        $list = $this->signedInAs($editor)
            ->getJson('/api/v1/phone/hours')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $list);
        $this->assertSame($created['id'], $list[0]['id']);

        $this->signedInAs($editor)->patchJson('/api/v1/phone/hours/'.$created['id'], [
            'name' => 'Main desk',
            'weeks' => ['mon' => [['08:00', '18:00']]],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Main desk')
            ->assertJsonPath('data.weeks.mon.0.0', '08:00');

        $this->signedInAs($editor)
            ->deleteJson('/api/v1/phone/hours/'.$created['id'])
            ->assertOk();

        $this->inTenant($t['org'], function () use ($created) {
            $this->assertNull(PhoneHoursSchedule::query()->find($created['id']));
        });
    }

    public function test_viewer_without_edit_cannot_create_hours(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Technician', ['phone.view', 'phone.settings.view']);

        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        $response = $this->signedInAs($viewer)->postJson('/api/v1/phone/hours', [
            'connection_id' => $connection->id,
            'name' => 'Main',
            'timezone' => 'America/New_York',
            'weeks' => ['mon' => [['09:00', '17:00']]],
        ]);

        $response->assertForbidden();
        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('PhoneHoursSchedule', $message);
        $this->assertStringNotContainsString('HoursClock', $message);
    }

    /**
     * Index must use the same reach as view: company callers do not see
     * schedules on the organization connection.
     */
    public function test_a_company_scoped_caller_does_not_list_org_connection_hours(): void
    {
        $t = $this->provisionTenant();

        $companyUser = $this->inTenant($t['org'], function () use ($t) {
            $role = Role::create([
                'organization_id' => $t['org']->id,
                'name' => 'Company phone',
                'is_system' => false,
            ]);
            $role->syncPermissionKeys(['phone.view', 'phone.settings.view', 'phone.edit']);

            $user = User::create([
                'organization_id' => $t['org']->id,
                'role_id' => $role->id,
                'name' => 'Company phone',
                'email' => 'company-phone@'.$t['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $t['org']->id,
                'user_id' => $user->id,
                'assigned_company_id' => $t['company']->id,
            ]);

            return $user;
        });

        [$orgSchedule, $companySchedule] = $this->inTenant($t['org'], function () use ($t) {
            $orgConnection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);
            $companyConnection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
            ]);

            $orgSchedule = PhoneHoursSchedule::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $orgConnection->id,
                'name' => 'Org hours',
                'timezone' => 'America/New_York',
                'weeks' => ['mon' => [['09:00', '17:00']]],
                'exceptions' => null,
            ]);
            $companySchedule = PhoneHoursSchedule::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $companyConnection->id,
                'name' => 'Company hours',
                'timezone' => 'America/Chicago',
                'weeks' => ['mon' => [['09:00', '17:00']]],
                'exceptions' => null,
            ]);

            return [$orgSchedule, $companySchedule];
        });

        $list = $this->signedInAs($companyUser)
            ->getJson('/api/v1/phone/hours')
            ->assertOk()
            ->json('data');

        $ids = array_column($list, 'id');
        $this->assertContains($companySchedule->id, $ids);
        $this->assertNotContains($orgSchedule->id, $ids);

        $this->signedInAs($companyUser)
            ->getJson('/api/v1/phone/hours/'.$orgSchedule->id)
            ->assertForbidden();

        $this->signedInAs($companyUser)
            ->getJson('/api/v1/phone/hours/'.$companySchedule->id)
            ->assertOk()
            ->assertJsonPath('data.id', $companySchedule->id);
    }

    /**
     * @param  array<string, list<list<string>>>  $weeks
     * @param  list<array{on: string, closed?: bool}>|null  $exceptions
     */
    private function schedule(string $timezone, array $weeks, ?array $exceptions = null): PhoneHoursSchedule
    {
        $t = $this->provisionTenant();

        return $this->inTenant($t['org'], function () use ($t, $timezone, $weeks, $exceptions) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            return PhoneHoursSchedule::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'Test',
                'timezone' => $timezone,
                'weeks' => $weeks,
                'exceptions' => $exceptions,
            ]);
        });
    }
}
