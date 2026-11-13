<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\SocialConnection;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Leads — enquiries from connected advertising (EZ Leads).
 *
 * The cases here are the ones that would let somebody see an enquiry that is
 * not theirs, edit a record of what a member of the public actually said, or
 * read an empty list and take it for a quiet week when it means nothing is
 * connected at all.
 */
class LeadsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/leads';

    private const PHONE = '+1 (555) 123-4567';

    /** @param array{org: Organization} $t */
    private ?SocialConnection $account = null;

    /**
     * The account these enquiries arrived through.
     *
     * Every row here is `local_services`, and since 2026-09-16 a Google or
     * Thumbtack enquiry with no connection is hidden from the list - it means
     * the account was disconnected. Without this the fixtures were rows that
     * cannot exist in production, and every assertion below would be about the
     * wrong thing.
     */
    private function account(array $t): SocialConnection
    {
        return $this->account ??= $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id'        => $t['org']->id,
                'provider'        => SocialProvider::GoogleAds->value,
                'remote_id'       => '4051729356',
                'display_name'    => 'A1 Chimney',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    /*
     * Every row here is `local_services`, so every role that reads the list
     * needs `leads.local_services.view` beside `leads.view` - the source became
     * a separate grant on 2026-09-17. The admin fixtures are unaffected:
     * Role::isAdministrator bypasses the permission table entirely.
     */
    private function lead(array $t, array $attributes = []): Lead
    {
        $account = $this->account($t);

        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'connection_id'   => $account->getKey(),
            'source'          => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id'       => 'lead-'.bin2hex(random_bytes(6)),
            'contact_name'    => 'Dana Whitfield',
            'phone'           => self::PHONE,
            'phone_digits'    => Lead::digits(self::PHONE),
            'category'        => 'chimney_sweep',
            'status'          => Lead::STATUS_NEW,
            'received_at'     => now()->subHour(),
            ...$attributes,
        ]));
    }

    /**
     * A lead filed at organization level belongs to everybody who can see the tab.
     *
     * Every lead from an organization-scoped connection carries company_id AND
     * franchise_id null. The reach filter matched company OR franchise in the
     * user's reach, so such a row matched neither and vanished for every user
     * who was not organization-level - while LeadPolicy::view still allowed it
     * by id. BusinessProfileController::index already carried the clause this
     * needs, with a comment saying why; Leads and Reviews never got it.
     */
    public function test_an_organization_level_lead_is_listed_for_a_branch_user(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->lead($t);   // company_id and franchise_id both null

        $branch = $this->userWithRole($t, 'Branch', ['leads.view', 'leads.local_services.view'], onlyAt: $t['franchise']);

        $this->signedInAs($branch)
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * A number that is already a customer is shown as that customer, with
     * their jobs - Jose, 2026-09-28. The customer's phone is typed another way
     * and carries no country code; the last ten digits decide.
     */
    public function test_a_known_number_is_shown_as_the_customer_with_their_jobs(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t, ['contact_name' => null]);
        $this->inTenant($t['org'], function () use ($t) {
            $customer = Customer::create([
                'owning_franchise_id' => $t['franchise']->id,
                'number' => 'C-0042',
                'name' => 'Marcus Bell',
                'phone' => '('.substr(Lead::digits(self::PHONE), -10, 3).') '.substr(Lead::digits(self::PHONE), -7, 3).'-'.substr(Lead::digits(self::PHONE), -4),
                'city' => 'Ridgefield',
                'state' => 'CT',
            ]);
            foreach ([['Done', 45000, '2026-09-01'], ['Canceled', 30000, '2026-09-10']] as [$status, $minor, $at]) {
                Job::create([
                    'franchise_id' => $t['franchise']->id,
                    'customer_id' => $customer->id,
                    'number' => 'J-'.$status,
                    'title' => 'Chimney sweep',
                    'status' => $status,
                    'scheduled_for' => $at,
                    'amount_minor' => $minor,
                    'currency' => 'USD',
                ]);
            }
        });

        $row = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk()->json('data.0');
        $this->assertSame('Marcus Bell', $row['customer']['name']);

        $customer = $this->getJson(self::ENDPOINT.'/'.$lead->id)->assertOk()->json('data.customer');
        $this->assertSame('Marcus Bell', $customer['name']);
        $this->assertSame('Ridgefield, CT', $customer['address']);
        $this->assertSame(2, $customer['jobs_total']);
        $this->assertSame(45000, $customer['revenue_minor'], 'a cancelled job is not revenue');
        $this->assertSame('J-Canceled', $customer['jobs'][0]['number'], 'newest first');

        // Without the customers permission the lead says nothing about them.
        $viewer = $this->userWithRole($t, 'Leads only', ['leads.view', 'leads.local_services.view']);
        $this->assertNull($this->signedInAs($viewer)->getJson(self::ENDPOINT)->assertOk()->json('data.0.customer'));
        $this->assertNull($this->getJson(self::ENDPOINT.'/'.$lead->id)->assertOk()->json('data.customer'));
    }

    /**
     * The thread's extras (Jose, 2026-09-28): who sent each of our replies,
     * an iPhone reaction recognised as one, and the address, ZIP and name read
     * out of what the customer wrote, with the Google Ads account and status.
     */
    /** Jose, 2026-11-05: Kristen, 06820, booked with Franchise blank. */
    public function test_a_lead_with_no_branch_suggests_the_one_covering_its_zip(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($t, $f) {
            $area = ServiceArea::create(['organization_id' => $t['org']->id, 'franchise_id' => $f->id, 'name' => 'Fairfield']);
            ServiceAreaZip::create(['organization_id' => $t['org']->id, 'franchise_id' => $f->id, 'service_area_id' => $area->id, 'zip' => '06820']);
        });
        $covered = $this->lead($t, ['postal_code' => '06820', 'company_id' => $t['company']->id, 'franchise_id' => null]);
        $elsewhere = $this->lead($t, ['postal_code' => '90210', 'company_id' => $t['company']->id, 'franchise_id' => null]);

        $this->assertSame($f->id, $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'/'.$covered->id)->json('data.book_franchise_id'));
        $this->assertNull($this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'/'.$elsewhere->id)->json('data.book_franchise_id'));
    }

    public function test_the_thread_names_senders_reads_reactions_and_details(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t, ['source_status' => 'ACTIVE']);
        $this->inTenant($t['org'], function () use ($t, $lead) {
            $say = fn (string $who, string $text, int $ago) => LeadConversation::create([
                'organization_id' => $t['org']->id, 'lead_id' => $lead->id, 'remote_id' => 'c-'.$ago,
                'channel' => 'MESSAGE', 'participant' => $who, 'occurred_at' => now()->subMinutes($ago), 'text' => $text,
            ]);
            $say('CONSUMER', '44 Elm Street Bethel CT 06801', 30);
            $reply = $say('ADVERTISER', 'We can come Saturday at 8.', 20);
            $say('CONSUMER', "Liked \u{201C}We can come Saturday at 8.\u{201D}", 10);
            app(AuditLogger::class)->log('lead.replied', $lead, ['conversation_id' => $reply->id], actor: $t['admin']);
        });

        config(['services.google_maps.server_key' => 'maps-key']);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [[
            'formatted_address' => '44 Elm St, Bethel, CT 06801, USA',
            'address_components' => [['short_name' => '06801', 'types' => ['postal_code']], ['short_name' => '44', 'types' => ['street_number']],
                ['short_name' => 'Elm St', 'types' => ['route']], ['short_name' => 'Bethel', 'types' => ['locality']],
                ['short_name' => 'CT', 'types' => ['administrative_area_level_1']]],
            'geometry' => ['location_type' => 'ROOFTOP'],
        ]]])]);

        $data = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'/'.$lead->id)->assertOk()->json('data');

        // line1, city and state fill the Book form (Jose, 2026-11-05).
        $this->assertSame(['address' => '44 Elm St, Bethel, CT 06801, USA', 'zip' => '06801', 'line1' => '44 Elm St', 'city' => 'Bethel', 'state' => 'CT', 'exact' => true],
            $data['details']['checked_address']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'address=44%20Elm%20Street%20Bethel%20CT%2006801')
            && ! str_contains($r->url(), 'Saturday'), 'only the address is sent, nothing else they said');
        $this->assertSame('44 Elm Street Bethel CT 06801', $data['details']['address']);
        $this->assertSame('06801', $data['details']['zip']);
        $this->assertSame('Active', $data['details']['google_status']);
        $this->assertNotNull($data['details']['account']);
        [$first, $reply, $reaction] = $data['conversations'];
        $this->assertSame($t['admin']->name, $reply['sent_by']);
        // Their id too, for the photo on the bubble (Jose, 2026-11-13).
        $this->assertSame($t['admin']->id, $reply['sent_by_id']);
        $this->assertNull($first['sent_by']);
        $this->assertNull($first['sent_by_id']);
        $this->assertSame(['emoji' => "\u{1F44D}", 'quote' => 'We can come Saturday at 8'], $reaction['reaction']);
        $this->assertNull($first['reaction']);
    }

    /**
     * Who else has a lead open (Jose, 2026-09-29): opening it checks in, the
     * others see the name - on the list and on the lead - and nobody sees
     * themselves. A check-in lasts 45 seconds.
     */
    public function test_somebody_else_with_the_lead_open_is_named(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t);
        $other = $this->userWithRole($t, 'Dispatcher', ['leads.view', 'leads.local_services.view']);

        $this->signedInAs($other)->getJson(self::ENDPOINT.'/'.$lead->id)->assertOk();

        $this->signedInAs($t['admin']);
        $this->assertSame([$other->name], $this->getJson(self::ENDPOINT)->assertOk()->json('data.0.viewers'));
        $this->assertSame([$other->name], $this->getJson(self::ENDPOINT.'/'.$lead->id)->assertOk()->json('data.viewers'));

        // The dispatcher sees the admin now, never themselves.
        $this->signedInAs($other);
        $this->assertSame([$t['admin']->name], $this->getJson(self::ENDPOINT)->assertOk()->json('data.0.viewers'));

        // Closing the thread takes the name off at once, not when the
        // check-in runs out (Jose, 2026-10-01).
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT.'/'.$lead->id.'/leave')->assertOk();
        $this->signedInAs($other);
        $this->assertSame([], $this->getJson(self::ENDPOINT)->assertOk()->json('data.0.viewers'));

        // A thread that never said goodbye: past its 25 seconds a check-in no
        // longer counts - inside the cache's own 40, so it is the rule doing
        // this, not expiry.
        $this->getJson(self::ENDPOINT.'/'.$lead->id)->assertOk();
        $this->signedInAs($t['admin']);
        $this->assertSame([$other->name], $this->getJson(self::ENDPOINT)->assertOk()->json('data.0.viewers'));
        $this->travel(30)->seconds();
        $this->assertSame([], $this->getJson(self::ENDPOINT)->assertOk()->json('data.0.viewers'));
    }

    /**
     * Marketing reads leads to inspect them; naming them as viewing told the
     * dispatchers somebody else was answering (Mia, 2026-10-02). A role set
     * hidden on leads is never named. Switched from Roles & Permissions.
     */
    public function test_a_role_hidden_on_leads_is_not_named_as_viewing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t);
        $marketing = $this->userWithRole($t, 'Marketing', ['leads.view', 'leads.local_services.view']);

        $this->signedInAs($t['admin'])
            ->patchJson('/api/v1/roles/'.$marketing->role_id, ['hidden_on_leads' => true])
            ->assertOk()
            ->assertJsonPath('data.hidden_on_leads', true);

        $this->signedInAs($marketing->fresh())->getJson(self::ENDPOINT.'/'.$lead->id)->assertOk();

        $this->signedInAs($t['admin']);
        $this->assertSame([], $this->getJson(self::ENDPOINT)->assertOk()->json('data.0.viewers'));
        $this->assertSame([], $this->getJson(self::ENDPOINT.'/'.$lead->id)->assertOk()->json('data.viewers'));
    }

    public function test_a_lead_is_listed_for_somebody_who_may_see_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->lead($t);

        $response = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame('Dana Whitfield', $response->json('data.0.contact_name'));
        $this->assertSame(Lead::STATUS_NEW, $response->json('data.0.status'));
    }

    public function test_another_organizations_leads_are_never_listed(): void
    {
        $mine   = $this->provisionTenant('Mine');
        $theirs = $this->provisionTenant('Theirs');

        $this->lead($theirs, ['contact_name' => 'Not Yours']);

        $response = $this->signedInAs($mine['admin'])->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_a_phone_search_ignores_how_either_side_wrote_the_number(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->lead($t);

        // Typed bare; stored with punctuation and a country code.
        $response = $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT.'?search=5551234567')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * The empty state has to distinguish a quiet week from nothing working.
     */
    public function test_the_list_says_nothing_is_connected_when_nothing_is(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $response = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
        $this->assertSame([], $response->json('meta.connections'));
    }

    public function test_a_connected_account_shows_in_the_list_state(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], fn () => SocialConnection::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
            'scope_id'        => $t['org']->id,
            'provider'        => SocialProvider::GoogleAds->value,
            'remote_id'       => '1234567890',
            'display_name'    => '123-456-7890',
        ]));

        $response = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame('google_ads', $response->json('meta.connections.0.provider'));
    }

    public function test_the_status_can_be_changed(): void
    {
        $t    = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t);

        $this->signedInAs($t['admin'])
            ->patchJson(self::ENDPOINT."/{$lead->id}", ['status' => Lead::STATUS_BOOKED])
            ->assertOk()
            ->assertJsonPath('data.status', Lead::STATUS_BOOKED);

        $this->assertSame(
            Lead::STATUS_BOOKED,
            $this->inTenant($t['org'], fn () => Lead::find($lead->id))->status,
        );
    }

    public function test_a_status_the_software_does_not_have_is_refused(): void
    {
        $t    = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t);

        $this->signedInAs($t['admin'])
            ->patchJson(self::ENDPOINT."/{$lead->id}", ['status' => 'sold'])
            ->assertStatus(422);
    }

    /**
     * The one that keeps the record from becoming notes.
     *
     * The phone is what Google or the carrier reported. An endpoint that
     * accepted edits to it would turn evidence into something tidyable, and
     * the difference matters the first time a charge is disputed. The NAME is
     * a label a person may correct in place since the A1 Leads takeover
     * (Jose, 2026-10-16: "go ahead"; A1 Leads kept a corrected name): what
     * was said stays in the conversations.
     */
    public function test_the_phone_cannot_be_edited_but_the_name_can_be_corrected(): void
    {
        $t    = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t);

        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT."/{$lead->id}", [
            'status'       => Lead::STATUS_BOOKED,
            'contact_name' => 'Someone Else',
            'phone'        => '+1 555 000 0000',
        ])->assertOk();

        $fresh = $this->inTenant($t['org'], fn () => Lead::find($lead->id));

        $this->assertSame('Someone Else', $fresh->contact_name);
        $this->assertSame(self::PHONE, $fresh->phone);
    }

    public function test_there_is_no_way_to_invent_a_lead(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT, ['contact_name' => 'Invented'])
            ->assertStatus(405);
    }

    public function test_the_list_is_refused_without_the_leads_tab(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['customers.view']);

        $this->lead($t);

        $this->signedInAs($viewer)
            ->getJson(self::ENDPOINT)
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'see this lead') && ! str_contains($m, 'leads.view'),
            );
    }

    public function test_syncing_with_nothing_connected_says_so_rather_than_failing(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $response = $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT.'/sync')
            ->assertStatus(409);

        $this->assertStringContainsString(
            'No Google Ads account is connected',
            $response->json('message'),
        );
    }

    /**
     * A branch manager sees their branch's enquiries and not a sibling's.
     */
    public function test_reach_narrows_the_list(): void
    {
        $t     = $this->provisionTenant('A1-Group');
        $mine  = $t['franchise'];
        $them  = $this->inTenant($t['org'], fn () => \App\Models\Franchise::create([
            'organization_id' => $t['org']->id,
            'company_id'      => $t['company']->id,
            'name'            => 'Second Branch',
        ]));

        $manager = $this->userWithRole($t, 'Branch', ['leads.view', 'leads.local_services.view'], onlyAt: $mine);

        $this->lead($t, [
            'company_id'   => $t['company']->id,
            'franchise_id' => $mine->id,
            'contact_name' => 'Mine',
        ]);
        $this->lead($t, [
            'company_id'   => $t['company']->id,
            'franchise_id' => $them->id,
            'contact_name' => 'Theirs',
        ]);

        $response = $this->signedInAs($manager)->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Mine', $response->json('data.0.contact_name'));
    }
}
