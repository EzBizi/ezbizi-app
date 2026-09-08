<?php

namespace Tests\Feature\Web;

use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\WebChat;
use App\Models\WebSite;
use App\Support\Web\WebsiteEnquiries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The chat widget and the contact form on a customer's own website.
 *
 * These are the only routes in EzBizi a stranger can create a row through, so
 * most of what is asserted here is what a stranger CANNOT do.
 */
class WebsiteEnquiriesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── a chat becomes a lead ────────────────────────────────────────

    public function test_a_visitor_starting_a_chat_creates_a_lead_on_the_right_company(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $response = $this->startChat($site->public_key, 'My chimney is smoking into the room.')
            ->assertCreated();

        $lead = $this->inTenant($tenant['org'], fn () => Lead::query()->firstOrFail());

        $this->assertSame(Lead::SOURCE_WEBSITE_CHAT, $lead->source);
        $this->assertSame($site->company_id, $lead->company_id);
        $this->assertSame(Lead::STATUS_NEW, $lead->status);

        // The visitor's first message is on the thread, not just in the lead.
        $this->assertSame(
            'My chimney is smoking into the room.',
            $response->json('messages.0.text'),
        );
        $this->assertSame('you', $response->json('messages.0.from'));
    }

    /**
     * The preview line is what the Leads list shows, and it is Leads' cache
     * rather than ours — proving it is written proves we went through
     * LeadThread instead of writing conversation rows behind its back.
     */
    public function test_the_lead_carries_the_preview_line_the_list_reads(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $this->startChat($site->public_key, 'Do you do liners?')->assertCreated();

        $lead = $this->inTenant($tenant['org'], fn () => Lead::query()->firstOrFail());

        $this->assertSame('Do you do liners?', $lead->last_contact_preview);
        $this->assertSame(LeadConversation::FROM_CONSUMER, $lead->last_contact_from);
        $this->assertNotNull($lead->last_contact_at);
    }

    public function test_a_form_submission_becomes_one_lead_with_one_message(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $this->postJson('/api/v1/web/form', [
            'key' => $site->public_key,
            'name' => 'Dana Reyes',
            'email' => 'dana@example.com',
            'phone' => '(555) 010-0100',
            'fields' => [
                'Service' => 'Chimney sweep',
                'When' => 'Next week if possible',
                'Notes' => '',
            ],
        ])->assertCreated()->assertJson(['received' => true]);

        [$lead, $rows] = $this->inTenant($tenant['org'], fn () => [
            Lead::query()->firstOrFail(),
            LeadConversation::query()->get(),
        ]);

        $this->assertSame(Lead::SOURCE_WEBSITE_FORM, $lead->source);
        $this->assertSame('Dana Reyes', $lead->contact_name);
        $this->assertSame('5550100100', $lead->phone_digits);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Service: Chimney sweep', $rows->first()->text);

        // An empty field is not a line saying it was empty.
        $this->assertStringNotContainsString('Notes:', $rows->first()->text);
    }

    /**
     * The whole point of routing website enquiries into Leads: a reply typed in
     * the Leads thread has to reach the visitor's widget.
     */
    public function test_a_reply_from_the_business_reaches_the_visitor(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $started = $this->startChat($site->public_key, 'Are you open Saturday?')->assertCreated();
        $chatId = $started->json('chat');
        $token = $started->json('token');

        // A second later, as a human reply is. Without this both messages carry
        // the same second-precision stamp and the assertion below would be
        // about the tiebreak rather than about the reply arriving.
        $this->travel(1)->seconds();

        $this->inTenant($tenant['org'], function () use ($chatId) {
            app(WebsiteEnquiries::class)->say(
                WebChat::findOrFail($chatId),
                'We are, 8 until 1.',
                LeadConversation::FROM_ADVERTISER,
            );
        });

        $messages = $this->withToken($token)
            ->getJson("/api/v1/web/chat/{$chatId}/messages")
            ->assertOk()
            ->json('messages');

        $this->assertCount(2, $messages);
        $this->assertSame('them', $messages[1]['from']);
        $this->assertSame('We are, 8 until 1.', $messages[1]['text']);
    }

    // ── what a stranger cannot do ────────────────────────────────────

    /**
     * Knowing a site's public key is not knowing its visitors' conversations.
     *
     * The key is published in page source by design, so if it were enough to
     * read a chat, every chat on every EzBizi site would be public.
     */
    public function test_the_public_key_does_not_open_somebody_elses_chat(): void
    {
        ['site' => $site] = $this->siteWithKey();

        $chatId = $this->startChat($site->public_key, 'Private, I would hope.')
            ->assertCreated()
            ->json('chat');

        $this->withToken($site->public_key)
            ->getJson("/api/v1/web/chat/{$chatId}/messages")
            ->assertUnauthorized();

        $this->getJson("/api/v1/web/chat/{$chatId}/messages")
            ->assertUnauthorized();
    }

    /**
     * A token opens ONE conversation.
     *
     * Checking the token without the id would make a single leaked token a key
     * to every chat, which is the failure this pairing exists to prevent.
     */
    public function test_a_token_does_not_open_a_different_chat(): void
    {
        ['site' => $site] = $this->siteWithKey();

        $mine = $this->startChat($site->public_key, 'Mine.')->assertCreated();
        $theirs = $this->startChat($site->public_key, 'Theirs.')->assertCreated();

        $this->withToken($mine->json('token'))
            ->getJson('/api/v1/web/chat/'.$theirs->json('chat').'/messages')
            ->assertUnauthorized();
    }

    public function test_an_unknown_key_creates_nothing(): void
    {
        $this->siteWithKey();

        $this->startChat('pk_not_a_real_key', 'Hello?')->assertNotFound();

        $this->assertSame(0, Lead::query()->withoutGlobalScopes()->count());
    }

    public function test_a_missing_key_is_a_bad_request_not_a_missing_route(): void
    {
        $this->postJson('/api/v1/web/chat', ['message' => 'Hello?'])
            ->assertStatus(400);
    }

    /**
     * The token is said once and kept hashed, like every other credential here.
     */
    public function test_the_visitor_token_is_not_stored_in_the_clear(): void
    {
        ['site' => $site] = $this->siteWithKey();

        $started = $this->startChat($site->public_key, 'Hello.')->assertCreated();
        $token = $started->json('token');

        $stored = WebChat::query()->withoutGlobalScopes()->findOrFail($started->json('chat'));

        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
    }

    /**
     * An enquiry belongs to the organization whose site took it, and to no
     * other — the placement comes from the key, never from the caller.
     */
    public function test_an_enquiry_lands_in_the_owning_organization_only(): void
    {
        ['site' => $site, 'tenant' => $alpha] = $this->siteWithKey('Alpha', 'a-1chimney.com');
        ['tenant' => $beta] = $this->siteWithKey('Beta', 'beta-example.com');

        $this->startChat($site->public_key, 'For Alpha.')->assertCreated();

        $this->assertSame(1, $this->inTenant($alpha['org'], fn () => Lead::query()->count()));
        $this->assertSame(0, $this->inTenant($beta['org'], fn () => Lead::query()->count()));
    }

    /**
     * A site can answer for a branch, and its enquiries land there.
     *
     * This is the Organization → Company → Franchise shape doing its job. A
     * company HAS franchises rather than belonging to one, so nothing can
     * derive the branch from the company — the site has to name it, and until
     * it could, every website enquiry arrived at company level even for a firm
     * whose whole point is branches.
     */
    public function test_an_enquiry_lands_on_the_branch_its_website_answers_for(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole(
            $tenant,
            'Web Editor',
            ['web_management.view', 'web_management.edit'],
        );

        $created = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(),
            'franchise_id' => $tenant['franchise']->getKey(),
            'host' => 'nj.a-1chimney.com',
        ])->assertCreated();

        $this->assertSame(
            $tenant['franchise']->getKey(),
            $created->json('data.franchise.id'),
            'The screen has to be able to show which branch it answers for.',
        );

        $site = $this->inTenant(
            $tenant['org'],
            fn () => WebSite::findOrFail($created->json('data.id')),
        );

        $this->startChat($site->public_key, 'Booking for the Newark house.')->assertCreated();

        $lead = $this->inTenant($tenant['org'], fn () => Lead::query()->firstOrFail());

        $this->assertSame($tenant['franchise']->getKey(), $lead->franchise_id);
        $this->assertSame($tenant['company']->getKey(), $lead->company_id);
    }

    /** A site that names no branch places its enquiries at company level. */
    public function test_a_site_with_no_branch_places_its_enquiries_on_the_company(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $this->startChat($site->public_key, 'Anywhere will do.')->assertCreated();

        $lead = $this->inTenant($tenant['org'], fn () => Lead::query()->firstOrFail());

        $this->assertNull($lead->franchise_id);
        $this->assertSame($site->company_id, $lead->company_id);
    }

    /**
     * Automations must be able to name WHICH website.
     *
     * Leads raises `lead.contact_received` for every source it holds, which
     * cannot say that. A company with branch sites writing "send Newark's
     * enquiries to the Newark manager" needs the site as the subject.
     */
    public function test_an_enquiry_raises_an_event_about_the_website(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $this->startChat($site->public_key, 'Is anyone there?')->assertCreated();

        $events = $this->inTenant(
            $tenant['org'],
            fn () => AutomationEvent::query()->pluck('event_key')->all(),
        );

        $this->assertContains('website.chat_started', $events);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @return array{site: WebSite, tenant: array<string, mixed>} */
    private function siteWithKey(string $name = 'Alpha', string $host = 'a-1chimney.com'): array
    {
        $tenant = $this->provisionTenant($name);
        $editor = $this->userWithRole(
            $tenant,
            'Web Editor',
            ['web_management.view', 'web_management.edit'],
        );

        $created = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(),
            'host' => $host,
            'label' => $host,
        ])->assertCreated();

        $site = $this->inTenant(
            $tenant['org'],
            fn () => WebSite::findOrFail($created->json('data.id')),
        );

        return ['site' => $site, 'tenant' => $tenant];
    }

    private function startChat(string $key, string $message): TestResponse
    {
        return $this->postJson('/api/v1/web/chat', [
            'key' => $key,
            'message' => $message,
        ]);
    }
}
