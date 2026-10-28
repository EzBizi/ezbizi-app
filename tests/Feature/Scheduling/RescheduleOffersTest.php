<?php

namespace Tests\Feature\Scheduling;

use App\Console\Commands\SchedulingWarnUnderfilled;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\SchedulingRescheduleOffer;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "An earlier opening is available" (Shoval, 2026-10-26): customers booked
 * later in an area with room are texted once, each text opens a Reschedule
 * lead, and nobody is texted twice.
 */
class RescheduleOffersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00:00', 'America/New_York'));
    }

    public function test_later_customers_are_offered_the_open_day_once_and_replies_land_on_a_reschedule_lead(): void
    {
        $t = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($t['org']);
        $area = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Dallas']);
        foreach (range(0, 6) as $d) {
            ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => $d, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 3]);
        }
        $job = fn (string $when, string $phone) => Job::factory()->create([
            'franchise_id' => $t['franchise']->id, 'service_area_id' => $area->id, 'scheduled_for' => $when, 'status' => 'Submitted',
            'customer_id' => Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => 'Dana Reyes', 'phone' => $phone])->id,
        ]);
        $tooSoon = $job('2026-10-28 09:00:00', '+15553330001');   // 1 day after the open day
        $later = $job('2026-11-03 09:00:00', '+15553330002');
        $noPhone = $job('2026-11-04 09:00:00', '');
        PhoneNumber::factory()->create([
            'connection_id' => PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id])->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id, 'e164' => '+12035551004', 'sms_outbound_allowed' => true,
        ]);
        $as = $this->signedInAs($t['admin']);
        $target = ['area_id' => $area->id, 'date' => '2026-10-27'];

        $plan = $as->postJson('/api/v1/scheduling/overbooking/offers/preview', $target)->assertOk()->json('data');
        $this->assertSame([$later->id], array_column($plan['candidates'], 'job_id'));
        $this->assertSame(3, $plan['open'][0]['remaining']);

        // Someone outside the preview is not texted even if named.
        $as->postJson('/api/v1/scheduling/overbooking/offers/send', $target + ['job_ids' => [$later->id, $tooSoon->id, $noPhone->id]])
            ->assertOk()->assertJsonPath('data.sent', [$later->id]);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $sends = collect($gateway->calls)->where('method', 'sendMessage')->values();
        $this->assertCount(1, $sends);
        $this->assertSame('+15553330002', $sends[0]['args'][2]);
        $this->assertStringContainsString('Hi Dana, ', $sends[0]['args'][3]);
        $this->assertStringContainsString('Tuesday, Oct 27, 8:00 AM–11:00 AM', $sends[0]['args'][3]);

        app(TenantContext::class)->set($t['org']);
        $lead = Lead::query()->where('job_id', $later->id)->sole();
        $this->assertSame(['Reschedule', Lead::SOURCE_EZ_TEXTS], [$lead->lead_type, $lead->source]);
        $this->assertSame(1, SchedulingRescheduleOffer::query()->count());

        // Offered once: the next preview leaves them out.
        $this->assertSame([], $as->postJson('/api/v1/scheduling/overbooking/offers/preview', $target)->json('data.candidates'));
    }

    public function test_sending_needs_scheduling_edit_and_the_send_hours(): void
    {
        $t = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($t['org']);
        $area = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Dallas']);
        $target = ['area_id' => $area->id, 'date' => '2026-10-27', 'job_ids' => [$area->id]];

        $viewer = $this->userWithRole($t, 'Dispatcher', ['scheduling.view', 'scheduling.overbooking.view']);
        $this->signedInAs($viewer)->postJson('/api/v1/scheduling/overbooking/offers/send', $target)->assertForbidden();

        $this->travelTo(CarbonImmutable::parse('2026-10-26 21:30:00', 'America/New_York'));
        $this->signedInAs($t['admin'])->postJson('/api/v1/scheduling/overbooking/offers/send', $target)->assertStatus(422);
    }

    public function test_tomorrow_underfilled_fires_once_at_the_set_hour_with_branch_totals(): void
    {
        $t = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($t['org']);
        $area = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Dallas']);
        ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => 2, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 4]);
        $quiet = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Plano']);
        ServiceAreaSlot::create(['service_area_id' => $quiet->id, 'weekday' => 2, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 1]);

        // 10:00 is not the default 4 PM: nothing.
        $this->artisan('scheduling:warn-underfilled')->doesntExpectOutputToContain('empty spots')->assertSuccessful();

        $this->travelTo(CarbonImmutable::parse('2026-10-26 16:10:00', 'America/New_York'));
        $this->artisan('scheduling:warn-underfilled')->expectsOutputToContain('1 areas with 2+ empty spots')->assertSuccessful();
        $this->artisan('scheduling:warn-underfilled')->doesntExpectOutputToContain('empty spots')->assertSuccessful();

        // Two companies, one email each, scoped to its company (Jose, 2026-10-28).
        $this->assertSame([[$t['company']->id, 'company']], AutomationEvent::query()->get()
            ->map(fn ($e) => [$e->scope_snapshot['id'], $e->scope_snapshot['type']])->all());
        $other = $this->inTenant($t['org'], fn () => Company::create(['name' => 'Bravo Co']));
        $theirs = $this->inTenant($t['org'], fn () => Franchise::create(['company_id' => $other->id, 'name' => 'Connecticut']));
        $this->inTenant($t['org'], fn () => ServiceAreaSlot::create(['service_area_id' => ServiceArea::create(['franchise_id' => $theirs->id, 'name' => 'Hartford'])->id, 'weekday' => 2, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 3]));
        AutomationEvent::query()->delete();
        $this->artisan('scheduling:warn-underfilled', ['--now' => true])->assertSuccessful();
        $events = AutomationEvent::query()->get()->keyBy(fn ($e) => $e->scope_snapshot['id']);
        $this->assertEqualsCanonicalizing([$t['company']->id, $other->id], $events->keys()->all());
        $this->assertStringContainsString('Hartford', $events[$other->id]->payload['after']['summary']);
        $this->assertStringContainsString('Bravo Co', $events[$other->id]->payload['after']['summary']);
        $this->assertStringNotContainsString('Dallas', $events[$other->id]->payload['after']['summary']);
        $this->assertStringNotContainsString('Hartford', $events[$t['company']->id]->payload['after']['summary']);

        $summary = SchedulingWarnUnderfilled::summary([['franchise_name' => 'DFW', 'name' => 'Dallas', 'booked' => 1, 'capacity' => 4, 'gap' => 3]], CarbonImmutable::parse('2026-10-27'));
        $this->assertStringContainsString('### DFW: 3 empty (1 of 4 booked)', $summary);
        $this->assertStringContainsString('- Dallas: 1 booked / 4 available, 3 empty', $summary);
        $this->assertStringContainsString('/scheduling/overbooking?date=2026-10-27)', $summary);
    }
}
