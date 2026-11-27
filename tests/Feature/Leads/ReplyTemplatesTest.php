<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Canned replies beside Send, filled in for the customer and the brand
 * (Jose, 2026-09-30: "Thank you for contacting (company name)").
 */
class ReplyTemplatesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_lead_carries_its_templates_filled_in(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $lead = $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4941850746',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return Lead::create([
                'organization_id' => $t['org']->id,
                'company_id' => $t['company']->id,
                'connection_id' => $row->id,
                'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => 'L-1',
                'contact_name' => 'dana Whitfield',
                'lead_type' => 'MESSAGE',
                'status' => Lead::STATUS_NEW,
                'received_at' => now(),
            ]);
        });

        $templates = $this->signedInAs($t['admin'])
            ->getJson("/api/v1/leads/{$lead->id}")->assertOk()->json('data.reply_templates');

        $this->assertSame('Thank you', $templates[0]['name']);
        $this->assertStringStartsWith("Hi Dana, thank you for contacting {$t['company']->name}!", $templates[0]['text']);

        // {{missing}} names only what the customer has not given: here the
        // name is known, the address and email are not.
        $this->inTenant($t['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)->set(
            'leads.reply_templates', $t['company'], [['label' => 'Book', 'value' => 'May I have your {{missing}}?']],
        ));
        $templates = $this->getJson("/api/v1/leads/{$lead->id}")->assertOk()->json('data.reply_templates');
        $this->assertSame('May I have your full service address and email?', $templates[0]['text']);
    }

    /** Leads > Manage > Templates: one list per company, edited in place. */
    public function test_the_manager_lists_and_saves_a_company_list(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->signedInAs($t['admin']);

        $list = $this->getJson('/api/v1/leads/reply-templates')->assertOk();
        $this->assertTrue($list->json('meta.may_edit'));
        $this->assertSame('Thank you', $list->json('data.0.templates.0.name'));

        $this->putJson("/api/v1/leads/reply-templates/{$t['company']->id}", ['templates' => [
            ['name' => 'Free estimate', 'text' => '{{greeting}}! The visit is a free estimate.'],
        ]])->assertOk()->assertJsonPath('data.templates.0.name', 'Free estimate');

        $this->assertSame('Free estimate', $this->getJson('/api/v1/leads/reply-templates')->json('data.0.templates.0.name'));

        $viewer = $this->userWithRole($t, 'Viewer', ['leads.view']);
        $this->signedInAs($viewer)->putJson("/api/v1/leads/reply-templates/{$t['company']->id}", ['templates' => [
            ['name' => 'x', 'text' => 'y'],
        ]])->assertForbidden();
    }

    /**
     * {{availability}} reads the area covering the ZIP the customer wrote and
     * names its first day with room (Jose, 2026-09-30, Karyle Thatcher, 44313).
     */
    public function test_availability_is_filled_from_the_customers_zip(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-09-30T15:00:00Z'); // a Wednesday
        $t = $this->provisionTenant('A1-Group');

        $lead = $this->inTenant($t['org'], function () use ($t) {
            $area = \App\Models\ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Cleveland']);
            foreach (range(0, 6) as $day) {
                \App\Models\ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => $day, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 2]);
                \App\Models\ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => $day, 'start_time' => '14:00', 'end_time' => '17:00', 'max_jobs' => 2]);
            }
            \App\Models\ServiceAreaZip::create(['organization_id' => $t['org']->id, 'franchise_id' => $t['franchise']->id, 'service_area_id' => $area->id, 'zip' => '44313']);

            $row = SocialConnection::create([
                'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id, 'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4941850746', 'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();
            $lead = Lead::create([
                'organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'connection_id' => $row->id,
                'source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => 'MESSAGE', 'remote_id' => 'L-2', 'contact_name' => 'Karyle Thatcher',
                'status' => Lead::STATUS_NEW, 'received_at' => now(),
            ]);
            \App\Models\LeadConversation::create([
                'organization_id' => $t['org']->id, 'lead_id' => $lead->id, 'remote_id' => 'c-1',
                'channel' => 'MESSAGE', 'participant' => 'CONSUMER', 'occurred_at' => now(),
                'text' => '435 Sampleton Court, Akron, Ohio 44313',
            ]);
            app(\App\Support\Settings\SettingsResolver::class)->set('leads.reply_templates', $t['company'], [
                ['label' => 'Availability', 'value' => 'The soonest availability we have is {{availability}}.'],
            ]);

            return $lead;
        });

        $data = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()->json('data');
        $this->assertSame('44313', $data['details']['zip']);
        $this->assertSame([], $data['reply_templates'] === [] ? ['cannot reply'] : [], 'a chat lead can be replied to');
        $this->assertSame('The soonest availability we have is Thursday, October 1: 8-11am or 2-5pm.', $data['reply_templates'][0]['text']);
    }
}
