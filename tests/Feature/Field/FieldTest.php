<?php

namespace Tests\Feature\Field;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FieldAlert;
use App\Models\FieldGeocode;
use App\Models\FieldLocation;
use App\Models\FieldPresence;
use App\Models\Job;
use App\Models\Notification;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\FieldGlance;
use App\Support\Modules\ModuleRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Field (docs/superpowers/specs/2026-09-25-field-module-design.md).
 *
 * Coordinates: the job sits at (40.0, -75.0). 0.001° of latitude is ~111 m,
 * so +0.0005 is ~55 m (inside the 150 m default) and +0.01 is ~1.1 km.
 */
class FieldTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private User $tech;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed instant: jobs are dated by wall clock, and a live clock
        // beside a fixed date goes red at midnight.
        $this->travelTo('2026-09-25 14:00:00');

        $this->t = $this->provisionTenant();
        $this->inTenant($this->t['org'], function () {
            app(SettingsResolver::class)->set('timezone', $this->t['org'], 'UTC');
            $this->t['franchise']->forceFill(['timezone' => 'UTC'])->save();
        });

        $this->tech = $this->userWithRole($this->t, 'Technician', ['field.view', 'field.tracked']);
        $this->manager = $this->userWithRole($this->t, 'Dispatcher', [
            'field.view', 'field.dashboard.view', 'field.map.view', 'field.route.view', 'field.manage',
        ]);
    }

    private function mode(string $mode): void
    {
        $this->inTenant($this->t['org'], fn () => app(SettingsResolver::class)->set('field.tracking_mode', $this->t['org'], $mode));
    }

    private function point(float $lat, float $lng, string $at = '2026-09-25 14:00:00'): array
    {
        return ['lat' => $lat, 'lng' => $lng, 'recorded_at' => Carbon::parse($at)->toIso8601ZuluString()];
    }

    private function jobAt(float $lat, float $lng, string $when = '2026-09-25 15:00:00', string $street = '1 Main St'): Job
    {
        return $this->inTenant($this->t['org'], function () use ($lat, $lng, $when, $street) {
            $customer = Customer::factory()->create([
                'owning_franchise_id' => $this->t['franchise']->id,
                'address_line1' => $street, 'city' => 'Town', 'state' => 'PA', 'postal_code' => '19000',
            ]);
            FieldGeocode::query()->create([
                'address_hash' => FieldGeocode::hash(FieldGeocode::addressOf($customer)),
                'address' => FieldGeocode::addressOf($customer),
                'latitude' => $lat, 'longitude' => $lng, 'status' => FieldGeocode::OK,
            ]);

            return Job::factory()->create([
                'franchise_id' => $this->t['franchise']->id,
                'customer_id' => $customer->id,
                'performed_by_user_id' => $this->tech->id,
                'scheduled_for' => $when,
                'duration_minutes' => 60,
            ]);
        });
    }

    /** The manager's map row for one person; the tenant's administrator is tracked too, so rows are found by id. */
    private function mapRow(User $person): ?array
    {
        return collect($this->signedInAs($this->manager)->getJson('/api/v1/field/map')->assertOk()->json('data'))
            ->firstWhere('user.id', $person->id);
    }

    private function send(array ...$points)
    {
        return $this->signedInAs($this->tech)->postJson('/api/v1/field/locations', ['points' => $points]);
    }

    private function events(string $key)
    {
        return $this->inTenant($this->t['org'], fn () => AutomationEvent::query()->where('event_key', $key)->get());
    }

    public function test_the_module_declares_its_grants_and_five_tabs(): void
    {
        $module = app(ModuleRegistry::class)->get('field');

        $this->assertSame(
            ['field.view', 'field.tracked', 'field.manage', 'field.record', 'field.listen',
                'field.dashboard.view', 'field.map.view', 'field.route.view', 'field.recordings.view', 'field.settings.view'],
            $module->permissionKeys(),
        );
    }

    public function test_an_untracked_role_is_told_so_and_cannot_send(): void
    {
        $this->signedInAs($this->manager)->getJson('/api/v1/field/me')
            ->assertOk()->assertJsonPath('data.tracked', false)->assertJsonPath('data.should_track', false);

        $this->signedInAs($this->manager)->postJson('/api/v1/field/locations', ['points' => [$this->point(40, -75)]])
            ->assertForbidden();
    }

    public function test_clocked_in_mode_refuses_points_outside_a_shift_and_keeps_those_inside(): void
    {
        // The default mode.
        $this->send($this->point(40, -75))->assertOk()->assertJsonPath('data.kept', 0)->assertJsonPath('data.refused', 1);

        $this->inTenant($this->t['org'], fn () => TimeClockShift::query()->create([
            'user_id' => $this->tech->id, 'clocked_in_at' => now()->subHour(),
            'scheduled_end_at' => now()->addHours(4),
        ]));

        $this->send($this->point(40, -75, '2026-09-25 12:00:00'), $this->point(40, -75))
            ->assertOk()->assertJsonPath('data.kept', 1)
            ->assertJsonPath('data.status.should_track', true);
    }

    public function test_arriving_and_leaving_a_job_raise_one_event_each(): void
    {
        $this->mode('always');
        $job = $this->jobAt(40.0, -75.0);

        $this->send($this->point(40.01, -75.0, '2026-09-25 13:50:00'))->assertJsonPath('data.kept', 1);
        $this->assertCount(0, $this->events('field.arrived_at_job'));

        $this->send($this->point(40.0005, -75.0, '2026-09-25 13:55:00'));
        $this->send($this->point(40.0004, -75.0, '2026-09-25 13:56:00'));
        $arrived = $this->events('field.arrived_at_job');
        $this->assertCount(1, $arrived, 'arrives once, not once per point');
        $this->assertSame($job->id, $arrived->first()->subject_id);
        $this->assertSame($this->tech->id, $arrived->first()->payload['user_id']);

        $this->send($this->point(40.01, -75.0, '2026-09-25 13:59:00'));
        $this->assertCount(1, $this->events('field.left_job'));
        $this->assertNull($this->inTenant($this->t['org'], fn () => FieldPresence::query()->first()->at_job_id));
    }

    public function test_a_job_on_another_day_is_not_arrived_at(): void
    {
        $this->mode('always');
        $this->jobAt(40.0, -75.0, '2026-09-26 09:00:00');

        $this->send($this->point(40.0, -75.0));

        $this->assertCount(0, $this->events('field.arrived_at_job'));
    }

    public function test_standing_still_away_from_a_job_alerts_once_and_rearms_on_moving(): void
    {
        $this->mode('always');

        $this->send($this->point(41.0, -75.0, '2026-09-25 13:30:00'));
        $this->send($this->point(41.0001, -75.0, '2026-09-25 13:45:00'));
        $this->assertCount(0, $this->events('field.stopped_too_long'), '15 min is under the 20 min default');

        $this->send($this->point(41.0002, -75.0, '2026-09-25 13:51:00'));
        $this->send($this->point(41.0001, -75.0, '2026-09-25 13:55:00'));
        $this->assertCount(1, $this->events('field.stopped_too_long'));
        $this->assertSame(1, $this->inTenant($this->t['org'], fn () => FieldAlert::query()->where('kind', 'stopped')->count()));

        // Moves on, stops again for 20 minutes: a second episode.
        $this->travelTo('2026-09-25 14:30:00');
        $this->send($this->point(41.1, -75.0, '2026-09-25 14:05:00'));
        $this->send($this->point(41.1, -75.0, '2026-09-25 14:26:00'));
        $this->assertCount(2, $this->events('field.stopped_too_long'));
    }

    public function test_the_sweep_geocodes_a_job_two_weeks_out_ahead_of_time(): void
    {
        config(['services.google_maps.server_key' => 'k']);
        Http::fake(['maps.googleapis.com/*' => Http::response([
            'status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 39.3, 'lng' => -76.6]]]],
        ])]);
        $customer = $this->inTenant($this->t['org'], function () {
            $customer = Customer::factory()->create([
                'owning_franchise_id' => $this->t['franchise']->id,
                'address_line1' => '712 Pacific St', 'city' => 'Baltimore', 'state' => 'MD', 'postal_code' => '21211',
            ]);
            Job::factory()->create([
                'franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id,
                'scheduled_for' => '2026-10-07 10:00:00', 'duration_minutes' => 60,
            ]);

            return $customer;
        });

        $this->artisan('ezbizi:field-sweep')->assertSuccessful();

        $this->assertSame(FieldGeocode::OK, $this->inTenant($this->t['org'], fn () => FieldGeocode::query()
            ->where('address_hash', FieldGeocode::hash(FieldGeocode::addressOf($customer)))->value('status')));
    }

    public function test_the_sweep_raises_lost_signal_once_while_the_person_should_be_reporting(): void
    {
        $this->mode('always');
        $this->send($this->point(40.0, -75.0, '2026-09-25 13:59:00'));

        $this->travelTo('2026-09-25 14:10:00');
        $this->artisan('ezbizi:field-sweep')->assertSuccessful();
        $this->assertCount(0, $this->events('field.signal_lost'), '11 min is under the 15 min default');

        $this->travelTo('2026-09-25 14:20:00');
        $this->artisan('ezbizi:field-sweep')->assertSuccessful();
        $this->artisan('ezbizi:field-sweep')->assertSuccessful();
        $this->assertCount(1, $this->events('field.signal_lost'));

        // Clocked-in mode and not clocked in: silence is expected, not an alert.
        $this->inTenant($this->t['org'], fn () => FieldPresence::query()->update(['signal_lost_alerted' => false]));
        $this->mode('clocked_in');
        $this->artisan('ezbizi:field-sweep')->assertSuccessful();
        $this->assertCount(1, $this->events('field.signal_lost'));
    }

    public function test_a_lost_signal_alert_closes_itself_when_the_phone_reports_again(): void
    {
        $this->mode('always');
        $this->send($this->point(40.0, -75.0, '2026-09-25 13:59:00'));
        $this->travelTo('2026-09-25 14:20:00');
        $this->artisan('ezbizi:field-sweep')->assertSuccessful();
        // Only lost signal: standing still 21 minutes there also raised "stopped", which stays open.
        $open = fn () => $this->inTenant($this->t['org'], fn () => FieldAlert::query()
            ->where('kind', FieldAlert::SIGNAL_LOST)->whereNull('acknowledged_at')->count());
        $this->assertSame(1, $open());

        $this->send($this->point(40.0, -75.0, '2026-09-25 14:20:00'));

        $this->assertSame(0, $open());
        $this->assertNull($this->inTenant($this->t['org'], fn () => FieldAlert::query()
            ->where('kind', FieldAlert::SIGNAL_LOST)->value('acknowledged_by')));
    }

    public function test_sos_alerts_notifies_the_managers_who_can_see_the_person_and_is_audited(): void
    {
        $bystander = $this->userWithRole($this->t, 'Office', ['field.view']);

        $this->signedInAs($this->tech)->postJson('/api/v1/field/sos', ['lat' => 40.1, 'lng' => -75.1])
            ->assertCreated()->assertJsonPath('data.kind', 'sos');

        $this->inTenant($this->t['org'], function () use ($bystander) {
            $this->assertCount(1, AutomationEvent::query()->where('event_key', 'field.sos')->get());
            $this->assertTrue(AuditEvent::query()->where('action', 'field.sos')->exists());

            $told = Notification::query()->pluck('user_id')->all();
            $this->assertContains($this->manager->id, $told);
            $this->assertNotContains($bystander->id, $told);
            $this->assertNotContains($this->tech->id, $told);
        });

        $alert = $this->inTenant($this->t['org'], fn () => FieldAlert::query()->first());
        $this->signedInAs($this->manager)->postJson("/api/v1/field/alerts/{$alert->id}/acknowledge")
            ->assertOk()->assertJsonPath('data.acknowledged_at', now()->toIso8601ZuluString());
    }

    /**
     * SOS is a button: the owner out on a job may press it, and pressing it
     * makes no position row of its own.
     */
    public function test_an_administrator_may_press_sos(): void
    {
        $this->signedInAs($this->t['admin'])->postJson('/api/v1/field/sos', ['lat' => 40.0, 'lng' => -75.0])
            ->assertCreated();

        $this->inTenant($this->t['org'], fn () => $this->assertSame(0, FieldPresence::query()->count()));
    }

    public function test_sos_without_a_position_still_goes_out(): void
    {
        $this->signedInAs($this->tech)->postJson('/api/v1/field/sos', [])
            ->assertCreated()->assertJsonPath('data.lat', null);

        $this->inTenant($this->t['org'], fn () => $this->assertSame(
            1, Notification::query()->where('user_id', $this->manager->id)->count(),
        ));
    }

    public function test_a_manager_at_one_franchise_does_not_see_a_technician_at_another(): void
    {
        $this->mode('always');
        $other = $this->extraFranchise($this->t, 'F2');
        $tech = $this->userWithRole($this->t, 'Tech2', ['field.view', 'field.tracked'], onlyAt: $other);
        $local = $this->userWithRole($this->t, 'Local', ['field.view', 'field.map.view'], onlyAt: $this->t['franchise']);

        $this->signedInAs($tech)->postJson('/api/v1/field/locations', ['points' => [$this->point(40, -75)]])
            ->assertJsonPath('data.kept', 1);

        $this->signedInAs($local)->getJson('/api/v1/field/map')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($local)->getJson("/api/v1/field/map?user_id={$tech->id}&date=2026-09-25")->assertNotFound();
        $this->assertNotNull($this->mapRow($tech));
    }

    public function test_the_trail_returns_the_day_in_order(): void
    {
        $this->mode('always');
        $this->send($this->point(40.0, -75.0, '2026-09-25 13:00:00'), $this->point(40.1, -75.0, '2026-09-25 12:00:00'));

        $this->signedInAs($this->manager)->getJson("/api/v1/field/map?user_id={$this->tech->id}&date=2026-09-25")
            ->assertOk()->assertJsonCount(2, 'data.points')->assertJsonPath('data.points.0.lat', 40.1);
    }

    public function test_route_without_a_key_keeps_booked_order_and_says_why(): void
    {
        config(['services.google_maps.server_key' => null]);
        $late = $this->jobAt(40.02, -75.0, '2026-09-25 16:00:00', '2 Oak St');
        $early = $this->jobAt(40.01, -75.0, '2026-09-25 15:00:00', '1 Elm St');
        $this->inTenant($this->t['org'], fn () => Job::factory()->create([
            'franchise_id' => $this->t['franchise']->id,
            'customer_id' => Customer::factory()->create(['owning_franchise_id' => $this->t['franchise']->id, 'address_line1' => null])->id,
            'performed_by_user_id' => $this->tech->id, 'scheduled_for' => '2026-09-25 17:00:00',
        ]));

        $this->signedInAs($this->manager)->getJson("/api/v1/field/route?user_id={$this->tech->id}")
            ->assertOk()
            ->assertJsonPath('data.optimised', false)
            ->assertJsonPath('data.stops.0.job_id', $early->id)
            ->assertJsonPath('data.stops.1.job_id', $late->id)
            ->assertJsonCount(1, 'data.unroutable')
            ->assertJsonPath('data.unroutable.0.why', 'The customer has no street address.');
    }

    public function test_route_uses_googles_order_from_where_the_technician_stands(): void
    {
        config(['services.google_maps.server_key' => 'k']);
        $this->mode('always');
        $a = $this->jobAt(40.02, -75.0, '2026-09-25 15:00:00', '1 A St');
        $b = $this->jobAt(40.01, -75.0, '2026-09-25 15:30:00', '2 B St');
        $c = $this->jobAt(40.03, -75.0, '2026-09-25 17:00:00', '3 C St');
        $this->send($this->point(40.0, -75.0));

        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [[
            // Intermediates were [a, b]; Google says b first.
            'optimizedIntermediateWaypointIndex' => [1, 0],
            'legs' => [
                ['duration' => '600s', 'distanceMeters' => 1100],
                ['duration' => '7200s', 'distanceMeters' => 1100],
                ['duration' => '300s', 'distanceMeters' => 1100],
            ],
        ]]])]);

        $this->signedInAs($this->manager)->getJson("/api/v1/field/route?user_id={$this->tech->id}")
            ->assertOk()
            ->assertJsonPath('data.starts_from', 'current_position')
            ->assertJsonPath('data.optimised', true)
            ->assertJsonPath('data.stops.0.job_id', $b->id)
            ->assertJsonPath('data.stops.0.drive_minutes', 10)
            ->assertJsonPath('data.stops.1.job_id', $a->id)
            // b: arrive 14:10, wait for 15:30, work to 16:30, +2h drive = 18:30 against 15:00.
            ->assertJsonPath('data.stops.1.late', true)
            ->assertJsonPath('data.stops.2.job_id', $c->id);

        Http::assertSent(fn ($r) => $r->hasHeader('X-Goog-Api-Key', 'k') && $r['optimizeWaypointOrder'] === true);
    }

    /** Jose, 2026-09-28: "Always, like any tracked role." Within the window, never outside it. */
    public function test_the_administrator_is_tracked_like_any_tracked_role(): void
    {
        // Default window, not clocked in: refused like anybody else.
        $this->signedInAs($this->t['admin'])->postJson('/api/v1/field/locations', ['points' => [$this->point(40, -75)]])
            ->assertJsonPath('data.kept', 0);

        $this->mode('always');

        $this->signedInAs($this->t['admin'])->getJson('/api/v1/field/me')
            ->assertOk()->assertJsonPath('data.tracked', true)->assertJsonPath('data.should_track', true);
        $this->signedInAs($this->t['admin'])->postJson('/api/v1/field/locations', ['points' => [$this->point(40, -75)]])
            ->assertJsonPath('data.kept', 1);
    }

    public function test_the_map_carries_googles_copyright_for_the_viewport(): void
    {
        config(['services.google_maps.tiles_key' => 't']);
        Http::fake([
            'tile.googleapis.com/v1/createSession*' => Http::response(['session' => 's1', 'expiry' => time() + 86400 * 14]),
            'tile.googleapis.com/tile/v1/viewport*' => Http::response(['copyright' => 'Map data ©2026 Google']),
        ]);

        $this->signedInAs($this->manager)
            ->getJson('/api/v1/field/map/attribution?zoom=12&north=40.1&south=39.9&east=-74.9&west=-75.1')
            ->assertOk()->assertJsonPath('data.copyright', 'Map data ©2026 Google');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'viewport') && $r['session'] === 's1');
    }

    public function test_each_theme_gets_its_own_quiet_map_style(): void
    {
        config(['services.google_maps.tiles_key' => 't']);
        Http::fake(['tile.googleapis.com/v1/createSession*' => Http::sequence()
            ->push(['session' => 'light-s', 'expiry' => time() + 86400 * 14])
            ->push(['session' => 'dark-s', 'expiry' => time() + 86400 * 14])]);

        $this->signedInAs($this->manager)->getJson('/api/v1/field/map/tiles')
            ->assertOk()->assertJsonPath('data.url', fn ($u) => str_contains($u, 'session=light-s'));
        $this->signedInAs($this->manager)->getJson('/api/v1/field/map/tiles?theme=dark')
            ->assertOk()->assertJsonPath('data.url', fn ($u) => str_contains($u, 'session=dark-s'));

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]['styles']);
        $this->assertCount(2, $sent);
        $this->assertNotEquals($sent[0], $sent[1], 'light and dark are different styles');
        $this->assertContains(['featureType' => 'poi', 'stylers' => [['visibility' => 'off']]], $sent[0]);
    }

    public function test_a_tracked_person_who_never_reported_is_listed_without_a_position(): void
    {
        $row = $this->mapRow($this->tech);
        $this->assertNotNull($row);
        $this->assertNull($row['lat']);
        $this->assertNull($row['last_seen_at']);
        $this->assertFalse($row['fresh']);
        $this->assertFalse($row['should_track'], 'Clocked-in mode and not clocked in: off shift.');
        $this->mode('always');
        $this->assertTrue($this->mapRow($this->tech)['should_track']);
    }

    public function test_the_map_shows_only_the_roles_settings_tick(): void
    {
        $office = $this->userWithRole($this->t, 'Office', ['field.view', 'field.tracked']);
        $this->assertNotNull($this->mapRow($office), 'Nothing ticked: every tracked role.');

        $this->inTenant($this->t['org'], fn () => app(SettingsResolver::class)
            ->set('field.map_roles', $this->t['org'], [$this->tech->role_id]));

        $this->assertNotNull($this->mapRow($this->tech));
        $this->assertNull($this->mapRow($office));
        // Still tracked: the setting is what is shown, not who reports.
        $this->signedInAs($office)->getJson('/api/v1/field/me')->assertJsonPath('data.tracked', true);
    }

    public function test_the_role_picker_offers_the_organizations_roles(): void
    {
        $admin = $this->t['admin'];
        $setting = collect($this->signedInAs($admin)
            ->getJson("/api/v1/settings?scope_type=organization&scope_id={$this->t['org']->id}")->assertOk()->json('data'))
            ->firstWhere('key', 'field.map_roles');

        $this->assertNotNull($setting);
        $this->assertContains($this->tech->role_id, array_column($setting['options'], 'value'));
    }

    public function test_the_map_list_says_since_when_how_far_and_how_fast(): void
    {
        $this->mode('always');
        $this->send(
            ['lat' => 40.0, 'lng' => -75.0, 'recorded_at' => '2026-09-25T12:00:00Z', 'speed_mps' => 0],
            ['lat' => 40.1, 'lng' => -75.0, 'recorded_at' => '2026-09-25T13:00:00Z', 'speed_mps' => 13.4],
        );

        $row = $this->mapRow($this->tech);
        $this->assertSame('2026-09-25T12:00:00Z', $row['since']);
        // 0.1 degree of latitude is 11.1 km, 6.9 miles.
        $this->assertSame(6.9, $row['distance_mi']);
        $this->assertSame(30, $row['speed_mph']);
    }

    public function test_the_phone_describes_itself_and_the_map_says_so(): void
    {
        $this->mode('always');
        $this->signedInAs($this->tech)->postJson('/api/v1/field/locations', [
            'points' => [$this->point(40, -75)],
            'device' => ['battery_pct' => 41, 'charging' => true, 'platform' => 'android',
                'os_version' => '15', 'device_model' => 'Samsung SM-S918U', 'app_version' => '0.0.0330'],
        ])->assertJsonPath('data.kept', 1);

        $device = $this->mapRow($this->tech)['device'];
        $this->assertSame(41, $device['battery_pct']);
        $this->assertTrue($device['charging']);
        $this->assertSame('Samsung SM-S918U', $device['model']);
        $this->assertSame('0.0.0330', $device['app_version']);
    }

    public function test_jobs_on_the_map_need_the_jobs_grant_and_come_with_their_place(): void
    {
        $job = $this->jobAt(40.0, -75.0);

        $this->signedInAs($this->manager)->getJson('/api/v1/field/jobs?date=2026-09-25')->assertForbidden();

        $dispatcher = $this->userWithRole($this->t, 'Dispatch2', ['field.view', 'field.map.view', 'jobs.view']);
        $this->signedInAs($dispatcher)->getJson('/api/v1/field/jobs?date=2026-09-25')
            ->assertOk()
            ->assertJsonPath('data.0.job_id', $job->id)
            ->assertJsonPath('data.0.lat', 40)
            ->assertJsonPath('data.0.tech.id', $this->tech->id);
    }

    public function test_eta_ranks_the_people_reporting_now_by_drive_time(): void
    {
        config(['services.google_maps.server_key' => 'k']);
        $this->mode('always');
        $job = $this->jobAt(40.0, -75.0);
        $near = $this->userWithRole($this->t, 'Tech2', ['field.view', 'field.tracked']);
        $this->send($this->point(40.3, -75.0));
        $this->signedInAs($near)->postJson('/api/v1/field/locations', ['points' => [$this->point(40.05, -75.0)]]);
        $dispatcher = $this->userWithRole($this->t, 'Dispatch2', ['field.view', 'field.map.view', 'jobs.view']);

        // Straight-line order is [near, tech]; Google says the far one is faster.
        Http::fake(['routes.googleapis.com/distanceMatrix/*' => Http::response([
            ['originIndex' => 0, 'destinationIndex' => 0, 'duration' => '1500s', 'distanceMeters' => 6000, 'condition' => 'ROUTE_EXISTS'],
            ['originIndex' => 1, 'destinationIndex' => 0, 'duration' => '900s', 'distanceMeters' => 34000, 'condition' => 'ROUTE_EXISTS'],
        ])]);

        $this->signedInAs($dispatcher)->getJson("/api/v1/field/eta?job_id={$job->id}")
            ->assertOk()
            ->assertJsonPath('data.candidates.0.user.id', $this->tech->id)
            ->assertJsonPath('data.candidates.0.minutes', 15)
            ->assertJsonPath('data.candidates.0.assigned', true)
            ->assertJsonPath('data.candidates.1.user.id', $near->id);
    }

    public function test_eta_for_one_person_draws_the_road_and_says_it_in_a_sentence(): void
    {
        config(['services.google_maps.server_key' => 'k']);
        $this->mode('always');
        $job = $this->jobAt(40.0, -75.0);
        $this->send($this->point(40.1, -75.0));
        $dispatcher = $this->userWithRole($this->t, 'Dispatch2', ['field.view', 'field.map.view', 'jobs.view']);
        Http::fake(['routes.googleapis.com/directions/*' => Http::response(['routes' => [[
            'duration' => '1080s', 'distanceMeters' => 12000, 'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC'],
        ]]])]);

        $this->signedInAs($dispatcher)->getJson("/api/v1/field/eta?job_id={$job->id}&user_id={$this->tech->id}")
            ->assertOk()
            ->assertJsonPath('data.minutes', 18)
            ->assertJsonPath('data.polyline', '_p~iF~ps|U_ulLnnqC')
            ->assertJsonPath('data.say', 'Technician is about 18 minutes away and should arrive around 2:18 PM.');
    }

    public function test_a_zip_ranks_the_offices_by_drive_time_or_by_an_estimate_without_maps(): void
    {
        config(['services.google_maps.server_key' => 'k']);
        Http::fake([
            'maps.googleapis.com/*' => Http::sequence()
                // The ZIP, then Alpha Co (Cleveland), then Beta Co (Akron).
                ->push(['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 41.4, 'lng' => -81.6]]]]])
                ->push(['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 41.5, 'lng' => -81.7]]]]])
                ->push(['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 41.1, 'lng' => -81.5]]]]]),
            // Akron (origin 1) is the shorter drive though Cleveland is closer as the crow flies.
            'routes.googleapis.com/*' => Http::response([
                ['originIndex' => 0, 'condition' => 'ROUTE_EXISTS', 'duration' => '2400s', 'distanceMeters' => 16000],
                ['originIndex' => 1, 'condition' => 'ROUTE_EXISTS', 'duration' => '1500s', 'distanceMeters' => 40000],
            ]),
        ]);
        $this->inTenant($this->t['org'], function () {
            $this->t['company']->forceFill(['address_line1' => '100 Main St', 'city' => 'Cleveland', 'state' => 'OH', 'postal_code' => '44114'])->save();
            Company::query()->create(['name' => 'Beta Co', 'address_line1' => '1 Elm St', 'city' => 'Akron', 'state' => 'OH']);
        });

        $this->signedInAs($this->manager)->getJson('/api/v1/field/closest-office?zip=44120')
            ->assertOk()
            ->assertJsonPath('data.offices.0.office.name', 'Beta Co')
            ->assertJsonPath('data.offices.0.minutes', 25)
            ->assertJsonPath('data.offices.0.by', 'drive')
            ->assertJsonPath('data.offices.1.office.name', 'Alpha Co');

        // No key: the cached pins stay, and straight-line distance orders them.
        config(['services.google_maps.server_key' => null]);
        $this->getJson('/api/v1/field/closest-office?zip=44120')
            ->assertOk()
            ->assertJsonPath('data.offices.0.office.name', 'Alpha Co')
            ->assertJsonPath('data.offices.0.by', 'estimate')
            ->assertJsonPath('data.offices.0.similar', true);

        $this->getJson('/api/v1/field/closest-office?zip=abc')->assertStatus(422);
    }

    public function test_leads_users_get_zip_centres_and_the_coverage_points(): void
    {
        $job = $this->jobAt(41.4123, -81.6);
        $leads = $this->userWithRole($this->t, 'Leads', ['leads.view']);
        $this->inTenant($this->t['org'], fn () => FieldGeocode::query()->create([
            'address_hash' => FieldGeocode::hash('44120, USA'), 'address' => '44120, USA',
            'latitude' => 41.47, 'longitude' => -81.58, 'status' => FieldGeocode::OK,
        ]));

        $this->signedInAs($leads)->getJson('/api/v1/field/geocode-zips?zips=44120,99999')
            ->assertOk()->assertJsonPath('data.44120.lat', 41.47)->assertJsonMissingPath('data.99999');
        $leadsPin = $this->getJson('/api/v1/field/coverage?from=2026-09-25&to=2026-09-25')->assertOk()->json('data.jobs.0');
        $this->assertSame(41.41, $leadsPin['lat']);
        $this->assertArrayNotHasKey('customer_id', $leadsPin);
        $this->assertArrayNotHasKey('number', $leadsPin);
        $this->assertArrayNotHasKey('job_id', $leadsPin);

        $this->signedInAs($this->manager)->getJson('/api/v1/field/coverage?from=2026-09-25&to=2026-09-25')
            ->assertJsonPath('data.jobs.0.job_id', $job->id)->assertJsonPath('data.jobs.0.lat', 41.4123);
        $this->signedInAs($leads);
        $this->getJson('/api/v1/field/coverage?from=2026-09-26&to=2026-09-27')->assertJsonCount(0, 'data.jobs');

        $nobody = $this->userWithRole($this->t, 'Nobody', ['field.view']);
        $this->signedInAs($nobody)->getJson('/api/v1/field/geocode-zips?zips=44120')->assertForbidden();
    }

    public function test_headquarters_with_an_address_are_on_the_map_for_their_view_only(): void
    {
        config(['services.google_maps.server_key' => 'k']);
        Http::fake(['maps.googleapis.com/*' => Http::response([
            'status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 41.5, 'lng' => -81.7]]]],
        ])]);

        $this->inTenant($this->t['org'], fn () => $this->t['company']->forceFill([
            'address_line1' => '100 Main St', 'city' => 'Cleveland', 'state' => 'OH', 'postal_code' => '44114',
        ])->save());
        $sibling = $this->inTenant($this->t['org'], fn () => Company::query()->create([
            'name' => 'Sibling Co', 'address_line1' => '1 Elm St', 'city' => 'Akron', 'state' => 'OH',
        ]));
        $local = $this->userWithRole($this->t, 'Local', ['field.view', 'field.map.view'], onlyAt: $this->t['franchise']);

        $this->signedInAs($this->manager)->getJson('/api/v1/field/places')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Co')
            ->assertJsonPath('data.0.lat', 41.5)
            ->assertJsonPath('data.0.has_logo', false);

        // Standing in the franchise: only its own company, never the sibling.
        $this->signedInAs($local)->getJson('/api/v1/field/places')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company_id', $this->t['company']->id);

        // Asked of Google once per address, then remembered.
        Http::assertSentCount(2);
    }

    public function test_the_dashboard_counts_only_people_you_can_see(): void
    {
        $this->mode('always');
        $this->send($this->point(40, -75));

        $this->signedInAs($this->manager)->getJson('/api/v1/field/dashboard')
            ->assertOk()
            // The technician and the administrator.
            ->assertJsonPath('data.counts.tracked', 2)
            ->assertJsonPath('data.counts.reporting', 1);
    }

    public function test_the_dashboard_glance_says_who_is_reporting(): void
    {
        $this->mode('always');
        $this->send($this->point(40, -75));

        $payload = app(FieldGlance::class)
            ->resolve($this->manager->fresh(), DashboardPeriod::for($this->manager), []);

        // The technician reports; the administrator is tracked but silent.
        $this->assertSame(['1 of 2 tracked people are reporting now.'], $payload->data['sentences']);
    }

    public function test_the_glance_lists_what_needs_a_manager_only_when_something_does(): void
    {
        $this->mode('always');
        $this->send($this->point(40, -75));
        $glance = fn () => app(FieldGlance::class)
            ->resolve($this->manager->fresh(), DashboardPeriod::for($this->manager), [])
            ->data;

        $this->assertSame([], $glance()['attention']);

        $this->inTenant($this->t['org'], function () {
            foreach ([FieldAlert::SOS, FieldAlert::STOPPED, FieldAlert::STOPPED, FieldAlert::SIGNAL_LOST] as $kind) {
                FieldAlert::query()->create(['user_id' => $this->tech->id, 'kind' => $kind]);
            }
            // An acknowledged one needs nobody.
            FieldAlert::query()->create(['user_id' => $this->tech->id, 'kind' => FieldAlert::SOS, 'acknowledged_at' => now()]);
        });

        $this->assertSame([
            ['tone' => 'bad', 'text' => '1 SOS waiting'],
            ['tone' => 'bad', 'text' => '1 person lost signal'],
            ['tone' => 'caution', 'text' => '2 people stopped too long'],
        ], $glance()['attention']);
    }

    public function test_erasing_a_person_drops_their_trail(): void
    {
        $this->mode('always');
        $this->send($this->point(40, -75));

        $this->inTenant($this->t['org'], function () {
            $this->assertSame(1, FieldLocation::personalDataAbout($this->tech)->count());
            $this->assertSame(1, FieldPresence::personalDataAbout($this->tech)->count());
        });
    }
}
