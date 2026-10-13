<?php

namespace Tests\Feature\Leads;

use App\Models\AutomationEvent;
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
                'remote_id' => uniqid(), 'contact_name' => 'Dana', 'phone' => '+12035550100', 'status' => 'new', 'received_at' => now(),
            ]);
            WebBooking::create(['site_id' => $siteId, 'lead_id' => $lead->id, 'service_area_id' => $area->id, 'date' => '2026-10-15',
                'start_time' => '08:00', 'end_time' => '11:00', 'status' => WebBooking::PENDING]);

            return $lead;
        });

        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/booking/approve")->assertOk();
        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/booking/approve")->assertOk();

        $events = AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.booking_approved')->get();
        $this->assertCount(1, $events, 'approving twice is not news');
        $after = $events->first()->payload['after'];
        $this->assertSame('Thursday, October 15, between 8:00 AM and 11:00 AM', $after['booking_when']);
        $this->assertSame('(203) 555-0199', $after['office_phone']);
    }

    public function test_the_booking_link_names_the_lead_and_is_empty_without_a_booking_page(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'source' => Lead::SOURCE_THUMBTACK,
            'remote_id' => uniqid(), 'status' => 'new', 'received_at' => now(),
        ]));

        $this->assertSame('', $this->inTenant($t['org'], fn () => $lead->fresh()->booking_link));

        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('leads.booking_url', $t['org'], 'https://www.a-1chimney.com/booking/'));
        $link = $this->inTenant($t['org'], fn () => $lead->fresh()->booking_link);

        $this->assertStringStartsWith('https://www.a-1chimney.com/booking/?l=', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
        $this->assertSame($lead->id, BookingLink::leadId($q['l']));
    }
}
