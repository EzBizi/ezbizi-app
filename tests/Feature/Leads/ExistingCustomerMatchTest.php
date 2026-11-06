<?php

namespace Tests\Feature\Leads;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-06: "i don't see a single lead from meta with it" and "All
 * the leads should, like this things need to be basic". Every source is
 * matched to the customer it already is: by its phone, then a phone or email
 * the customer wrote, then its own email. Never by name.
 */
class ExistingCustomerMatchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array<string, array{string, array<string, string>, ?string}> */
    public static function sources(): array
    {
        $phone = ['phone' => '+1 216 555 0100'];
        $email = ['email' => 'Jane@Example.com'];

        return [
            'Messenger, a phone in the thread' => [Lead::SOURCE_FACEBOOK, [], 'sure, my number is (216) 555-0100'],
            'Instagram, an email in the thread' => [Lead::SOURCE_INSTAGRAM, [], 'jane@example.com is best'],
            'Messenger, its own email' => [Lead::SOURCE_FACEBOOK, $email, null],
            'WhatsApp' => [Lead::SOURCE_WHATSAPP, $phone, null],
            'Google LS' => [Lead::SOURCE_LOCAL_SERVICES, $phone, null],
            'Thumbtack' => [Lead::SOURCE_THUMBTACK, $phone, null],
            'website chat, an email in the thread' => [Lead::SOURCE_WEBSITE_CHAT, [], 'email me at JANE@example.com'],
            'website form' => [Lead::SOURCE_WEBSITE_FORM, $email, null],
            'online booking' => [Lead::SOURCE_WEBSITE_BOOKING, $phone, null],
            'a call' => [Lead::SOURCE_EZ_CALLS, $phone, null],
            'a text' => [Lead::SOURCE_EZ_TEXTS, $phone, null],
        ];
    }

    /** @param array<string, string> $contact */
    #[DataProvider('sources')]
    public function test_every_source_finds_its_customer(string $source, array $contact, ?string $wrote): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        [$lead, $customer] = $this->inTenant($t['org'], function () use ($t, $source, $contact, $wrote) {
            $customer = Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-0001',
                'name' => 'Jane Doe', 'phone' => '(216) 555-0100', 'email' => 'jane@example.com']);
            Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-0002', 'name' => 'Jane Doe']);
            // A connected account behind it, or the list hides a Google, Meta or Thumbtack lead.
            $connection = new SocialConnection;
            $connection->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'provider' => SocialProvider::GoogleAds->value, 'remote_id' => '1', 'display_name' => 'x']);
            $connection->connect(['refresh_token' => 'rt']);
            $connection->save();
            $lead = Lead::create(['organization_id' => $t['org']->id, 'source' => $source, 'remote_id' => uniqid(),
                'connection_id' => $connection->id, 'contact_name' => 'Jane Doe', 'status' => 'new', 'received_at' => now(), ...$contact]);
            if ($wrote !== null) {
                LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $lead->id, 'remote_id' => uniqid(),
                    'channel' => LeadConversation::CHANNEL_MESSAGE, 'participant' => LeadConversation::FROM_CONSUMER,
                    'occurred_at' => now(), 'text' => $wrote]);
            }

            return [$lead, $customer];
        });

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data'))
            ->firstWhere('id', $lead->id);
        $this->assertSame($customer->id, $row['customer']['id'] ?? null, 'the list');

        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()->json('data');
        $this->assertSame([$customer->id], array_column($detail['customer_matches'], 'id'), 'the lead');
    }

    /** The control: a name alone attaches nobody, however many Jane Does there are. */
    public function test_a_name_alone_matches_nobody(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $lead = $this->inTenant($t['org'], function () use ($t) {
            Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-0001', 'name' => 'Jane Doe',
                'phone' => '(216) 555-0100', 'email' => 'jane@example.com']);

            return Lead::create(['organization_id' => $t['org']->id, 'source' => Lead::SOURCE_FACEBOOK, 'remote_id' => uniqid(),
                'contact_name' => 'Jane Doe', 'status' => 'new', 'received_at' => now()]);
        });

        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()->json('data');
        $this->assertSame([], $detail['customer_matches']);
        $this->assertNull($detail['customer']);
    }
}
