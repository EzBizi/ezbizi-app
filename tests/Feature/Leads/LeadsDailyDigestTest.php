<?php

namespace Tests\Feature\Leads;

use App\Models\AutomationEvent;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A1 Leads' morning digest, as an automation trigger (Jose, 2026-09-30). */
class LeadsDailyDigestTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_yesterday_is_summed_in_one_line_and_an_empty_day_raises_nothing(): void
    {
        Carbon::setTestNow('2026-09-30T12:00:00Z'); // 8 AM in New York
        $t = $this->provisionTenant('A1-Group');

        $this->artisan('ezbizi:leads-daily-digest')->assertSuccessful();
        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.daily_digest')->count(), 'no leads, no message');

        $this->inTenant($t['org'], function () use ($t) {
            foreach ([['PHONE_CALL', '2026-09-29T14:00:00Z'], ['PHONE_CALL', '2026-09-29T20:00:00Z'], ['MESSAGE', '2026-09-29T21:00:00Z'], ['PHONE_CALL', '2026-09-30T05:00:00Z']] as $i => [$type, $at]) {
                Lead::create([
                    'organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                    'source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => $type, 'remote_id' => "d-$i",
                    'status' => $i === 0 ? Lead::STATUS_BOOKED : Lead::STATUS_NEW, 'received_at' => Carbon::parse($at),
                ]);
            }
        });

        $this->artisan('ezbizi:leads-daily-digest')->assertSuccessful();

        $after = AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.daily_digest')->firstOrFail()->payload['after'];
        // 05:00Z on the 30th is 1 AM on the 30th in New York: today, not yesterday.
        $this->assertSame(3, $after['leads']);
        $this->assertSame(['LS calls' => 2, 'LS texts' => 1], $after['by_source']);
        $this->assertSame(1, $after['booked']);
        $this->assertStringStartsWith('Tue Sep 29: 3 leads (2 LS calls, 1 LS texts)', $after['summary']);
        $this->assertStringNotContainsString("\n", $after['summary']);
        $this->assertSame("*Tue Sep 29: 3 leads*\nLS calls: 2\nLS texts: 1\n*Booked: 1*", $after['summary_lines']);
    }

    /** Desk, 2026-11-11: a company's leads were printed under "Booked"; Jose watches bookings. */
    public function test_leads_and_bookings_per_company_are_two_labelled_blocks(): void
    {
        Carbon::setTestNow('2026-09-30T12:00:00Z');
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $other = \App\Models\Company::create(['organization_id' => $t['org']->id, 'name' => 'Other Co']);
            foreach ([[$t['company'], true], [$t['company'], false], [$t['company'], false], [$other, true], [$other, false]] as $i => [$company, $booked]) {
                Lead::create([
                    'organization_id' => $t['org']->id, 'company_id' => $company->id,
                    'source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => 'PHONE_CALL', 'remote_id' => "c-$i",
                    'status' => $booked ? Lead::STATUS_BOOKED : Lead::STATUS_NEW, 'received_at' => Carbon::parse('2026-09-29T15:00:00Z'),
                ]);
            }
        });

        $this->artisan('ezbizi:leads-daily-digest')->assertSuccessful();

        $after = AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.daily_digest')->sole()->payload['after'];
        $name = $t['company']->name;
        $this->assertEquals([$name => 3, 'Other Co' => 2], $after['by_company']); // a JSON column keeps no key order
        $this->assertEquals([$name => 1, 'Other Co' => 1], $after['booked_by_company']);
        $this->assertSame(
            "*Tue Sep 29: 5 leads*\nLS calls: 5\nLeads by company:\n$name: 3\nOther Co: 2\n*Booked: 2*\nBooked by company:\n$name: 1\nOther Co: 1",
            $after['summary_lines'],
        );
        $this->assertStringContainsString("by company: $name 3, Other Co 2 - 2 booked ($name 1, Other Co 1).", $after['summary']);
    }
}
