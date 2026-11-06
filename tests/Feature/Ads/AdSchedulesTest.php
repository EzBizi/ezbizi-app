<?php

namespace Tests\Feature\Ads;

use App\Models\AdSchedule;
use App\Models\AuditEvent;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-05: "can we build a switch so it can turn it on/off at designated times?" */
class AdSchedulesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function account(array $t, string $provider = 'facebook_ads', string $remote = 'act_111'): SocialConnection
    {
        return $this->inTenant($t['org'], fn () => tap(SocialConnection::create([
            'organization_id' => $t['org']->id,
            'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'provider' => $provider,
            'remote_id' => $remote,
            'display_name' => 'A1 Chimney',
        ]), fn ($c) => $c->forceFill(['credentials' => ['access_token' => 'user-token']])->save()));
    }

    private function schedule(array $days, string $on, string $off): AdSchedule
    {
        return new AdSchedule(['days' => $days, 'on_at' => $on, 'off_at' => $off, 'timezone' => 'America/New_York']);
    }

    public function test_the_window_holds_by_day_and_runs_overnight(): void
    {
        // Mon-Fri 8:00-18:00 New York. 2026-11-09 is a Monday.
        $day = $this->schedule([1, 2, 3, 4, 5], '08:00', '18:00');
        $this->assertFalse($day->wantsOn(Carbon::parse('2026-11-09 07:59', 'America/New_York')));
        $this->assertTrue($day->wantsOn(Carbon::parse('2026-11-09 08:00', 'America/New_York')));
        $this->assertFalse($day->wantsOn(Carbon::parse('2026-11-09 18:00', 'America/New_York')));
        $this->assertFalse($day->wantsOn(Carbon::parse('2026-11-14 12:00', 'America/New_York'))); // Saturday
        $next = $day->nextChange(Carbon::parse('2026-11-13 19:00', 'America/New_York')); // Friday evening
        $this->assertSame('2026-11-16 08:00', $next['at']->setTimezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertTrue($next['on']);

        // 22:00-06:00 on Fridays only: still on at 05:00 Saturday.
        $night = $this->schedule([5], '22:00', '06:00');
        $this->assertTrue($night->wantsOn(Carbon::parse('2026-11-14 05:00', 'America/New_York')));
        $this->assertFalse($night->wantsOn(Carbon::parse('2026-11-14 06:00', 'America/New_York')));
    }

    public function test_the_scheduler_acts_on_each_edge_once(): void
    {
        $t = $this->provisionTenant('Alpha');
        $row = $this->account($t);
        Http::fake([
            'graph.facebook.com/*/120?*' => Http::response(['id' => '120', 'account_id' => '111']),
            'graph.facebook.com/*/120' => Http::response(['success' => true]),
        ]);
        $this->inTenant($t['org'], fn () => AdSchedule::create([
            'organization_id' => $t['org']->id, 'connection_id' => $row->id, 'object_id' => '120', 'object_name' => 'Milwaukee',
            'days' => [1, 2, 3, 4, 5, 6, 7], 'on_at' => '08:00', 'off_at' => '18:00', 'timezone' => 'America/New_York',
        ]));

        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00', 'America/New_York'));
        $this->artisan('ezbizi:ad-schedules')->assertSuccessful();
        $this->artisan('ezbizi:ad-schedules')->assertSuccessful();   // same state: nothing sent
        Carbon::setTestNow(Carbon::parse('2026-11-09 18:01', 'America/New_York'));
        $this->artisan('ezbizi:ad-schedules')->assertSuccessful();

        $posts = Http::recorded(fn (Request $r) => $r->method() === 'POST')->map(fn ($pair) => $pair[0]['status'])->values()->all();
        $this->assertSame(['ACTIVE', 'PAUSED'], $posts);
        $this->assertSame(2, AuditEvent::query()->withoutGlobalScopes()->where('action', 'meta_ads.status_changed')
            ->where('metadata->by', 'schedule')->count());
        Carbon::setTestNow();
    }

    public function test_a_refusal_is_kept_on_the_row_and_the_edge_is_tried_again(): void
    {
        $t = $this->provisionTenant('Bravo');
        $row = $this->account($t);
        Http::fake([
            'graph.facebook.com/*/120?*' => Http::response(['id' => '120', 'account_id' => '111']),
            'graph.facebook.com/*/120' => Http::response(['error' => ['message' => '(#200) Requires ads_management permission']], 403),
        ]);
        $schedule = $this->inTenant($t['org'], fn () => AdSchedule::create([
            'organization_id' => $t['org']->id, 'connection_id' => $row->id, 'object_id' => '120',
            'days' => [1, 2, 3, 4, 5, 6, 7], 'on_at' => '00:00', 'off_at' => '23:59', 'timezone' => 'UTC',
        ]));

        $this->artisan('ezbizi:ad-schedules')->assertSuccessful();

        $fresh = $this->inTenant($t['org'], fn () => $schedule->fresh());
        $this->assertNull($fresh->last_state);
        $this->assertStringContainsString('ads_management', $fresh->last_error);
    }

    public function test_a_manager_sets_a_google_schedule_and_a_reader_cannot(): void
    {
        $t = $this->provisionTenant('Charlie');
        $row = $this->account($t, SocialProvider::GoogleAds->value, '4051729356');
        $body = ['connection_id' => $row->id, 'object_id' => '987', 'object_name' => 'Atlanta', 'days' => [1, 2, 3, 4, 5], 'on_at' => '08:00', 'off_at' => '18:00'];

        $this->signedInAs($this->userWithRole($t, 'Reader', ['ads_performance.view']))
            ->putJson('/api/v1/ad-schedules', $body)->assertForbidden();

        $this->signedInAs($this->userWithRole($t, 'Marketing', ['ads_performance.view', 'ads_performance.manage']))
            ->putJson('/api/v1/ad-schedules', $body)
            ->assertOk()->assertJsonPath('data.days', [1, 2, 3, 4, 5])->assertJsonPath('data.timezone', 'America/New_York');

        $list = $this->signedInAs($this->userWithRole($t, 'Reader2', ['ads_performance.view']))
            ->getJson('/api/v1/ad-schedules?connection_id='.$row->id)->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertNotNull($list[0]['next_change_at']);

        // Worded in the schedule's own timezone: a Friday evening in November.
        Carbon::setTestNow(Carbon::parse('2026-11-13 19:00', 'America/New_York'));
        $row = $this->signedInAs($this->userWithRole($t, 'Reader3', ['ads_performance.view']))
            ->getJson('/api/v1/ad-schedules?connection_id='.$row->id)->json('data.0');
        $this->assertSame('Mon–Fri 8am–6pm EST', $row['summary']);
        $this->assertSame('on at Mon 8:00 AM', $row['next_change']);
        Carbon::setTestNow();
    }
}
