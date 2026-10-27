<?php

namespace Tests\Feature\Leads;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\WebBooking;
use App\Support\Settings\SettingsResolver;
use App\Support\Web\BookingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-13: the closed-office reply carries the booking link, and an
 * approved booking sends the customer a confirmation with the number to call.
 */
class BookingApprovedTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_approving_raises_the_confirmation_trigger_once_with_the_words_it_needs(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('leads.office_phone', $t['org'], '(203) 555-0199'));

        $siteId = $this->signedInAs($t['admin'])->postJson('/api/v1/web/sites', [
            'company_id' => $t['company']->getKey(), 'host' => 'a-1chimney.com',
        ])->assertCreated()->json('data.id');

        $lead = $this->inTenant($t['org'], function () use ($t, $siteId) {
            $area = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Fairfield']);
            ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => 4, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 1]);
            $lead = Lead::create([
                'organization_id' => $t['org']->id, 'franchise_id' => $t['franchise']->id, 'source' => Lead::SOURCE_WEBSITE_BOOKING,
                'remote_id' => uniqid(), 'contact_name' => 'Dana', 'phone' => '+12035550100', 'postal_code' => '06810', 'status' => 'new', 'received_at' => now(),
            ]);
            WebBooking::create(['site_id' => $siteId, 'lead_id' => $lead->id, 'service_area_id' => $area->id, 'date' => '2026-10-15',
                'start_time' => '08:00', 'end_time' => '11:00', 'status' => WebBooking::PENDING]);

            return $lead;
        });

        // The dispatcher's fit line (Scheduling's NearbySuggestion::fit): nothing booked nearby.
        $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('data.booking.fit.verdict', 'alone');

        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/booking/approve")->assertOk()
            ->assertJsonPath('data.fit', null);
        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/booking/approve")->assertOk();

        $events = AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.booking_approved')->get();
        $this->assertCount(1, $events, 'approving twice is not news');
        $after = $events->first()->payload['after'];
        $this->assertSame('Thursday, October 15, between 8:00 AM and 11:00 AM', $after['booking_when']);
        $this->assertSame('(203) 555-0199', $after['office_phone']);
        $this->assertSame('Thursday, Oct 15', $after['booking_day']);
        $this->assertSame('8:00 AM - 11:00 AM', $after['booking_window']);
    }

    public function test_the_booking_link_names_the_lead_and_is_empty_without_a_booking_page(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => Lead::SOURCE_THUMBTACK,
            'remote_id' => uniqid(), 'status' => 'new', 'received_at' => now(),
        ]));
        $this->inTenant($t['org'], function () use ($t) {
            // Somewhere the booking page can offer a day (Bar, 2026-10-21: no link without one).
            $area = \App\Models\ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Fairfield']);
            \App\Models\ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => 4, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 1]);
            \App\Models\ServiceAreaZip::create(['franchise_id' => $t['franchise']->id, 'service_area_id' => $area->id, 'zip' => '06810']);
        });

        $this->assertSame('', $this->inTenant($t['org'], fn () => $lead->fresh()->booking_link));

        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('leads.booking_url', $t['org'], 'https://www.a-1chimney.com/booking/'));
        $link = $this->inTenant($t['org'], fn () => $lead->fresh()->booking_link);

        // Short, on the booking page's site (Jose, 2026-10-20): /b/<7 chars>.
        $this->assertMatchesRegularExpression('~^https://www\.a-1chimney\.com/b/[A-Za-z0-9]{7}$~', $link);
        $this->assertSame($lead->id, $this->inTenant($t['org'], fn () => BookingLink::leadId(basename($link))));
        $this->assertSame($link, $this->inTenant($t['org'], fn () => $lead->fresh()->booking_link), 'the same code is sent again');
    }

    /**
     * Bar, 2026-10-21: garage-door customers were sent a booking link and found
     * no availability. A company with nowhere bookable, or a ZIP no area covers,
     * gets no link - the reply drops the line.
     */
    public function test_no_link_where_the_booking_page_could_offer_no_day(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], function () use ($t) {
            app(SettingsResolver::class)->set('leads.booking_url', $t['org'], 'https://www.a-1chimney.com/booking/');
            $lead = fn (?string $zip) => Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'source' => Lead::SOURCE_THUMBTACK, 'remote_id' => uniqid(), 'status' => 'new', 'received_at' => now(), 'postal_code' => $zip]);

            $this->assertSame('', $lead(null)->booking_link, 'no service area at all');

            $area = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Fairfield']);
            \App\Models\ServiceAreaZip::create(['franchise_id' => $t['franchise']->id, 'service_area_id' => $area->id, 'zip' => '06810']);
            $this->assertSame('', $lead('06810')->booking_link, 'an area with no capacity set');

            ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => 4, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 1]);
            $this->assertNotSame('', $lead('06810')->booking_link);
            $this->assertNotSame('', $lead(null)->booking_link, 'no ZIP yet: the page asks for it');
            $this->assertSame('', $lead('90210')->booking_link, 'a ZIP no area covers');
        });
    }

    /**
     * Kayla Culbreth, 2026-10-27: asked for the 28th online, booked in Workiz
     * for the 29th. Approving would have texted her the 28th. A lead with a job
     * shows the job instead, and Approve refuses.
     */
    public function test_a_booking_already_booked_as_a_job_cannot_be_approved(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $siteId = $this->signedInAs($t['admin'])->postJson('/api/v1/web/sites', [
            'company_id' => $t['company']->getKey(), 'host' => 'a-1chimney.com',
        ])->assertCreated()->json('data.id');

        $lead = $this->inTenant($t['org'], function () use ($t, $siteId) {
            $area = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Fairfield']);
            $customer = Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-1', 'name' => 'Kayla']);
            $job = Job::create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Sweep',
                'status' => 'Submitted', 'currency' => 'USD', 'scheduled_for' => '2026-10-29 08:00:00']);
            $lead = Lead::create([
                'organization_id' => $t['org']->id, 'franchise_id' => $t['franchise']->id, 'source' => Lead::SOURCE_WEBSITE_BOOKING,
                'remote_id' => uniqid(), 'contact_name' => 'Kayla', 'phone' => '+17705550100', 'status' => 'new', 'received_at' => now(),
            ]);
            $lead->forceFill(['job_id' => $job->id, 'job_matched_by' => 'phone'])->save();
            WebBooking::create(['site_id' => $siteId, 'lead_id' => $lead->id, 'service_area_id' => $area->id, 'date' => '2026-10-28',
                'start_time' => '08:00', 'end_time' => '11:00', 'status' => WebBooking::PENDING]);

            return $lead;
        });

        $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('data.booking.job.source', 'EzBizi')
            ->assertJsonPath('data.booking.job.when', 'Thursday, Oct 29, 8:00 AM')
            ->assertJsonPath('data.booking.fit', null);

        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/booking/approve")->assertStatus(409);
        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.booking_approved')->count());
        $this->assertSame(WebBooking::PENDING, $this->inTenant($t['org'], fn () => WebBooking::query()->where('lead_id', $lead->id)->value('status')));

        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/booking/decline")->assertOk()
            ->assertJsonPath('data.status', WebBooking::DECLINED);
    }
}
