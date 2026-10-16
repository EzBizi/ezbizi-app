<?php

namespace Tests\Feature\Web;

use App\Models\AuditEvent;
use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Models\User;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Social\SocialProvider;
use App\Support\Web\Hours\BusinessProfileHours;
use App\Support\Web\Hours\WeekEdit;
use App\Support\Web\HoursWeek;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Rodrigo's bulk hours editor (brief of 2026-09-28), one acceptance criterion
 * at a time: the logic in WeekEdit, then review and apply over HTTP.
 */
class HoursBatchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = "Mon\u{2013}Fri 8:00 AM \u{2013} 5:00 PM · Sat closed · Sun 9:00 AM \u{2013} 1:00 PM";

    // ── The week edit itself ────────────────────────────────────────

    public function test_the_formatter_writes_what_the_parser_reads(): void
    {
        $this->assertSame(self::WEEK, HoursWeek::format(HoursWeek::parse(self::WEEK)));
    }

    public function test_keep_existing_changes_nothing(): void
    {
        $plan = $this->plan([]);

        $this->assertSame('unchanged', $plan['status']);
    }

    public function test_closed_all_day_closes_the_day_without_a_toggle(): void
    {
        $plan = $this->plan([5 => ['action' => 'closed', 'open' => null, 'close' => null], 0 => ['action' => 'closed', 'open' => null, 'close' => null]]);

        $this->assertSame('change', $plan['status']);
        $this->assertTrue($plan['week'][0]['closed']);
        $this->assertTrue($plan['days'][0]['changed']);
        $this->assertFalse($plan['days'][5]['changed'], 'Saturday was closed already');
    }

    public function test_only_the_closing_time_can_change(): void
    {
        $plan = $this->plan([4 => ['action' => 'set', 'open' => null, 'close' => '15:00']]);

        $this->assertSame(['closed' => false, 'open' => '08:00', 'close' => '15:00'], $plan['week'][4]);
        $this->assertSame('08:00', $plan['week'][3]['open'], 'Thursday was left alone');
    }

    public function test_a_closed_day_needs_both_times_to_open(): void
    {
        $plan = $this->plan([5 => ['action' => 'set', 'open' => '09:00', 'close' => null]]);

        $this->assertSame('blocked', $plan['status']);
        $this->assertStringContainsString('Sat', $plan['reasons'][0]);
        $this->assertNull($plan['week']);
    }

    public function test_an_opening_after_the_closing_is_refused(): void
    {
        $this->assertSame('blocked', $this->plan([0 => ['action' => 'set', 'open' => '18:00', 'close' => '17:00']])['status']);
    }

    public function test_hours_convert_into_each_place_zone(): void
    {
        $monday = [0 => ['action' => 'set', 'open' => '08:00', 'close' => '17:00']];

        $chicago = $this->plan($monday, 'America/Chicago', 'America/New_York');
        $la = $this->plan($monday, 'America/Los_Angeles', 'America/New_York');

        $this->assertSame(['closed' => false, 'open' => '07:00', 'close' => '16:00'], $chicago['week'][0]);
        $this->assertSame(['closed' => false, 'open' => '05:00', 'close' => '14:00'], $la['week'][0]);
    }

    public function test_without_conversion_the_wall_clock_is_kept(): void
    {
        $plan = $this->plan([0 => ['action' => 'set', 'open' => '08:00', 'close' => '17:00']], 'America/Los_Angeles');

        $this->assertSame('08:00', $plan['week'][0]['open']);
    }

    public function test_the_floor_applies_after_conversion_and_only_to_the_opening(): void
    {
        $plan = $this->plan([0 => ['action' => 'set', 'open' => '08:00', 'close' => '17:00']], 'America/Los_Angeles', 'America/New_York', '06:00');

        $this->assertSame(['closed' => false, 'open' => '06:00', 'close' => '14:00'], $plan['week'][0]);
        $this->assertTrue($plan['days'][0]['adjusted']);
        $this->assertSame(['open' => '05:00', 'close' => '14:00'], $plan['days'][0]['converted']);
    }

    public function test_a_floor_that_leaves_no_hours_blocks_rather_than_inventing_them(): void
    {
        $plan = $this->plan([0 => ['action' => 'set', 'open' => '08:00', 'close' => '09:00']], 'America/Los_Angeles', 'America/New_York', '06:30');

        $this->assertSame('blocked', $plan['status']);
    }

    public function test_a_time_that_crosses_midnight_is_refused(): void
    {
        $plan = $this->plan([0 => ['action' => 'set', 'open' => '01:00', 'close' => '17:00']], 'America/Los_Angeles', 'America/New_York');

        $this->assertSame('blocked', $plan['status']);
        $this->assertStringContainsString('another day', $plan['reasons'][0]);
    }

    public function test_daylight_saving_moves_the_converted_time(): void
    {
        $monday = [0 => ['action' => 'set', 'open' => '08:00', 'close' => '17:00']];

        // Phoenix keeps standard time all year; New York does not.
        $july = $this->plan($monday, 'America/Phoenix', 'America/New_York', null, '2026-07-01 12:00');
        $january = $this->plan($monday, 'America/Phoenix', 'America/New_York', null, '2026-01-07 12:00');

        $this->assertSame('05:00', $july['week'][0]['open']);
        $this->assertSame('06:00', $january['week'][0]['open']);
    }

    public function test_a_site_without_a_weekly_schedule_needs_every_day(): void
    {
        $one = WeekEdit::plan(null, $this->days([0 => ['action' => 'closed', 'open' => null, 'close' => null]]), 'UTC', null, null, new DateTimeImmutable);
        $all = WeekEdit::plan(null, array_fill(0, 7, ['action' => 'closed', 'open' => null, 'close' => null]), 'UTC', null, null, new DateTimeImmutable);

        $this->assertSame('blocked', $one['status']);
        $this->assertSame('change', $all['status']);
    }

    // ── Review and apply ────────────────────────────────────────────

    public function test_review_then_apply_writes_only_what_was_reviewed(): void
    {
        [$editor, $site] = $this->siteWithHours();

        $review = $this->signedInAs($editor)->postJson('/api/v1/web/hours/review', [
            'site_ids' => [$site], 'destinations' => ['website'],
            'schedule' => ['friday' => ['action' => 'set_hours', 'open' => ['action' => 'keep'], 'close' => ['action' => 'set', 'time' => '15:00']]],
        ])->assertOk()->json('data');

        $this->assertSame('change', $review['places'][0]['status']);
        $this->assertSame(self::WEEK, $this->hours($site), 'a review writes nothing');

        $applied = $this->signedInAs($editor)->postJson('/api/v1/web/hours/apply', ['token' => $review['token']])->assertOk()->json('data');

        $this->assertSame('changed', $applied['results'][0]['status']);
        $this->assertSame("Mon\u{2013}Thu 8:00 AM \u{2013} 5:00 PM · Fri 8:00 AM \u{2013} 3:00 PM · Sat closed · Sun 9:00 AM \u{2013} 1:00 PM", $this->hours($site));

        // The same confirmed request again is Unchanged, not a second write.
        $again = $this->signedInAs($editor)->postJson('/api/v1/web/hours/apply', ['token' => $review['token']])->assertOk()->json('data');
        $this->assertSame('unchanged', $again['results'][0]['status']);
    }

    public function test_a_changed_website_is_told_to_read_its_hours_now(): void
    {
        [$editor, $site, $tenant] = $this->siteWithHours();
        Http::fake(['nj.a-1chimney.com/*' => Http::response(['fetched_at' => 1])]);
        $this->inTenant($tenant['org'], fn () => WebSite::query()->findOrFail($site)
            ->forceFill(['service_user_login' => 'ezbizi-service', 'service_password' => 'secret', 'service_rest_url' => 'https://nj.a-1chimney.com/wp-json/'])->save());

        $review = $this->signedInAs($editor)->postJson('/api/v1/web/hours/review', [
            'site_ids' => [$site], 'destinations' => ['website'], 'schedule' => ['monday' => ['action' => 'closed']],
        ])->json('data.token');
        $this->signedInAs($editor)->postJson('/api/v1/web/hours/apply', ['token' => $review])->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://nj.a-1chimney.com/wp-json/ezbizi/v1/refresh');
    }

    public function test_one_sites_hours_convert_from_another_zone_on_save(): void
    {
        [$editor, $site] = $this->siteWithHours();

        // Typed in Central time, saved on a New York site: an hour later there.
        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site}/values", [
            'values' => ['hours' => "Mon\u{2013}Sun 8:00 AM \u{2013} 5:00 PM"], 'hours_timezone' => 'America/Chicago',
        ])->assertOk();
        $this->assertSame("Mon\u{2013}Sun 9:00 AM \u{2013} 6:00 PM", $this->hours($site));

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site}/values", [
            'values' => ['hours' => "Mon\u{2013}Sun 8:00 PM \u{2013} 11:30 PM"], 'hours_timezone' => 'America/Chicago',
        ])->assertStatus(422);
        $this->assertSame("Mon\u{2013}Sun 9:00 AM \u{2013} 6:00 PM", $this->hours($site), 'a refused conversion writes nothing');
    }

    public function test_a_review_that_went_stale_is_not_applied(): void
    {
        [$editor, $site] = $this->siteWithHours();

        $token = $this->signedInAs($editor)->postJson('/api/v1/web/hours/review', [
            'site_ids' => [$site], 'destinations' => ['website'],
            'schedule' => ['monday' => ['action' => 'closed']],
        ])->json('data.token');

        WebValue::query()->withoutGlobalScopes()->where('site_id', $site)->where('key', 'hours')->update(['value' => "Mon\u{2013}Sun 7:00 AM \u{2013} 7:00 PM"]);

        $applied = $this->signedInAs($editor)->postJson('/api/v1/web/hours/apply', ['token' => $token])->json('data');

        $this->assertSame('stale', $applied['results'][0]['status']);
        $this->assertSame("Mon\u{2013}Sun 7:00 AM \u{2013} 7:00 PM", $this->hours($site));
    }

    public function test_a_review_is_only_its_reviewers(): void
    {
        [$editor, $site, $tenant] = $this->siteWithHours();
        $other = $this->editorFor($tenant, 'Other Editor');

        $token = $this->signedInAs($editor)->postJson('/api/v1/web/hours/review', [
            'site_ids' => [$site], 'destinations' => ['website'], 'schedule' => ['monday' => ['action' => 'closed']],
        ])->json('data.token');

        $this->signedInAs($other)->postJson('/api/v1/web/hours/apply', ['token' => $token])->assertStatus(409);
    }

    public function test_a_viewer_cannot_review_changes(): void
    {
        [, $site, $tenant] = $this->siteWithHours();
        $viewer = $this->userWithRole($tenant, 'Viewer', ['web_management.view', 'web_management.seo.view']);

        $this->signedInAs($viewer)->postJson('/api/v1/web/hours/review', [
            'site_ids' => [$site], 'destinations' => ['website'], 'schedule' => [],
        ])->assertForbidden();
    }

    public function test_business_profile_is_built_and_switched_off(): void
    {
        [$editor, , $tenant] = $this->siteWithHours();
        $location = $this->listing($tenant);

        $review = $this->signedInAs($editor)->postJson('/api/v1/web/hours/review', [
            'location_ids' => [$location->id], 'destinations' => ['google_business_profile'],
            'schedule' => ['monday' => ['action' => 'closed']],
        ])->assertOk()->json('data');

        $this->assertSame('unavailable', $review['places'][0]['status']);
        $this->assertStringContainsString('not switched on', $review['places'][0]['reasons'][0]);
    }

    /**
     * Jose, 2026-10-16 (A1 Leads takeover): a location is chosen as a location;
     * review reads Google live, apply writes, reads Google again, and audits.
     */
    public function test_business_profile_hours_are_read_live_written_and_confirmed(): void
    {
        config(['services.google_business_profile.hours_write' => true, 'services.business_profile.client_id' => 'cid', 'services.business_profile.client_secret' => 'cs']);
        [$editor, , $tenant] = $this->siteWithHours();
        $location = $this->listing($tenant);
        $before = BusinessProfileHours::regularHours($this->weekArray());
        $after = $before;
        $after['periods'] = array_values(array_filter($after['periods'], fn ($p) => $p['openDay'] !== 'MONDAY'));
        $stored = $before;

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'mybusinessbusinessinformation.googleapis.com/*' => function ($request) use (&$stored) {
                if ($request->method() === 'PATCH') {
                    $stored = $request['regularHours'];

                    return Http::response([]);
                }

                return Http::response(['regularHours' => $stored]);
            },
        ]);

        $review = $this->signedInAs($editor)->postJson('/api/v1/web/hours/review', [
            'location_ids' => [$location->id], 'destinations' => ['google_business_profile'],
            'schedule' => ['monday' => ['action' => 'closed']],
        ])->assertOk()->json('data');
        $this->assertSame('change', $review['places'][0]['status']);
        $this->assertSame('A-1 Chimney Hallandale', $review['places'][0]['name']);

        $applied = $this->signedInAs($editor)->postJson('/api/v1/web/hours/apply', ['token' => $review['token']])->assertOk()->json('data');

        $this->assertSame('changed', $applied['results'][0]['status']);
        $this->assertEquals($after, $stored);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), $location->remote_name.'?updateMask=regularHours'));
        $this->inTenant($tenant['org'], fn () => $this->assertSame(1, AuditEvent::query()->where('action', 'web.hours.changed')->where('target_id', $location->id)->count()));
    }

    public function test_a_location_google_refuses_is_blocked_not_unconfirmed(): void
    {
        config(['services.google_business_profile.hours_write' => true, 'services.business_profile.client_id' => 'cid', 'services.business_profile.client_secret' => 'cs']);
        [$editor, , $tenant] = $this->siteWithHours();
        $location = $this->listing($tenant);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'mybusinessbusinessinformation.googleapis.com/*' => fn ($r) => $r->method() === 'PATCH'
                ? Http::response(['error' => ['message' => 'The caller does not have permission']], 403)
                : Http::response(['regularHours' => BusinessProfileHours::regularHours($this->weekArray())]),
        ]);

        $token = $this->signedInAs($editor)->postJson('/api/v1/web/hours/review', [
            'location_ids' => [$location->id], 'destinations' => ['google_business_profile'],
            'schedule' => ['monday' => ['action' => 'closed']],
        ])->json('data.token');
        $result = $this->signedInAs($editor)->postJson('/api/v1/web/hours/apply', ['token' => $token])->assertOk()->json('data.results.0');

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsString('does not have permission', $result['reason']);
    }

    public function test_business_profile_hours_translate_both_ways(): void
    {
        $week = HoursWeek::parse(self::WEEK);
        $week = array_map(fn ($d) => ['closed' => $d['closed'], 'open' => $d['open'], 'close' => $d['close']], $week);

        $this->assertSame($week, BusinessProfileHours::weekFrom(BusinessProfileHours::regularHours($week)));
        $this->assertNull(BusinessProfileHours::weekFrom(['periods' => [
            ['openDay' => 'MONDAY', 'openTime' => ['hours' => 8], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 12]],
            ['openDay' => 'MONDAY', 'openTime' => ['hours' => 13], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17]],
        ]]), 'split hours are not a week this editor can hold');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /** The test week as the seven days a destination reads. */
    private function weekArray(): array
    {
        return array_map(fn ($d) => ['closed' => $d['closed'], 'open' => $d['open'], 'close' => $d['close']], HoursWeek::parse(self::WEEK));
    }

    /** A Business Profile location on a connected account, in the tenant's company. */
    private function listing(array $tenant): BusinessLocation
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $connection = SocialConnection::create([
                'organization_id' => $tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $tenant['company']->id, 'provider' => SocialProvider::GoogleBusinessProfile->value,
                'remote_id' => 'accounts/1', 'display_name' => 'A-1 Chimney',
            ]);
            $connection->connect(['refresh_token' => 'rt-good']);
            $connection->save();

            return BusinessLocation::create([
                'organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id,
                'connection_id' => $connection->id, 'remote_name' => 'locations/123', 'title' => 'A-1 Chimney Hallandale',
            ]);
        });
    }

    private function plan(array $edits, string $zone = 'America/New_York', ?string $source = null, ?string $floor = null, string $now = '2026-09-28 12:00'): array
    {
        $week = array_map(fn ($d) => ['closed' => $d['closed'], 'open' => $d['open'], 'close' => $d['close']], HoursWeek::parse(self::WEEK));

        return WeekEdit::plan($week, $this->days($edits), $zone, $source, $floor, new DateTimeImmutable($now, new \DateTimeZone('UTC')));
    }

    private function days(array $edits): array
    {
        return array_replace(array_fill(0, 7, ['action' => 'keep', 'open' => null, 'close' => null]), $edits);
    }

    /** @return array{0: User, 1: string, 2: array} */
    private function siteWithHours(): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->id, 'host' => 'nj.a-1chimney.com',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site}/values", [
            'values' => ['hours' => self::WEEK, 'timezone' => 'America/New_York'],
        ])->assertOk();

        return [$editor, $site, $tenant];
    }

    private function editorFor(array $tenant, string $role = 'Web Editor'): User
    {
        return $this->userWithRole($tenant, $role, ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view', 'business_profile.view', 'business_profile.edit']);
    }

    private function hours(string $site): ?string
    {
        return WebValue::query()->withoutGlobalScopes()->where('site_id', $site)->where('path', '')->where('key', 'hours')->value('value');
    }
}
