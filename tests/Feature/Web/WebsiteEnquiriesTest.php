<?php

namespace Tests\Feature\Web;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\WebChat;
use App\Models\WebSite;
use App\Support\Automations\ActionRunner;
use App\Support\Leads\LeadReplies;
use App\Support\Leads\SmsLeadReplies;
use App\Support\Web\WebsiteChatReplies;
use App\Support\Web\WebsiteEnquiries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** Jose, 2026-10-19: the same phone sending a form again within a day is one lead. */
    public function test_a_second_form_from_the_same_phone_within_a_day_joins_the_first(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();
        $send = fn (string $phone, string $what) => $this->postJson('/api/v1/web/form', [
            'key' => $site->public_key, 'name' => 'Elizabeth Bell', 'phone' => $phone,
            'fields' => ['Rating' => 'Not happy', 'What happened?' => $what],
        ])->assertCreated();

        $send('(216) 392-9942', 'Late');
        $send('216.392.9942', 'Still late');
        $send('(216) 392-0000', 'Someone else');

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(2, Lead::query()->count());
            $first = Lead::query()->where('phone_digits', 'like', '%2163929942')->sole();
            $this->assertSame(2, $first->conversations()->count());
            $this->assertSame('BAD_RATING', $first->lead_type);
        });

        // A day later, a new lead.
        $this->travel(25)->hours();
        $send('(216) 392-9942', 'Again');
        $this->assertSame(3, $this->inTenant($tenant['org'], fn () => Lead::query()->count()));
    }

    /** Same email when there is no phone; a chat joins only an earlier chat (its replies go through the chat). */
    public function test_a_repeat_by_email_joins_and_a_chat_never_lands_on_a_form(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();
        $form = fn (string $email) => $this->postJson('/api/v1/web/form', [
            'key' => $site->public_key, 'name' => 'Kim', 'email' => $email, 'fields' => ['Notes' => 'Quote please'],
        ])->assertCreated();

        $form('kim@example.com');
        $form('KIM@example.com');
        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => Lead::query()->count()), 'one lead by email');

        $this->startChat($site->public_key, 'Hi again', ['email' => 'kim@example.com'])->assertCreated();
        $this->startChat($site->public_key, 'And one more thing', ['email' => 'kim@example.com'])->assertCreated();
        $this->inTenant($tenant['org'], function () {
            $this->assertSame(2, Lead::query()->count(), 'the chat is its own lead, not the form\'s');
            $chat = Lead::query()->where('source', Lead::SOURCE_WEBSITE_CHAT)->sole();
            $this->assertSame(2, \App\Models\WebChat::query()->where('lead_id', $chat->id)->count(), 'the second chat joined the first chat\'s lead');
        });
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
        $this->assertSame(
            '👤 Dana Reyes
📞 (555) 010-0100
✉️ dana@example.com
📅 Next week if possible
🛠️ Chimney sweep',
            $rows->first()->text,
        );

        // An empty field is not a line saying it was empty.
        $this->assertStringNotContainsString('Notes:', $rows->first()->text);
    }

    /**
     * Agreeing to marketing email is recorded only when the box was ticked AND
     * the words beside it were sent. A bare `true` proves nothing anybody could
     * show later, so it is not consent (Jose, 2026-09-16: "needs opt-in").
     *
     * Three visitors, three outcomes: the control is the ticked-with-words one,
     * without which "nobody is ever recorded" would pass the other two.
     */
    public function test_marketing_consent_is_recorded_only_with_the_words_they_agreed_to(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();
        $words = 'Yes, send me offers and news by email.';

        foreach ([
            ['ticked@example.com', true, $words],
            ['bare@example.com', true, ''],
            ['unticked@example.com', false, $words],
        ] as [$email, $ticked, $text]) {
            $this->postJson('/api/v1/web/form', [
                'key' => $site->public_key, 'name' => 'Visitor', 'email' => $email,
                'marketing_consent' => $ticked, 'marketing_consent_text' => $text,
                'fields' => ['Service' => 'Sweep'],
            ])->assertCreated();
        }

        $leads = $this->inTenant($tenant['org'], fn () => Lead::query()->get()->keyBy('email'));

        $this->assertNotNull($leads['ticked@example.com']->marketing_consent_at);
        $this->assertSame($words, $leads['ticked@example.com']->marketing_consent_text);
        $this->assertNull($leads['bare@example.com']->marketing_consent_at,
            'a tick with no wording is not a record anybody could show');
        $this->assertNull($leads['unticked@example.com']->marketing_consent_at);
        $this->assertNull($leads['unticked@example.com']->marketing_consent_text);
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
            ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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

    /** Jose, 2026-10-05: a website's branch is picked on screen, and only from its own company. */
    public function test_a_website_moves_to_a_branch_of_its_own_company_only(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();
        $editor = $this->userWithRole($tenant, 'Branch Editor',
            ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

        $this->signedInAs($editor)->patchJson('/api/v1/web/sites/'.$site->getKey(), [
            'franchise_id' => $tenant['franchise']->getKey(),
        ])->assertOk()->assertJsonPath('data.franchise.id', $tenant['franchise']->getKey());

        $other = $this->inTenant($tenant['org'], function () use ($tenant) {
            $company = Company::create(['organization_id' => $tenant['org']->getKey(), 'name' => 'Other Co']);

            return Franchise::create(['organization_id' => $tenant['org']->getKey(),
                'company_id' => $company->getKey(), 'name' => 'Other branch', 'is_active' => true]);
        });

        $this->signedInAs($editor)->patchJson('/api/v1/web/sites/'.$site->getKey(), [
            'franchise_id' => $other->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors('franchise_id');

        $this->signedInAs($editor)->patchJson('/api/v1/web/sites/'.$site->getKey(), ['franchise_id' => null])
            ->assertOk()->assertJsonPath('data.franchise', null);
    }

    /** A site that names no branch places its enquiries at company level. */
    /**
     * Desk, 2026-11-18: website reviews reached lead.received with no branch,
     * so a Houston and a Maryland review both fell to the no-branch rule.
     */
    public function test_a_review_lands_on_the_branch_of_its_zip_else_its_area_code(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();
        $texas = $this->inTenant($tenant['org'], function () use ($tenant) {
            $texas = \App\Models\Franchise::create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'name' => 'Texas']);
            $area = \App\Models\ServiceArea::create(['organization_id' => $tenant['org']->id, 'franchise_id' => $texas->id, 'name' => 'Houston']);
            \App\Models\ServiceAreaZip::create(['organization_id' => $tenant['org']->id, 'franchise_id' => $texas->id, 'service_area_id' => $area->id, 'zip' => '77002']);

            return $texas;
        });
        $review = fn (string $phone, array $more = []) => $this->postJson('/api/v1/web/form', [
            'key' => $site->public_key, 'name' => 'A Customer', 'phone' => $phone,
            'fields' => ['Rating' => 'Not happy', 'What happened?' => 'Late'] + $more,
        ])->assertCreated();
        $branchOf = fn (string $digits) => $this->inTenant($tenant['org'], fn () => Lead::query()->where('phone_digits', 'like', '%'.$digits)->value('franchise_id'));

        $review('(832) 555-0101', ['Zip' => '77002']);
        $this->assertSame($texas->id, $branchOf('8325550101'), 'the ZIP decides');

        // No ZIP: an 832 number, where this company's 832 leads all went to Texas.
        $review('(832) 555-0102');
        $this->assertNull($branchOf('8325550102'), 'the control: no ZIP and no history is no branch, not a guess');
        $this->inTenant($tenant['org'], function () use ($tenant, $texas) {
            foreach (range(1, 5) as $i) {
                Lead::create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'franchise_id' => $texas->id,
                    'source' => 'local_services', 'remote_id' => "h$i", 'phone_digits' => "1832555090$i", 'received_at' => now()->subDays(3)]);
            }
        });
        $review('(832) 555-0103');
        $this->assertSame($texas->id, $branchOf('8325550103'), 'the area code decides');
    }

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

    // ── replying from the Leads thread ──────────────────────────────

    /**
     * The composer in the Leads thread reaches the visitor's page.
     *
     * Resolved by container tag rather than by source name, so this asserts the
     * wiring as well as the delivery: an untagged channel would leave the
     * composer permanently greyed out with nothing to say why.
     */
    /**
     * "Customer replied" is the customer writing AGAIN, and only them.
     *
     * Jose, 2026-09-28: a new lead and a customer reply are different rules,
     * and a reply "should count only reply from the costumer not our own
     * replies". Three messages: their first (the enquiry, not a reply), ours
     * (never), their second (the one reply). Asserting exactly one event is
     * what can tell the difference from firing on every consumer message.
     */
    public function test_customer_replied_fires_only_when_the_customer_writes_again(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $started = $this->startChat($site->public_key, 'Are you open Saturday?')->assertCreated();
        $replied = fn () => $this->inTenant($tenant['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'lead.customer_replied')->count());

        $this->assertSame(0, $replied(), 'the first message is the enquiry, not a reply');

        $this->inTenant($tenant['org'], function () {
            $lead = Lead::query()->firstOrFail();
            app(LeadReplies::class)->for($lead)->send($lead, 'We are, 8 until 1.');
        });
        $this->assertSame(0, $replied(), 'our own reply is never a customer reply');

        $this->withToken($started->json('token'))
            ->postJson('/api/v1/web/chat/'.$started->json('chat').'/messages', ['message' => 'Great, 10am?'])
            ->assertOk();

        $this->assertSame(1, $replied());
        $this->assertSame('Great, 10am?', $this->inTenant($tenant['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'lead.customer_replied')->sole()->payload['after']['preview']));
    }

    public function test_a_reply_typed_in_leads_reaches_the_widget(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $started = $this->startChat($site->public_key, 'Are you open Saturday?')->assertCreated();

        $this->travel(1)->seconds();

        $this->inTenant($tenant['org'], function () {
            $lead = Lead::query()->firstOrFail();
            $replies = app(LeadReplies::class);

            $this->assertTrue($replies->canReply($lead), 'The composer has to be offered.');

            $replies->for($lead)->send($lead, 'We are, 8 until 1.');
        });

        $messages = $this->withToken($started->json('token'))
            ->getJson('/api/v1/web/chat/'.$started->json('chat').'/messages')
            ->assertOk()
            ->json('messages');

        $this->assertCount(2, $messages);
        $this->assertSame('them', $messages[1]['from']);
        $this->assertSame('We are, 8 until 1.', $messages[1]['text']);
    }

    /**
     * A submitted form has no page left to answer into, so it is answered by
     * a text (Jose, 2026-11-03) - and only when the form left a phone. Never
     * by email: that would be a second send path with its own consent and
     * audit questions, wearing this one's name.
     */
    public function test_a_form_submission_is_answered_by_text_and_only_with_a_phone(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $this->postJson('/api/v1/web/form', [
            'key' => $site->public_key,
            'fields' => ['Service' => 'Sweep'],
        ])->assertCreated();

        $this->inTenant($tenant['org'], function () {
            $lead = Lead::query()->firstOrFail();
            $replies = app(LeadReplies::class);

            $this->assertFalse($replies->canReply($lead), 'No phone on the form, nothing to text.');
            $this->assertInstanceOf(SmsLeadReplies::class, $replies->for($lead));
        });
    }

    /** A chat whose row is gone says so, rather than reporting a sent reply. */
    public function test_a_reply_to_a_vanished_chat_refuses_out_loud(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $this->startChat($site->public_key, 'Hello?')->assertCreated();

        $this->inTenant($tenant['org'], function () {
            $lead = Lead::query()->firstOrFail();
            WebChat::query()->where('lead_id', $lead->getKey())->delete();

            $this->expectException(\RuntimeException::class);

            app(LeadReplies::class)
                ->for($lead)
                ->send($lead, 'Anyone there?');
        });
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @return array{site: WebSite, tenant: array<string, mixed>} */
    // ── the site's own Elementor forms, sent on by the plugin ────────

    public function test_the_plugin_passes_on_a_site_form_with_the_site_token(): void
    {
        [$tenant, $token] = $this->siteWithToken();

        $this->withToken($token)->postJson('/api/v1/web/plugin/form', [
            'host' => 'a-1chimney.com',
            'name' => 'Dana Reyes', 'email' => 'dana@example.com', 'phone' => '(555) 010-0100',
            'fields' => ['Name' => 'Dana Reyes', 'Message' => 'Chimney leaking'],
            'page_url' => 'https://a-1chimney.com/contact-us/',
        ])->assertCreated();

        $lead = $this->inTenant($tenant['org'], fn () => Lead::query()->firstOrFail());
        $this->assertSame(Lead::SOURCE_WEBSITE_FORM, $lead->source);
        $this->assertSame('Dana Reyes', $lead->contact_name);
        $this->assertNull($lead->marketing_consent_at, 'an Elementor form records no marketing consent');
    }

    /** Bar via Jose, 2026-10-12: condensed, values only, in the order a dispatcher reads them. */
    public function test_a_form_is_condensed_to_one_line_per_thing_in_order(): void
    {
        $lines = WebsiteEnquiries::condensed([
            'Service Need' => 'Chimney Cap Installation',
            'Email Address' => 'john@test.com',
            'Last Name' => 'Vale',
            'First Name' => 'Nora',
            'Phone Number' => '7542366981',
            'Appointment Date' => '2026-10-20',
            'Appointment Time' => '13:00',
            'Address Line 1' => '123 Elm St',
            'Address Line 2 (Optional)' => '',
            'City' => 'Tempe',
            'State' => 'Arizona',
            'Zip Code' => '85281',
            'Message' => 'Cap blew off',
            'How did you hear about us?' => 'Google',
            'cf-turnstile-response' => 'token',
        ]);

        $this->assertSame([
            '👤 Nora Vale',
            '📞 (754) 236-6981',
            '✉️ john@test.com',
            '📅 2026-10-20 13:00',
            '📍 123 Elm St, Tempe, Arizona 85281',
            '🛠️ Chimney Cap Installation',
            '📝 Cap blew off',
            '▫️ How did you hear about us?: Google',
        ], $lines);
    }

    /** Jose, 2026-10-09: no captcha token in the thread; a field per line with its mark; ratings named. */
    public function test_a_form_reads_field_by_field_without_the_robot_check(): void
    {
        [$tenant, $token] = $this->siteWithToken();

        $this->withToken($token)->postJson('/api/v1/web/plugin/form', [
            'host' => 'a-1chimney.com',
            'fields' => ['First Name' => 'Laura', 'Phone Number' => '2162245383', 'cf-turnstile-response' => '1.XE0gJwM-long-token'],
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/web/plugin/form', [
            'host' => 'a-1chimney.com',
            'fields' => ['Rating' => 'Not happy', 'What happened?' => 'Late'],
        ])->assertCreated();

        $this->inTenant($tenant['org'], function () {
            $text = LeadConversation::query()->orderBy('created_at')->orderBy('id')->pluck('text')->all();
            $this->assertContains("👤 Laura\n📞 (216) 224-5383", $text);
            $this->assertStringNotContainsString('turnstile', implode(' ', $text));
            $this->assertSame(['BAD_RATING'], Lead::query()->whereNotNull('lead_type')->pluck('lead_type')->all());

            // Jose, 2026-10-13: a complaint rule triggers on lead.received with rating = sad.
            $received = AutomationEvent::query()->where('event_key', 'lead.received')->get()
                ->map(fn ($e) => $e->payload['after'] ?? [])->all();
            $this->assertSame([null, 'sad'], array_column($received, 'rating'));
            $this->assertSame([null, 'Late'], array_column($received, 'comment'));
        });
    }

    public function test_a_wrong_token_passes_nothing_on(): void
    {
        $this->siteWithToken();

        $this->withToken('not-the-token')->postJson('/api/v1/web/plugin/form', ['fields' => ['A' => 'b']])->assertUnauthorized();
        $this->assertSame(0, Lead::query()->withoutGlobalScopes()->count());
    }

    public function test_one_host_address_can_pass_on_more_than_a_browser_may(): void
    {
        [$tenant, $token] = $this->siteWithToken();

        // Past the public form's 40 an hour per address: every plugin call comes from the web host's one address.
        for ($i = 0; $i < 45; $i++) {
            $this->withToken($token)->postJson('/api/v1/web/plugin/form', ['fields' => ['Message' => "Enquiry {$i}"]])->assertCreated();
        }

        $this->assertSame(45, $this->inTenant($tenant['org'], fn () => Lead::query()->count()));
    }

    /** @return array{0: array, 1: string} */
    private function siteWithToken(): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Web Editor', ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);
        $token = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(), 'host' => 'a-1chimney.com',
        ])->assertCreated()->json('token');

        return [$tenant, $token];
    }

    private function siteWithKey(string $name = 'Alpha', string $host = 'a-1chimney.com'): array
    {
        $tenant = $this->provisionTenant($name);
        $editor = $this->userWithRole(
            $tenant,
            'Web Editor',
            ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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

    private function startChat(string $key, string $message, array $visitor = []): TestResponse
    {
        return $this->postJson('/api/v1/web/chat', $visitor + [
            'key' => $key,
            'message' => $message,
        ]);
    }

    /**
     * A website enquiry ARRIVES the way a Google or Thumbtack one does: it
     * raises lead.received. Without this, an automation on "Lead received"
     * fired for two sources and silently skipped the other two.
     */
    public function test_a_website_enquiry_raises_lead_received_like_every_other_source(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithKey();

        $this->startChat($site->public_key, 'Do you service gas inserts?')->assertCreated();

        $raised = $this->inTenant($tenant['org'], fn () => DB::table('automation_events')
            ->where('event_key', 'lead.received')
            ->count());

        $this->assertSame(1, $raised, 'the arrival of a website lead is an event, not only its words');
    }

    /** Set the greeting the way the Widgets tab does. */
    private function greetWith(array $made, string $text): void
    {
        // Not "Web Editor": siteWithKey already made one in this org, and the
        // role name is unique per organization.
        $editor = $this->userWithRole(
            $made['tenant'],
            'Widget Editor',
            ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
        );

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$made['site']->getKey()}/widgets", [
                'config' => ['chat' => ['auto_reply' => $text]],
            ])->assertOk();
    }

    /**
     * The answer that arrives before a person does.
     *
     * Asserted on the THREAD as well as the reply, because the point of storing
     * it rather than drawing it in the widget is that Leads shows it: whoever
     * picks the conversation up has to see what the visitor was already told,
     * or they repeat it or contradict it.
     */
    public function test_a_chat_is_answered_the_moment_it_starts(): void
    {
        $made = $this->siteWithKey();
        $this->greetWith($made, 'We have you — one moment.');

        $body = $this->startChat($made['site']->public_key, 'hello i need a sweep')
            ->assertCreated()
            ->json();

        // Two messages, in the order they happened: the question, then us.
        $this->assertCount(2, $body['messages']);
        $this->assertSame('you', $body['messages'][0]['from']);
        $this->assertSame('hello i need a sweep', $body['messages'][0]['text']);
        $this->assertSame('them', $body['messages'][1]['from']);
        $this->assertSame('We have you — one moment.', $body['messages'][1]['text']);

        $this->inTenant($made['tenant']['org'], function () {
            $this->assertDatabaseHas('lead_conversations', [
                'lead_id' => WebChat::query()->firstOrFail()->lead_id,
                'participant' => LeadConversation::FROM_ADVERTISER,
                'text' => 'We have you — one moment.',
            ]);
        });
    }

    /**
     * Blank is a business that would rather say nothing than say something
     * automatic — not a switch coming back in through the side door.
     */
    public function test_an_empty_greeting_sends_nothing(): void
    {
        $made = $this->siteWithKey();
        $this->greetWith($made, '   ');

        $body = $this->startChat($made['site']->public_key, 'hello i need a sweep')
            ->assertCreated()
            ->json();

        $this->assertCount(1, $body['messages']);
    }

    /**
     * Only the first. Every message would make a machine that argues with
     * people: somebody typing three lines in a row would get three identical
     * answers, each one more clearly nobody.
     */
    public function test_it_answers_once_and_not_every_time(): void
    {
        $made = $this->siteWithKey();
        $this->greetWith($made, 'We have you — one moment.');

        $started = $this->startChat($made['site']->public_key, 'hello')
            ->assertCreated()
            ->json();

        $after = $this->withToken($started['token'])
            ->postJson("/api/v1/web/chat/{$started['chat']}/messages", ['message' => 'still there?'])
            ->assertOk()
            ->json();

        $this->assertSame(
            1,
            collect($after['messages'])->where('text', 'We have you — one moment.')->count(),
            'The greeting was sent again on a later message.',
        );
    }

    /**
     * The greeting must not count as somebody having answered.
     *
     * This is the defect that kept it switched off: an ADVERTISER row took a
     * brand new enquiry out of the list a business uses to find the people
     * nobody has replied to. Leads fixed the reading at 072cb313 and this is
     * the half that has to be true on my side — the row is MARKED.
     *
     * Asserted on the lead's own cache rather than on the column, because the
     * cache is what the unreplied filter actually reads. A test on
     * `is_automatic` alone would pass while the filter still lied.
     */
    public function test_the_greeting_does_not_make_a_new_enquiry_look_answered(): void
    {
        $made = $this->siteWithKey();
        $this->greetWith($made, 'We have you — one moment.');

        $this->startChat($made['site']->public_key, 'hello i need a sweep')
            ->assertCreated();

        $this->inTenant($made['tenant']['org'], function () {
            $lead = WebChat::query()->firstOrFail()->lead->refresh();

            // Still unreplied: the newest row a PERSON was in is the visitor's.
            $this->assertSame(LeadConversation::FROM_CONSUMER, $lead->last_contact_from);

            // And the greeting is still the preview, because it IS the most
            // recent thing said. Hiding it would be a different lie.
            $this->assertSame('We have you — one moment.', $lead->last_contact_preview);

            $this->assertTrue(
                (bool) LeadConversation::query()
                    ->where('text', 'We have you — one moment.')
                    ->firstOrFail()
                    ->is_automatic,
            );
        });
    }

    /** A person replying still counts, or the flag would have gone too far. */
    public function test_a_real_reply_still_marks_the_lead_answered(): void
    {
        $made = $this->siteWithKey();
        $this->greetWith($made, 'We have you — one moment.');

        $started = $this->startChat($made['site']->public_key, 'hello')
            ->assertCreated()
            ->json();

        $this->inTenant($made['tenant']['org'], function () use ($started) {
            $chat = WebChat::query()->findOrFail($started['chat']);

            app(WebsiteEnquiries::class)->say(
                $chat,
                'Morning — what is the address?',
                LeadConversation::FROM_ADVERTISER,
            );

            $this->assertSame(
                LeadConversation::FROM_ADVERTISER,
                $chat->lead->refresh()->last_contact_from,
            );
        });
    }

    /**
     * The whole path: a rule replies through the channel, and the enquiry is
     * still waiting for a person.
     *
     * The two halves were written by different sessions a day apart — Leads
     * added the parameter to `send()` and deliberately did not pass it on,
     * because `say()` did not take it on main yet. This is the seam, so it is
     * the thing worth a test rather than either half alone.
     */
    public function test_a_rule_replying_through_the_channel_is_marked_automatic(): void
    {
        $made = $this->siteWithKey();

        $started = $this->startChat($made['site']->public_key, 'hello i need a sweep')
            ->assertCreated()
            ->json();

        $this->inTenant($made['tenant']['org'], function () use ($started) {
            $lead = WebChat::query()->findOrFail($started['chat'])->lead;

            app(WebsiteChatReplies::class)->send($lead, 'A machine wrote this.', automatic: true);

            // Still unreplied: nobody has actually answered.
            $this->assertSame(
                LeadConversation::FROM_CONSUMER,
                $lead->refresh()->last_contact_from,
            );
        });
    }

    /** A rule's reply raises lead.auto_replied, for the WhatsApp messages feed (2026-10-06). */
    public function test_a_rule_reply_raises_auto_replied(): void
    {
        $made = $this->siteWithKey();

        $started = $this->startChat($made['site']->public_key, 'hello i need a sweep')
            ->assertCreated()
            ->json();

        $this->inTenant($made['tenant']['org'], function () use ($started) {
            $lead = WebChat::query()->findOrFail($started['chat'])->lead;
            $received = AutomationEvent::query()->where('event_key', 'lead.received')->firstOrFail();

            $result = app(ActionRunner::class)->run(['type' => 'reply', 'message' => 'Thanks!'], $received, $lead);
            $this->assertTrue($result['ok'], (string) ($result['detail'] ?? ''));

            $raised = AutomationEvent::query()->where('event_key', 'lead.auto_replied')->sole();
            $this->assertSame('Thanks!', $raised->payload['after']['message']);
            $this->assertSame(1, $raised->depth, 'caused by the rule, so loop protection sees it');
        });
    }

    /** And the control: the same call without the flag DOES mark it answered. */
    public function test_the_same_channel_still_carries_a_person(): void
    {
        $made = $this->siteWithKey();

        $started = $this->startChat($made['site']->public_key, 'hello i need a sweep')
            ->assertCreated()
            ->json();

        $this->inTenant($made['tenant']['org'], function () use ($started) {
            $lead = WebChat::query()->findOrFail($started['chat'])->lead;

            app(WebsiteChatReplies::class)->send($lead, 'A person wrote this.');

            $this->assertSame(
                LeadConversation::FROM_ADVERTISER,
                $lead->refresh()->last_contact_from,
            );
        });
    }
}
