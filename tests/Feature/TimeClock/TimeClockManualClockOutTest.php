<?php

namespace Tests\Feature\TimeClock;

use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-13: "disable auto clock out for Technicians, it needs to be
 * Manual for that role. and also send a whatsapp to the area manager". A
 * technician in at 7, out at 6 against a 5 o'clock end: the schedule does not
 * close the shift, and the branch's manager hears In 7:00 AM, Out 6:00 PM
 * (1 h past schedule), 11 h. Wednesday 2026-10-21, New York.
 */
class TimeClockManualClockOutTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_technician_is_not_clocked_out_by_the_schedule_and_the_manager_hears_the_times(): void
    {
        [$alpha, $tech, $manager, $office] = $this->tenant();

        Carbon::setTestNow(Carbon::parse('2026-10-21 11:00:00', 'UTC')); // 7:00 AM
        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-21 21:30:00', 'UTC')); // 5:30 PM, past the 5 o'clock end
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();
        $this->assertNotNull($this->open($alpha, $tech), 'the schedule does not close a technician');

        Carbon::setTestNow(Carbon::parse('2026-10-21 22:00:00', 'UTC')); // 6:00 PM
        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        $messages = $this->messages();
        $this->assertCount(1, $messages, 'one message, to the one manager with a phone');
        $this->assertStringContainsString('3055550101', $messages[0]['to']);
        $params = array_column($messages[0]['template']['components'][0]['parameters'], 'text');
        $this->assertSame(["{$tech->name} · Alpha", '7:00 AM', '6:00 PM (1 h past schedule)', '11 h'], $params);

        // The control: the office person's role is not manual, so the schedule
        // closes them and nobody is texted.
        $this->sent = [];
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        $this->signedInAs($office)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-22 21:01:00', 'UTC'));
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();
        $this->assertNull($this->open($alpha, $office));
        $this->assertSame([], $this->messages());
    }

    /** @return array{0: array, 1: User, 2: User, 3: User} */
    private function tenant(): array
    {
        Http::fake(function ($request) {
            $this->sent[] = ['url' => $request->url(), 'method' => $request->method(), ...$request->data()];

            return Http::response(['messages' => [['id' => 'wamid.'.count($this->sent)]]]);
        });
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);

        $t = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($t, 'Technician', ['time_clock.view'], 'tech@alpha.test');
        $manager = $this->userWithRole($t, 'Local Manager', ['time_clock.view', 'work_hours.view', 'work_hours.approve'], 'gm@alpha.test');
        $office = $this->userWithRole($t, 'Office', ['time_clock.view'], 'office@alpha.test');
        $week = ['days' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], ['start' => '08:00', 'end' => '17:00'])];

        $this->inTenant($t['org'], function () use ($t, $tech, $manager, $office, $week) {
            $set = fn (string $k, mixed $v) => app(SettingsResolver::class)->set($k, $t['org'], $v);
            foreach (['time_clock.enabled' => true, 'timezone' => 'America/New_York', 'time_format' => '12h',
                'time_clock.manual_clock_out_roles' => [$tech->role_id]] as $k => $v) {
                $set($k, $v);
            }
            $tech->forceFill(['work_schedule' => $week])->save();
            $office->forceFill(['work_schedule' => $week])->save();
            $manager->forceFill(['phone' => '(305) 555-0101'])->save();
            DB::table('whatsapp_numbers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'phone_number_id' => 'REPORTS-1',
                'waba_id' => 'W2', 'display_number' => '+18774508572', 'purpose' => 'reports', 'created_at' => now(), 'updated_at' => now()]);
        });

        return [$t, $tech, $manager, $office];
    }

    /** @return list<array<string, mixed>> */
    private function messages(): array
    {
        return array_values(array_filter($this->sent, fn ($m) => $m['method'] === 'POST' && isset($m['to'])));
    }

    private function open(array $t, $user): ?TimeClockShift
    {
        return app(TenantContext::class)->run($t['org'], fn () => TimeClockShift::query()->open()->where('user_id', $user->id)->first());
    }
}
