<?php

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Lead;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Models\WebBooking;
use App\Models\WebSite;
use App\Support\Scheduling\NearbySuggestion;
use App\Support\Settings\SettingsResolver;
use App\Support\Web\BookingLink;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The booking widget (Jose, 2026-10-08): a visitor's ZIP finds its service
 * area's openings, a request holds its window, and a dispatcher's decline in
 * Leads ▸ Online Booking gives the window back.
 */
class WebBookingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_booking_holds_its_window_until_it_is_declined(): void
    {
        [$site, $tenant] = $this->siteWithArea();

        $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=99999')->assertNotFound();

        $days = $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=06810')->assertOk()->json('days');
        $first = $days[0]['date'];
        $this->assertSame([['start_time' => '08:00', 'end_time' => '11:00']], $days[0]['windows']);

        // No robot check, no booking: a spam run would hold real seats.
        $this->postJson('/api/v1/web/booking', $this->booking($site, $first))->assertStatus(422);

        $this->postJson('/api/v1/web/booking', $this->booking($site, $first) + ['captcha' => $this->solved()])->assertCreated();

        $lead = $this->inTenant($tenant['org'], fn () => Lead::query()->where('source', Lead::SOURCE_WEBSITE_BOOKING)->sole());
        $this->assertSame('06810', $lead->postal_code);
        $this->assertSame($tenant['franchise']->id, $lead->franchise_id);

        // The one seat that day is held: the day is gone, and asking for it again is refused.
        $again = $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=06810')->json('days');
        $this->assertNotSame($first, $again[0]['date']);
        $this->postJson('/api/v1/web/booking', $this->booking($site, $first) + ['captcha' => $this->solved()])->assertStatus(409);

        $this->signedInAs($tenant['admin'])->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()->assertJsonPath('data.booking.status', WebBooking::PENDING)->assertJsonPath('data.booking.start_time', '08:00');
        $this->signedInAs($tenant['admin'])->postJson("/api/v1/leads/{$lead->id}/booking/decline")
            ->assertOk()->assertJsonPath('data.status', WebBooking::DECLINED);
        auth()->forgetGuards();

        $this->assertSame($first, $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=06810')->json('days.0.date'));
    }

    /** Jose, 2026-11-11: the SMS box is recorded when ticked, never required. */
    public function test_a_ticked_sms_box_is_recorded_and_an_unticked_one_still_books(): void
    {
        [$site, $tenant] = $this->siteWithArea();
        $days = $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=06810')->json('days');

        $this->postJson('/api/v1/web/booking', $this->booking($site, $days[0]['date']) + ['captcha' => $this->solved()])->assertCreated();
        $this->assertNull($this->inTenant($tenant['org'], fn () => Lead::query()->sole())->sms_consent_at);

        // Another visitor, who ticks it.
        $this->postJson('/api/v1/web/booking', array_merge($this->booking($site, $days[1]['date']), [
            'phone' => '203-555-0199', 'captcha' => $this->solved(), 'sms_consent' => true, 'sms_consent_text' => 'By checking this box, I consent.',
        ]))->assertCreated();

        $lead = $this->inTenant($tenant['org'], fn () => Lead::query()->whereNotNull('sms_consent_at')->sole());
        $this->assertSame('By checking this box, I consent.', $lead->sms_consent_text);
        $this->assertSame($site->host, $lead->sms_consent_site);
        $this->assertNotNull($lead->sms_consent_ip);
        $this->assertNull($lead->marketing_consent_at);
    }

    /** Jose, 2026-10-08: forms ask the ZIP too, so Check availability works on their leads. */
    public function test_a_form_zip_lands_on_the_lead(): void
    {
        [$site, $tenant] = $this->siteWithArea();

        $this->postJson('/api/v1/web/form', ['key' => $site->public_key, 'fields' => ['ZIP code' => '06810'], 'zip' => '06810'])->assertCreated();

        $this->assertSame('06810', $this->inTenant($tenant['org'], fn () => Lead::query()->sole()->postal_code));
    }

    /**
     * Jose, 2026-10-13: an after-hours lead's own booking link books onto that
     * lead (no second lead) and is not asked again for what the lead holds.
     */
    public function test_a_leads_booking_link_books_onto_that_lead(): void
    {
        [$site, $tenant] = $this->siteWithArea();
        $lead = $this->inTenant($tenant['org'], fn () => Lead::create([
            'company_id' => $tenant['company']->getKey(), 'source' => 'website_form', 'remote_id' => 'form_1',
            'contact_name' => 'Dana Smith', 'phone' => '+12035550100', 'postal_code' => '06810',
            'status' => Lead::STATUS_NEW, 'received_at' => now(),
        ]));
        $token = basename($this->inTenant($tenant['org'], fn () => BookingLink::forLead($lead, 'https://www.a-1chimney.com/booking/')));
        // A link sent before the short codes (a signed token) still reads.
        $old = $this->inTenant($tenant['org'], fn () => BookingLink::signed($lead));
        $this->getJson('/api/v1/web/booking/lead?key='.$site->public_key.'&l='.urlencode($old))->assertOk();

        $this->getJson('/api/v1/web/booking/lead?key='.$site->public_key.'&l='.urlencode($token))->assertOk()
            ->assertExactJson(['first_name' => 'Dana', 'zip' => '06810', 'has_name' => true, 'has_phone' => true]);
        $this->getJson('/api/v1/web/booking/lead?key='.$site->public_key.'&l='.urlencode($token.'x'))->assertNotFound();

        $first = $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=06810')->json('days.0.date');
        $this->postJson('/api/v1/web/booking', ['key' => $site->public_key, 'zip' => '06810', 'date' => $first, 'start_time' => '08:00',
            'address' => '1 Main St, Danbury, CT', 'name' => '', 'phone' => '', 'l' => $token, 'captcha' => $this->solved()])->assertCreated();

        $this->inTenant($tenant['org'], function () use ($lead) {
            $this->assertSame(1, Lead::query()->count(), 'no second lead');
            $this->assertSame($lead->id, WebBooking::query()->sole()->lead_id);
        });

        // One request per lead: Leads shows a lead's booking as one row.
        $this->getJson('/api/v1/web/booking/lead?key='.$site->public_key.'&l='.urlencode($token))
            ->assertStatus(409)->assertJsonFragment(['message' => 'You already asked for '.Carbon::parse($first)->format('l, F j').". We'll confirm shortly."]);

        // A lead that already has its visit is told to call.
        $this->inTenant($tenant['org'], fn () => $lead->forceFill(['status' => Lead::STATUS_BOOKED])->save());
        $this->getJson('/api/v1/web/booking/lead?key='.$site->public_key.'&l='.urlencode($token))->assertStatus(409);
    }

    /**
     * Jose, 2026-10-13: the window Scheduling routes best is offered first, and
     * only its date and time leave the server; the suggestion's booked customers
     * and their addresses never do.
     */
    public function test_the_preferred_window_carries_only_a_date_and_time(): void
    {
        [$site] = $this->siteWithArea();
        $first = $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=06810')
            ->assertOk()->assertJsonPath('preferred', null)->json('days.0.date');

        // NearbySuggestion is final; the controller only calls for() on what the container gives.
        $this->app->instance(NearbySuggestion::class, new class($first)
        {
            public function __construct(private string $date) {}

            public function for(): array
            {
                return ['suggested' => [
                    'date' => $this->date, 'window' => ['start_time' => '08:00', 'end_time' => '11:00'],
                    'nearby' => [['name' => 'Pat Customer', 'address' => '9 Elm St']],
                ]];
            }
        });

        $body = $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=06810')->assertOk();
        $this->assertSame(['date' => $first, 'start_time' => '08:00'], $body->json('preferred'));
        $this->assertStringNotContainsString('Pat Customer', $body->getContent());
        $this->assertStringNotContainsString('Elm', $body->getContent());
    }

    /**
     * Jose, 2026-10-20: "the sub companies use the same availability as the
     * parent company". A sub-company's site books into the parent's area; the
     * lead is the sub-company's, never filed under the parent's branch.
     */
    public function test_a_sub_company_books_against_its_parents_availability(): void
    {
        [, $tenant] = $this->siteWithArea();
        $sub = $this->inTenant($tenant['org'], fn () => Company::create([
            'organization_id' => $tenant['org']->id, 'parent_company_id' => $tenant['company']->id, 'name' => 'AngiSweep',
        ]));
        $id = $this->signedInAs($tenant['admin'])->postJson('/api/v1/web/sites', ['company_id' => $sub->id, 'host' => 'angisweep.com'])
            ->assertCreated()->json('data.id');
        auth()->forgetGuards();
        $site = $this->inTenant($tenant['org'], fn () => WebSite::findOrFail($id));

        $first = $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=06810')->assertOk()->json('days.0.date');
        $this->postJson('/api/v1/web/booking', $this->booking($site, $first) + ['captcha' => $this->solved()])->assertCreated();

        $lead = $this->inTenant($tenant['org'], fn () => Lead::query()->sole());
        $this->assertSame($sub->id, $lead->company_id);
        $this->assertNull($lead->franchise_id, 'not filed under the parent company\'s branch');
    }

    /** Today is offered until its window starts, unless the company turned it off (Jose, 2026-10-28). */
    public function test_today_is_offered_before_its_window_starts_unless_turned_off(): void
    {
        [$site, $tenant] = $this->siteWithArea();
        $days = fn () => $this->getJson('/api/v1/web/booking/days?key='.$site->public_key.'&zip=06810')->assertOk()->json('days.0.date');

        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:00', 'America/New_York'));
        $this->assertSame('2026-11-03', $days());
        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:30', 'America/New_York'));
        $this->assertSame('2026-11-04', $days(), 'a window that has started is not offered');

        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:00', 'America/New_York'));
        $this->inTenant($tenant['org'], fn () => app(SettingsResolver::class)->set('scheduling.offer_today', $tenant['company'], false));
        $this->assertSame('2026-11-04', $days());
        $this->postJson('/api/v1/web/booking', $this->booking($site, '2026-11-03') + ['captcha' => $this->solved()])->assertStatus(409);
    }

    /** @return array{0: WebSite, 1: array} */
    private function siteWithArea(): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $id = $this->signedInAs($tenant['admin'])->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(), 'host' => 'a-1chimney.com',
        ])->assertCreated()->json('data.id');
        auth()->forgetGuards();

        $site = $this->inTenant($tenant['org'], function () use ($tenant, $id) {
            $area = ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Fairfield']);

            foreach (range(0, 6) as $weekday) {
                ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => $weekday,
                    'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 1]);
            }

            ServiceAreaZip::create(['franchise_id' => $tenant['franchise']->id, 'service_area_id' => $area->id, 'zip' => '06810']);

            return WebSite::findOrFail($id);
        });

        return [$site, $tenant];
    }

    private function booking(WebSite $site, string $date): array
    {
        return ['key' => $site->public_key, 'zip' => '06810', 'date' => $date, 'start_time' => '08:00',
            'name' => 'Dana', 'phone' => '203-555-0100', 'address' => '1 Main St, Danbury, CT'];
    }

    private function solved(): string
    {
        $c = $this->getJson('/api/v1/web/form/challenge')->json();

        for ($n = 0; $n <= $c['max']; $n++) {
            if (hash('sha256', $c['salt'].$n) === $c['challenge']) {
                return json_encode(['salt' => $c['salt'], 'number' => $n, 'challenge' => $c['challenge'], 'signature' => $c['signature']]);
            }
        }

        $this->fail('unsolvable');
    }
}
