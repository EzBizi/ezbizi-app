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
    }
}
