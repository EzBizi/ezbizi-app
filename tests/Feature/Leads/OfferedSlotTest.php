<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Leads\RequestedWindow;
use App\Support\Scheduling\SlotOffer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A1 Leads takeover (2026-10-19): the second auto-reply offers a real opening. */
class OfferedSlotTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_days_a_customer_asks_for_are_read_from_their_words(): void
    {
        $wed = CarbonImmutable::parse('2026-10-14');   // a Wednesday
        $this->assertSame(['start' => '2026-10-15', 'end' => '2026-10-15'], RequestedWindow::from('Could you come tomorrow?', $wed));
        $this->assertSame(['start' => '2026-10-14', 'end' => '2026-10-15'], RequestedWindow::from('anytime before Friday', $wed));
        $this->assertSame(['start' => '2026-10-16', 'end' => '2026-10-16'], RequestedWindow::from('Friday works', $wed));
        $this->assertSame(['start' => '2026-10-19', 'end' => '2026-10-25'], RequestedWindow::from('sometime next week', $wed));
        $this->assertSame(['start' => '2026-10-17', 'end' => '2026-10-18'], RequestedWindow::from('this weekend please', $wed));
        $this->assertSame(['start' => '2026-10-14', 'end' => '2026-10-14'], RequestedWindow::from('wednesday', $wed), 'today, not next week');
        $this->assertNull(RequestedWindow::from('How much is a sweep?', $wed));
    }

    public function test_the_lead_offers_the_slot_inside_the_asked_days_and_nothing_without_a_zip(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $asked = [];
        $this->app->instance(SlotOffer::class, new class($asked)
        {
            public function __construct(public array &$asked) {}

            public function for(string $zip, CarbonImmutable $from, ?array $window = null): ?array
            {
                $this->asked[] = [$zip, $window];

                return ['label' => 'Fri Oct 23, 7–11 AM'];
            }
        });

        $this->inTenant($t['org'], function () use ($t, &$asked) {
            $lead = Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => Lead::SOURCE_WEBSITE_CHAT,
                'remote_id' => 'c1', 'postal_code' => '08540', 'status' => Lead::STATUS_NEW, 'received_at' => now()]);
            LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $lead->id, 'remote_id' => 'm1', 'channel' => 'MESSAGE',
                'participant' => 'CONSUMER', 'text' => 'Can you come next week?', 'occurred_at' => now()]);

            $this->assertSame('Fri Oct 23, 7–11 AM', $lead->offered_slot);
            $this->assertSame('08540', $asked[0][0]);
            $this->assertNotNull($asked[0][1], 'the asked-for week was passed on');

            $lead->postal_code = null;
            $this->assertSame('', $lead->offered_slot, 'no ZIP, no offer');
            $this->assertContains('offered_slot', Lead::automationMessageFields());
        });
    }
}
