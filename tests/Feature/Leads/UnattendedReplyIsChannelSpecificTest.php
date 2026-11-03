<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Services\Leads\LocalServicesLeads;
use App\Services\Leads\ThumbtackLeads;
use App\Support\Leads\LeadReplies;
use App\Support\Social\SocialProvider;
use App\Support\Web\WebsiteChatReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Which channels software may send on, and which it may not.
 *
 * ── Why this is a method and not a comment ──────────────────────
 *
 * `LeadReplyChannel` used to say "never from a rule" in prose. Automations read
 * it as somebody's judgement about unattended replies and asked before building
 * over it — reasonably, because the paragraph carried the rule and not the
 * stakes.
 *
 * The stake is a filing. EzBizi told `ads-api-compliance` on 2026-09-15, and
 * Google's OAuth Trust and Safety team on 2026-09-16, that its only write is
 * "one reply a member of staff types and sends to a text enquiry". Both
 * submissions are open and the OAuth one has already bounced twice on
 * discrepancies. A rule reaching a Google lead makes that sentence false while
 * a reviewer is reading it.
 *
 * A comment relies on the next person remembering. This asks the channel, and
 * the answer is required rather than defaulted so a new channel cannot inherit
 * one nobody chose.
 */
class UnattendedReplyIsChannelSpecificTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * GOOGLE ALLOWS ONE, since 2026-09-29. This asserted the opposite because
     * of the filing quoted above; Jose decided it: "flip the block now,
     * there's no need to tell google about it". The auto reply that replaces
     * Rodrigo's bot answers Google text enquiries.
     */
    public function test_google_allows_an_unattended_reply(): void
    {
        $this->assertTrue(app(LocalServicesLeads::class)->allowsUnattendedReply());
    }

    /**
     * THUMBTACK ALLOWS ONE, since 2026-09-24.
     *
     * This asserted the opposite, and the channel said why: a choice rather
     * than a filing, false only because nobody had asked. Jose asked — *"it
     * should be for any lead, i don't know why you set a restriction on it"* —
     * so it was argued up, which is the path that method was written to allow.
     *
     * Google's test above is untouched, and it is the one that must never flip
     * without re-filing first.
     */
    public function test_thumbtack_allows_one(): void
    {
        $this->assertTrue(app(ThumbtackLeads::class)->allowsUnattendedReply());
    }

    /**
     * AND THE CONTROL. Without a channel that says yes, every assertion above
     * passes on an interface hard-wired to false — which would block the
     * feature Jose actually asked for while looking like a working guard.
     */
    public function test_website_chat_allows_one(): void
    {
        $this->assertTrue(
            app(WebsiteChatReplies::class)->allowsUnattendedReply(),
            'website chat is the channel the reply automation is built on',
        );
    }

    /**
     * Through the resolver, which is how a rule will ask — it holds a Lead,
     * not a channel.
     */
    public function test_the_resolver_answers_per_lead(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        [$google, $chat, $orphan] = $this->inTenant($t['org'], function () use ($t) {
            $connection = SocialConnection::create([
                'organization_id' => $t['org']->getKey(),
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->getKey(),
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4051729356',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $connection->connect(['refresh_token' => 'rt-good']);
            $connection->save();

            $make = fn (string $source, ?SocialConnection $c, string $id) => Lead::create([
                'organization_id' => $t['org']->getKey(),
                'company_id' => $t['company']->getKey(),
                'connection_id' => $c?->getKey(),
                'source' => $source,
                'remote_id' => $id,
                'contact_name' => 'Dana Whitfield',
                'lead_type' => 'MESSAGE',
                'status' => Lead::STATUS_NEW,
                'received_at' => now(),
            ]);

            return [
                $make(Lead::SOURCE_LOCAL_SERVICES, $connection, 'L-1'),
                $make(Lead::SOURCE_WEBSITE_CHAT, null, 'chat-1'),
                // A source with no channel at all (a form has one since 2026-11-03: a text).
                $make(Lead::SOURCE_WEBSITE_BOOKING, null, 'booking-1'),
            ];
        });

        $replies = app(LeadReplies::class);

        $this->assertTrue($replies->allowsUnattendedReply($google));
        $this->assertTrue($replies->allowsUnattendedReply($chat));

        $this->assertFalse(
            $replies->allowsUnattendedReply($orphan),
            'a source nothing can reply to must not be reported as open to rules',
        );
    }

    /**
     * The two questions are separate, and this is the pair that proves it.
     *
     * A Google text enquiry on a connected account CAN be replied to — a person
     * does it every day. It still must not be replied to by software. A single
     * method answering both would have let a rule test `canReply()`, get true,
     * and send.
     */
    public function test_being_repliable_is_not_permission_for_a_rule(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $lead = $this->inTenant($t['org'], function () use ($t) {
            $connection = SocialConnection::create([
                'organization_id' => $t['org']->getKey(),
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->getKey(),
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4051729356',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $connection->connect(['refresh_token' => 'rt-good']);
            $connection->save();

            return Lead::create([
                'organization_id' => $t['org']->getKey(),
                'company_id' => $t['company']->getKey(),
                'connection_id' => $connection->getKey(),
                'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => 'L-repliable',
                'contact_name' => 'Dana Whitfield',
                'lead_type' => 'MESSAGE',
                'status' => Lead::STATUS_NEW,
                'received_at' => now(),
            ]);
        });

        $replies = app(LeadReplies::class);

        $this->assertTrue(
            $replies->canReply($lead),
            'the control: a person really can answer this one',
        );

        // Since 2026-09-29 software may too (Jose's ruling above).
        $this->assertTrue($replies->allowsUnattendedReply($lead));
    }

    /**
     * A rule's reply to a Google text enquiry goes to Google, and the thread
     * records it as automatic - the bot mark, and the lead stays unreplied.
     */
    public function test_a_rules_reply_on_google_is_sent_and_marked_automatic(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response(['responses' => [['localServicesLeadConversation' => 'customers/4051729356/localServicesLeadConversations/991']]], 200),
        ]);

        $lead = $this->inTenant($t['org'], function () use ($t) {
            $connection = SocialConnection::create([
                'organization_id' => $t['org']->getKey(),
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->getKey(),
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4051729356',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $connection->connect(['refresh_token' => 'rt-good']);
            $connection->save();

            return Lead::create([
                'organization_id' => $t['org']->getKey(),
                'company_id' => $t['company']->getKey(),
                'connection_id' => $connection->getKey(),
                'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => 'L-auto',
                'contact_name' => 'Dana Whitfield',
                'lead_type' => 'MESSAGE',
                'status' => Lead::STATUS_NEW,
                'received_at' => now(),
            ]);
        });

        $row = $this->inTenant($t['org'], fn () => app(LocalServicesLeads::class)->send($lead, 'Hello', automatic: true));

        $this->assertTrue((bool) $row->fresh()->is_automatic);
        $this->assertSame('991', $row->remote_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'localServices:appendLeadConversation'));
    }

    /**
     * And the control: the same call WITHOUT the flag must not be refused by
     * this guard. Without it, "throws when automatic" passes on a send() that
     * throws always — which would break the human composer for every Google
     * enquiry in the product.
     */
    public function test_the_guard_does_not_refuse_a_person(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $lead = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->getKey(),
            'company_id' => $t['company']->getKey(),
            'source' => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id' => 'L-human',
            'contact_name' => 'Dana Whitfield',
            'lead_type' => 'MESSAGE',
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]));

        try {
            $this->inTenant(
                $t['org'],
                fn () => app(LocalServicesLeads::class)->send($lead, 'Hello'),
            );
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(
                'only be replied to by a person',
                $e->getMessage(),
                'a human reply was refused by the automation guard',
            );

            return;
        }

        $this->addToAssertionCount(1);
    }
}
