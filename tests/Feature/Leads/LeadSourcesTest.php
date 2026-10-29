<?php

namespace Tests\Feature\Leads;

use App\Models\BusinessLocation;
use App\Models\Lead;
use App\Models\SocialConnection;
use App\Models\WebSite;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-29: Connections' Website entry was one sentence, and WhatsApp, Facebook, Instagram and Business Profile were missing. */
class LeadSourcesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_source_lists_its_accounts_with_their_leads(): void
    {
        $t = $this->provisionTenant();
        $this->inTenant($t['org'], function () use ($t) {
            foreach (['a.example.com' => '1.26.22', 'b.example.com' => '1.26.21'] as $host => $version) {
                $site = new WebSite(['company_id' => $t['company']->id, 'host' => $host]);
                $site->forceFill(['token_hash' => hash('sha256', $host), 'plugin_version' => $version])->save();
            }
            $this->lead($t, Lead::SOURCE_WEBSITE_CHAT, ['site_host' => 'a.example.com']);
            $this->lead($t, Lead::SOURCE_WEBSITE_FORM, ['site_host' => 'a.example.com']);

            WhatsAppNumber::create(['organization_id' => $t['org']->id, 'phone_number_id' => 'PN1', 'waba_id' => 'W1', 'display_number' => '+1 555 0100']);
            $wa = $this->lead($t, Lead::SOURCE_WHATSAPP);
            WhatsAppContact::create(['organization_id' => $t['org']->id, 'phone_number_id' => 'PN1', 'counterparty' => '15550001111', 'lead_id' => $wa->id]);

            $page = $this->connection($t, 'facebook_page', 'Chimney Page');
            $this->lead($t, Lead::SOURCE_FACEBOOK, ['connection_id' => $page->id]);

            $gbp = $this->connection($t, 'google_business_profile', 'A1 Profile');
            foreach ([$t['franchise']->id ?? null, null] as $branch) {
                BusinessLocation::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'franchise_id' => $branch,
                    'connection_id' => $gbp->id, 'remote_name' => 'locations/'.uniqid(), 'title' => 'A1', 'address_line1' => '1 Main St', 'city' => 'X', 'state' => 'TX']);
            }
        });

        $r = $this->signedInAs($t['admin'])->getJson('/api/v1/leads/sources')->assertOk();

        $this->assertSame('1.26.22', $r->json('data.web.latest_plugin_version'));
        $this->assertSame([['a.example.com', false, 2], ['b.example.com', true, 0]],
            array_map(fn ($s) => [$s['host'], $s['outdated'], $s['leads']], $r->json('data.web.sites')));
        $this->assertNull($r->json('data.web.sites.0.booking_on'));
        $this->assertTrue($r->json('data.web.sites.0.may_edit_chat'));
        $this->assertSame(1, $r->json('data.whatsapp.0.leads'));
        $this->assertSame([['Chimney Page', 1]], array_map(fn ($c) => [$c['name'], $c['leads']], $r->json('data.facebook')));
        $this->assertSame([], $r->json('data.instagram'));
        $this->assertSame(2, $r->json('data.google_business_profile.0.locations'));
    }

    public function test_a_source_the_role_does_not_show_is_left_out(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Web only', ['leads.view', 'leads.website_chat.view']);

        $this->inTenant($t['org'], function () use ($t) {
            $site = new WebSite(['company_id' => $t['company']->id, 'host' => 'a.example.com']);
            $site->forceFill(['token_hash' => hash('sha256', 'a')])->save();
        });

        $data = $this->signedInAs($viewer)->getJson('/api/v1/leads/sources')->assertOk()->json('data');

        $this->assertSame(['web', 'google_business_profile'], array_keys($data));
        // A leads role edits no website: that is Web's gate.
        $this->assertSame([false, false, false], [$data['web']['sites'][0]['may_edit'], $data['web']['sites'][0]['may_edit_chat'], $data['web']['sites'][0]['may_set_primary']]);
    }

    private function lead(array $t, string $source, array $extra = []): Lead
    {
        return Lead::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => $source, 'lead_type' => 'MESSAGE',
            'remote_id' => uniqid(), 'status' => 'new', 'received_at' => now(), ...$extra,
        ]);
    }

    private function connection(array $t, string $provider, string $name): SocialConnection
    {
        return SocialConnection::create(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
            'scope_id' => $t['company']->id, 'provider' => $provider, 'remote_id' => uniqid(), 'display_name' => $name]);
    }
}
