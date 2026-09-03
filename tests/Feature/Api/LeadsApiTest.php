<?php

namespace Tests\Feature\Api;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    private function lead(array $t, array $attributes = []): Lead
    {
        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
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

        $branch = $this->userWithRole($t, 'Branch', ['leads.view'], onlyAt: $t['franchise']);

        $this->signedInAs($branch)
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(1, 'data');
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
     * Name and phone are what somebody actually said. An endpoint that accepted
     * edits to them would turn evidence into something tidyable, and the
     * difference matters the first time a charge is disputed.
     */
    public function test_the_contact_details_cannot_be_edited(): void
    {
        $t    = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t);

        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT."/{$lead->id}", [
            'status'       => Lead::STATUS_BOOKED,
            'contact_name' => 'Someone Else',
            'phone'        => '+1 555 000 0000',
        ])->assertOk();

        $fresh = $this->inTenant($t['org'], fn () => Lead::find($lead->id));

        $this->assertSame('Dana Whitfield', $fresh->contact_name);
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

        $manager = $this->userWithRole($t, 'Branch', ['leads.view'], onlyAt: $mine);

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
