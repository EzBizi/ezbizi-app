<?php

namespace Tests\Feature\Leads;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\WebSite;
use App\Services\Leads\ThumbtackLeads;
use App\Support\Leads\LeadReferral;
use App\Support\Leads\RingCentralLeadCalls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Design 159 (Jose, 2026-10-29): what each provider says beyond the phone, on the lead. */
class LeadSourceDetailsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_page_link_names_its_campaign_and_a_bare_page_names_nothing(): void
    {
        $this->assertSame(['source' => 'google', 'campaign_name' => 'Fall Sweep', 'keyword' => 'chimney sweep', 'page_url' => 'https://a.com/?utm_source=google&utm_campaign=Fall+Sweep&utm_term=chimney+sweep'],
            LeadReferral::fromUrl('https://a.com/?utm_source=google&utm_campaign=Fall+Sweep&utm_term=chimney+sweep'));
        $this->assertSame('Google Ads', LeadReferral::fromUrl('https://a.com/repair?gclid=abc')['source']);
        $this->assertNull(LeadReferral::fromUrl('https://a.com/repair'));
    }

    public function test_caller_id_names_a_person_and_never_a_place_or_a_line(): void
    {
        $this->assertSame(['Smith John', 'Smith John', 'Mary Ann Lee', null, null, null, null],
            array_map(fn ($l) => RingCentralLeadCalls::callerName($l), ['SMITH JOHN', 'SMITH,JOHN', 'MARY ANN LEE', 'BROOKLYN      NY', 'WIRELESS CALLER', 'MKE - SEO - A1 - New Client', '+12035551234']));
    }

    public function test_thumbtack_gives_where_what_and_which_business(): void
    {
        $this->assertSame([
            'address' => '1 Main St Apt 2', 'city' => 'Danbury', 'summary' => 'Smoke comes back in.', 'referral' => ['page_name' => 'A1 Chimney DC'],
        ], ThumbtackLeads::details([
            'business' => ['name' => 'A1 Chimney DC'],
            'request' => ['description' => 'Smoke comes back in.', 'location' => ['address1' => '1 Main St', 'address2' => 'Apt 2', 'city' => 'Danbury']],
        ]));
        $this->assertSame([], ThumbtackLeads::details(['request' => ['location' => ['address1' => '', 'city' => '']]]), 'a thin payload blanks nothing');
    }

    public function test_a_website_form_puts_its_address_service_zip_and_campaign_on_the_lead(): void
    {
        $t = $this->provisionTenant();
        $site = $this->inTenant($t['org'], function () use ($t) {
            $site = new WebSite(['company_id' => $t['company']->id, 'host' => 'a-1chimney.com']);
            $site->forceFill(['token_hash' => hash('sha256', 'x'), 'public_key' => 'pk_test_details'])->save();

            return $site;
        });

        $this->postJson('/api/v1/web/form', ['key' => $site->public_key, 'name' => 'Ada', 'phone' => '2035550101',
            'page_url' => 'https://a-1chimney.com/?utm_source=facebook&utm_campaign=Fall',
            'fields' => ['Street Address' => '9 Elm St', 'City' => 'Danbury', 'Service needed' => 'Chimney sweep', 'ZIP' => '06810']])->assertCreated();

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->sole());
        $this->assertSame(['9 Elm St', 'Danbury', 'Chimney sweep', '06810', 'Fall'],
            [$lead->address, $lead->city, $lead->service, $lead->postal_code, $lead->referral['campaign_name'] ?? null]);
        $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('data.job_type', 'Chimney sweep')->assertJsonPath('data.city', 'Danbury');
        // The same phone again joins that lead, and fills only what it lacked.
        $this->inTenant($t['org'], fn () => $lead->forceFill(['address' => null])->save());
        $this->postJson('/api/v1/web/form', ['key' => $site->public_key, 'name' => 'Ada', 'phone' => '2035550101',
            'fields' => ['Street Address' => '12 Oak Ave', 'City' => 'Bethel']])->assertCreated();
        $this->assertSame(['12 Oak Ave', 'Danbury'], $this->inTenant($t['org'], fn () => [Lead::query()->sole()->address, Lead::query()->sole()->city]));
    }

    public function test_a_call_line_is_where_it_came_from_and_a_known_customer_names_the_number(): void
    {
        $t = $this->provisionTenant();
        $lead = $this->inTenant($t['org'], function () use ($t) {
            Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => 'Pat Lee', 'phone' => '(314) 409-1888']);

            return Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => Lead::SOURCE_RINGCENTRAL,
                'lead_type' => 'PHONE_CALL', 'remote_id' => 'rc_1', 'category' => 'NY - GMB', 'phone' => '+13144091888',
                'phone_digits' => '13144091888', 'status' => 'new', 'received_at' => now()]);
        });

        $this->artisan('ezbizi:leads-fill-details')->assertSuccessful();

        $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('data.contact_name', 'Pat Lee')
            ->assertJsonPath('data.referral.campaign_name', 'NY - GMB')
            ->assertJsonPath('data.job_type', null);
    }
    /** UI-UX, design 159: "Ad question: …" in muted text when the newest message is an ad's preset question. */
    public function test_the_list_says_when_the_last_message_is_an_ad_question(): void
    {
        $t = $this->provisionTenant();
        $this->inTenant($t['org'], function () use ($t) {
            $at = now()->startOfSecond();
            foreach (['+15550000001' => true, '+15550000002' => false] as $phone => $ad) {
                $lead = Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => Lead::SOURCE_WEBSITE_CHAT,
                    'remote_id' => $phone, 'phone' => $phone, 'phone_digits' => Lead::digits($phone), 'contact_name' => $phone,
                    'status' => 'new', 'received_at' => $at, 'last_contact_at' => $at]);
                \App\Models\LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $lead->id, 'remote_id' => 'm'.$phone,
                    'channel' => 'MESSAGE', 'participant' => 'CONSUMER', 'occurred_at' => $at, 'text' => 'Is this available?', 'is_ad_question' => $ad]);
            }
        });

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data');
        $this->assertSame(['+15550000001' => true, '+15550000002' => false],
            collect($rows)->mapWithKeys(fn ($r) => [$r['contact_name'] => $r['last_contact_ad_question']])->sortKeys()->all());
    }
}
