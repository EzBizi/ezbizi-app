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
    }
}
