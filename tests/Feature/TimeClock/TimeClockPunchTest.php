<?php

namespace Tests\Feature\TimeClock;

use App\Models\AutomationEvent;
use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TimeClockPunchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_org_off_hides_the_button_and_refuses_clock_in(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $me = $this->signedInAs($puncher)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');

        $this->assertFalse($me['organization_enabled']);
        $this->assertFalse($me['show_button']);
        $this->assertNull($me['open']);

        $this->assertRefusedWithSentence(
            $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in'),
            'Clock in is switched off for this organization.',
        );
    }

    public function test_a_role_without_clock_in_cannot_punch(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.view']);

        $me = $this->signedInAs($viewer)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');

        $this->assertTrue($me['organization_enabled']);
        $this->assertFalse($me['can_clock']);
        $this->assertFalse($me['show_button']);
        $this->assertNull($me['open']);

        $this->signedInAs($viewer)->postJson('/api/v1/time-clock/clock-in')
            ->assertForbidden()
            ->assertJsonPath('message', Refusal::permission('clock in')->message());
    }

    public function test_clock_in_opens_a_shift_and_clock_out_closes_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->inTenant($alpha['org'], function () use ($puncher) {
            $this->assertTrue($puncher->hasPermission('time_clock.view'));
            $this->assertFalse($puncher->hasPermission('hr.view'));
        });

        $inAt = Carbon::parse('2026-08-25 14:00:00', 'UTC');
        Carbon::setTestNow($inAt);

        $me = $this->signedInAs($puncher)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');
        $this->assertTrue($me['show_button']);
        $this->assertTrue($me['can_clock']);
        $this->assertNull($me['open']);

        $clockedIn = $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $payload = $clockedIn->json('data.time_clock');
        $this->assertNotNull($payload['open']);
        $this->assertSame($inAt->utc()->format('Y-m-d\TH:i:s\Z'), $payload['open']['clocked_in_at']);

        $shiftId = $payload['open']['id'];
        $this->inTenant($alpha['org'], function () use ($puncher, $shiftId, $inAt) {
            $row = TimeClockShift::query()->find($shiftId);
            $this->assertNotNull($row);
            $this->assertSame($puncher->id, $row->user_id);
            $this->assertNull($row->clocked_out_at);
            $this->assertTrue($inAt->equalTo($row->clocked_in_at));
        });

        $this->assertSame(
            $shiftId,
            $this->signedInAs($puncher)->getJson('/api/v1/me')->assertOk()->json('data.time_clock.open.id'),
        );

        $this->assertRefusedWithSentence(
            $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in'),
            'You are already clocked in.',
        );

        Carbon::setTestNow($inAt->copy()->addHours(4));

        $clockedOut = $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-out')->assertOk();
        $this->assertNull($clockedOut->json('data.time_clock.open'));
        $this->assertNull($this->signedInAs($puncher)->getJson('/api/v1/me')->assertOk()->json('data.time_clock.open'));

        Carbon::setTestNow($inAt->copy()->addHours(5));
        $again = $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->assertNotSame($shiftId, $again->json('data.time_clock.open.id'));
    }

    public function test_clock_out_still_works_after_the_organization_switch_is_taken_away(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        Carbon::setTestNow(Carbon::parse('2026-08-25 09:00:00', 'UTC'));
        $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $this->inTenant($alpha['org'], function () use ($alpha) {
            app(SettingsResolver::class)->set('time_clock.enabled', $alpha['org'], false);
        });

        Carbon::setTestNow(Carbon::parse('2026-08-25 17:00:00', 'UTC'));
        $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-out')
            ->assertOk()
            ->assertJsonPath('data.time_clock.open', null);

        $this->assertRefusedWithSentence(
            $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in'),
            'Clock in is switched off for this organization.',
        );
    }

    public function test_clock_out_still_works_after_the_grant_is_taken_away(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        Carbon::setTestNow(Carbon::parse('2026-08-25 09:00:00', 'UTC'));
        $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $this->inTenant($alpha['org'], function () use ($puncher) {
            $puncher->role->syncPermissionKeys([]);
        });

        Carbon::setTestNow(Carbon::parse('2026-08-25 17:00:00', 'UTC'));
        $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-out')
            ->assertOk()
            ->assertJsonPath('data.time_clock.open', null);
    }

    public function test_a_client_supplied_timestamp_is_ignored(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $now = Carbon::parse('2026-08-25 14:30:00', 'UTC');
        Carbon::setTestNow($now);

        $payload = $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in', [
            'clocked_in_at' => '1999-01-01T00:00:00Z',
        ])->assertOk()->json('data.time_clock');

        $this->assertSame($now->utc()->format('Y-m-d\TH:i:s\Z'), $payload['open']['clocked_in_at']);
        $this->assertStringStartsWith($now->toDateString(), $payload['open']['clocked_in_at']);
        $this->assertStringNotContainsString('1999', $payload['open']['clocked_in_at']);
    }

    public function test_both_punches_raise_automation_events(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $inAt = Carbon::parse('2026-08-25 08:00:00', 'UTC');
        Carbon::setTestNow($inAt);
        $shiftId = $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in')
            ->assertOk()
            ->json('data.time_clock.open.id');

        $outAt = Carbon::parse('2026-08-25 16:00:00', 'UTC');
        Carbon::setTestNow($outAt);
        $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        $this->inTenant($alpha['org'], function () use ($puncher, $shiftId, $inAt, $outAt) {
            $clockedIn = AutomationEvent::query()->where('event_key', 'time_clock.clocked_in')->first();
            $this->assertNotNull($clockedIn);
            $this->assertSame(User::class, $clockedIn->subject_type);
            $this->assertSame($puncher->id, $clockedIn->subject_id);
            $this->assertSame($puncher->id, $clockedIn->actor_id);
            $this->assertSame($shiftId, $clockedIn->payload['id']);
            $this->assertSame($puncher->id, $clockedIn->payload['user_id']);
            $this->assertSame($inAt->utc()->format('Y-m-d\TH:i:s\Z'), $clockedIn->payload['clocked_in_at']);
            $this->assertNull($clockedIn->payload['clocked_out_at']);

            $clockedOut = AutomationEvent::query()->where('event_key', 'time_clock.clocked_out')->first();
            $this->assertNotNull($clockedOut);
            $this->assertSame(User::class, $clockedOut->subject_type);
            $this->assertSame($puncher->id, $clockedOut->subject_id);
            $this->assertSame($puncher->id, $clockedOut->actor_id);
            $this->assertSame($shiftId, $clockedOut->payload['id']);
            $this->assertSame($outAt->utc()->format('Y-m-d\TH:i:s\Z'), $clockedOut->payload['clocked_out_at']);
        });
    }

    public function test_another_organization_cannot_see_an_open_shift_on_me(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo', 'bravo@example.test');
        $this->enableClockIn($alpha['org']);
        $this->enableClockIn($bravo['org']);

        $alphaPuncher = $this->userWithRole($alpha, 'AlphaTech', ['time_clock.view']);
        $bravoPuncher = $this->userWithRole($bravo, 'BravoTech', ['time_clock.view']);

        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'UTC'));
        $alphaOpen = $this->signedInAs($alphaPuncher)->postJson('/api/v1/time-clock/clock-in')
            ->assertOk()
            ->json('data.time_clock.open');

        $this->assertNotNull($alphaOpen);

        $bravoMe = $this->signedInAs($bravoPuncher)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');
        $this->assertNull($bravoMe['open']);
        $this->assertNotSame($alphaOpen['id'], $bravoMe['open']['id'] ?? null);
    }

    public function test_clock_out_with_no_open_shift_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->assertRefusedWithSentence(
            $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-out'),
            'You are not clocked in.',
        );
    }

    public function test_clock_out_stays_on_me_after_the_organization_switch_is_taken_away(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        Carbon::setTestNow(Carbon::parse('2026-08-25 09:00:00', 'UTC'));
        $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $this->inTenant($alpha['org'], function () use ($alpha) {
            app(SettingsResolver::class)->set('time_clock.enabled', $alpha['org'], false);
        });

        $me = $this->signedInAs($puncher)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');
        $this->assertFalse($me['organization_enabled']);
        $this->assertTrue($me['show_button']);
        $this->assertNotNull($me['open']);

        Carbon::setTestNow(Carbon::parse('2026-08-25 17:00:00', 'UTC'));
        $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        $after = $this->signedInAs($puncher)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');
        $this->assertFalse($after['show_button']);
        $this->assertNull($after['open']);
    }

    public function test_a_unique_violation_on_clock_in_is_already_clocked_in(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        TimeClockShift::creating(function () use ($alpha, $puncher) {
            static $once = false;
            if ($once) {
                return;
            }
            $once = true;

            TimeClockShift::withoutEvents(fn () => TimeClockShift::query()->create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $puncher->id,
                'clocked_in_at' => now()->subMinute(),
                'clocked_out_at' => null,
            ]));
        });

        $this->assertRefusedWithSentence(
            $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in'),
            'You are already clocked in.',
        );
    }

    private function enableClockIn(Organization $org): void
    {
        app(TenantContext::class)->run($org, function () use ($org) {
            app(SettingsResolver::class)->set('time_clock.enabled', $org, true);
        });
    }

    private function assertRefusedWithSentence(TestResponse $response, string $sentence): void
    {
        $this->assertContains($response->status(), [403, 422], $response->getContent());
        $this->assertStringContainsString($sentence, $response->getContent());
    }
}
