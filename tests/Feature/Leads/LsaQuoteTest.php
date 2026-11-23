<?php

namespace Tests\Feature\Leads;

use App\Models\Automation;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Support\Leads\LsaQuote;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Shoval via Jose, 2026-11-16: an LSA message's first reply gives prices, openings and asks only for what is missing. */
class LsaQuoteTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function lead(array $t, array $attributes = [], string $said = ''): Lead
    {
        return $this->inTenant($t['org'], function () use ($t, $attributes, $said) {
            $lead = Lead::create($attributes + [
                'organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id,
                'source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => 'MESSAGE', 'remote_id' => uniqid(),
                'contact_name' => 'Andy Bruce', 'phone' => '+14046979986', 'status' => 'new', 'received_at' => now(),
            ]);
            if ($said !== '') {
                LeadConversation::create(['lead_id' => $lead->id, 'organization_id' => $t['org']->id, 'participant' => LeadConversation::FROM_CONSUMER, 'channel' => 'SMS',
                    'text' => $said, 'occurred_at' => now(), 'remote_id' => uniqid()]);
            }

            return $lead->fresh();
        });
    }

    public function test_prices_come_only_from_the_catalogue_the_chosen_ones_and_any_named_in_full(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], function () use ($t) {
            foreach ([['Chimney Sweep - Level 1', 9900], ['Chimney Inspection - Level 1', 9900], ['Dryer Vent Cleaning', 14900], ['Cap Replacement', 149900]] as [$name, $minor]) {
                Service::create(['company_id' => $t['company']->id, 'number' => uniqid(), 'name' => $name, 'price_minor' => $minor]);
            }
        });
        $lead = $this->lead($t, [], 'Need a cap replacement and a sweep');

        // Nothing chosen yet, and "cap replacement" named in full: that one alone.
        $this->assertSame('Cap Replacement: $1,499', $this->inTenant($t['org'], fn () => LsaQuote::prices($lead)));

        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set(LsaQuote::SETTING, $t['company'], 'Chimney Sweep - Level 1, Gutter Cleaning'));
        $this->assertSame("Cap Replacement: \$1,499\nChimney Sweep - Level 1: \$99",
            $this->inTenant($t['org'], fn () => LsaQuote::prices($lead->fresh())), 'Gutter Cleaning is not in the catalogue, so it is never quoted');
    }

    public function test_openings_are_the_booking_windows_for_the_zip_and_none_without_one(): void
    {
        Carbon::setTestNow('2026-11-16T15:00:00Z');
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], function () use ($t) {
            $area = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Cobb']);
            ServiceAreaZip::create(['organization_id' => $t['org']->id, 'franchise_id' => $t['franchise']->id, 'service_area_id' => $area->id, 'zip' => '30152']);
            foreach ([['08:00', '11:00'], ['11:00', '14:00']] as [$from, $to]) {
                ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => 1, 'start_time' => $from, 'end_time' => $to, 'max_jobs' => 1]);
            }
        });

        $windows = $this->inTenant($t['org'], fn () => LsaQuote::windows($this->lead($t, ['postal_code' => '30152']), 2));
        // 10 AM at the office: today's 11-2 has not started, its 8-11 has (the booking widget's own rule).
        $this->assertSame("Mon Nov 16, 11 AM–2 PM\nMon Nov 23, 8–11 AM", $windows);
        $this->assertSame('', $this->inTenant($t['org'], fn () => LsaQuote::windows($this->lead($t))), 'no ZIP, no invented opening');
    }

    public function test_it_asks_only_for_what_neither_the_lead_nor_the_customer_has(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $bare = $this->lead($t, ['contact_name' => null, 'service' => 'fireplace_chimney']);
        $this->assertSame('Could you send your name, the service address with ZIP, whether it is a wood, gas or pellet fireplace or stove and a photo of the chimney or fireplace if you have one?',
            $this->inTenant($t['org'], fn () => LsaQuote::missing($bare)));

        $known = $this->lead($t, ['address' => '1105 Menlo Way NW, Kennesaw, GA 30152', 'service' => 'fireplace_chimney'], 'Wood fireplace, needs a sweep');
        $this->assertSame('Could you send a photo of the chimney or fireplace if you have one?', $this->inTenant($t['org'], fn () => LsaQuote::missing($known)));
    }

    public function test_the_quoting_reply_is_listed_in_recovery_and_its_preview_fills_the_quote(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], function () use ($t) {
            Service::create(['company_id' => $t['company']->id, 'number' => uniqid(), 'name' => 'Chimney Sweep - Level 1', 'price_minor' => 9900]);
            $rule = Automation::create(['organization_id' => $t['org']->id, 'name' => 'LS Text - Prices and openings', 'trigger_key' => 'lead.received', 'is_active' => false]);
            $rule->addVersion(['all' => [['op' => 'eq', 'field' => 'kind', 'value' => 'ls_text']]],
                [['type' => 'reply', 'message' => "Prices: {{price_list}}\nNext openings: {{open_windows}}"]], $t['admin']);
        });
        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set(LsaQuote::SETTING, $t['company'], 'Chimney Sweep - Level 1'));

        $this->signedInAs($t['admin'])->getJson('/api/v1/leads/recovery')->assertOk()
            ->assertJsonPath('data.rules.0.name', 'LS Text - Prices and openings')->assertJsonPath('data.rules.0.is_active', false);
        $text = $this->signedInAs($t['admin'])->postJson('/api/v1/leads/recovery/preview', [
            'text' => "Prices: {{price_list}}\nNext openings: {{open_windows}}", 'company_id' => $t['company']->id,
        ])->assertOk()->json('data.text');
        $this->assertSame('Prices: Chimney Sweep - Level 1: $99', $text, 'the made-up preview lead has no ZIP, so the openings line is dropped');
    }
}
