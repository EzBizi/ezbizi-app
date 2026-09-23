<?php

namespace Tests\Feature\Social;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Leads\LeadReplies;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Answering a Messenger or Instagram enquiry from the Leads thread.
 *
 * The 24-hour window is the whole of this file's risk. Meta accepts a reply
 * inside it and refuses one outside, and the refusal arrives as an API error
 * after somebody has typed and sent - so the composer has to know before they
 * start, and the send has to refuse even if the composer did not.
 */
class MetaMessageRepliesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PAGE_ID = '102938475610293';

    private const SENDER = '7421938475610';

    /** @return array{0: Lead, 1: SocialConnection} */
    private function enquiry(array $t, int $hoursAgo = 1, string $source = Lead::SOURCE_FACEBOOK): array
    {
        return $this->inTenant($t['org'], function () use ($t, $hoursAgo, $source) {
            $connection = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'provider' => $source === Lead::SOURCE_FACEBOOK
                    ? SocialProvider::FacebookPage->value
                    : SocialProvider::InstagramProfessional->value,
                'remote_id' => self::PAGE_ID,
                'display_name' => 'A-1 Chimney',
            ]);

            $connection->forceFill(['credentials' => ['access_token' => 'a-page-token']])->save();

            $lead = Lead::create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->getKey(),
                'source' => $source,
                'remote_id' => self::SENDER,
                'status' => Lead::STATUS_NEW,
                'received_at' => now(),
            ]);

            LeadConversation::create([
                'organization_id' => $t['org']->id,
                'lead_id' => $lead->getKey(),
                'remote_id' => 'm_IN',
                'channel' => LeadConversation::CHANNEL_MESSAGE,
                'participant' => LeadConversation::FROM_CONSUMER,
                'occurred_at' => now()->subHours($hoursAgo),
                'text' => 'Do you clean chimneys?',
            ]);

            return [$lead->fresh(), $connection];
        });
    }

    private function metaAccepts(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_OUT_1'])]);
    }

    public function test_a_person_can_reply_inside_the_window(): void
    {
        $t = $this->provisionTenant('A1-Group');
        [$lead] = $this->enquiry($t, hoursAgo: 2);
        $this->metaAccepts();

        $this->inTenant($t['org'], function () use ($lead) {
            $channel = app(LeadReplies::class)->for($lead);

            $this->assertNotNull($channel, 'Messenger must have a reply channel registered');
            $this->assertTrue($channel->canReply($lead));

            $channel->send($lead, 'Yes, we do. Thursday morning suit?');
        });

        Http::assertSent(function ($request) {
            $body = $request->data();

            // RESPONSE, not the default UPDATE. UPDATE is a business-initiated
            // notification, refused outside the window and needing a tag we do
            // not hold - so the default would fail exactly when the window is
            // the thing under discussion.
            return str_contains($request->url(), '/'.self::PAGE_ID.'/messages')
                && $body['messaging_type'] === 'RESPONSE'
                && $body['recipient']['id'] === self::SENDER
                && $body['message']['text'] === 'Yes, we do. Thursday morning suit?';
        });

        $ours = LeadConversation::query()->withoutGlobalScopes()
            ->where('participant', LeadConversation::FROM_ADVERTISER)->firstOrFail();

        // Keyed on META's id, so the echo that comes straight back down the
        // webhook matches this row and refreshes it rather than adding the
        // business's own reply to the thread a second time.
        $this->assertSame('m_OUT_1', $ours->remote_id);
        $this->assertSame('Yes, we do. Thursday morning suit?', $ours->text);
    }

    public function test_the_window_closes_at_twenty_four_hours(): void
    {
        $t = $this->provisionTenant('A1-Group');
        [$lead] = $this->enquiry($t, hoursAgo: 25);
        $this->metaAccepts();

        $this->inTenant($t['org'], function () use ($lead) {
            $channel = app(LeadReplies::class)->for($lead);

            // The composer asks this BEFORE somebody types. Without it they
            // write a paragraph and learn it cannot be sent afterwards.
            $this->assertFalse($channel->canReply($lead));

            try {
                $channel->send($lead, 'Sorry for the delay');
                $this->fail('a reply outside the window must be refused');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('24 hours', $e->getMessage());
            }
        });

        // And nothing was attempted. Meta would have refused it anyway, but a
        // refusal we can explain beats an API error somebody has to decode.
        Http::assertNothingSent();
    }

    public function test_a_rule_may_not_send_on_this_channel(): void
    {
        $t = $this->provisionTenant('A1-Group');
        [$lead] = $this->enquiry($t, hoursAgo: 1);
        $this->metaAccepts();

        $this->inTenant($t['org'], function () use ($lead) {
            $channel = app(LeadReplies::class)->for($lead);

            $this->assertFalse($channel->allowsUnattendedReply());

            // The CALLEE's guard, asked even though the caller should not have
            // got here. Only this one is in the room when a new caller appears.
            $this->expectException(RuntimeException::class);
            $channel->send($lead, 'An automatic answer', automatic: true);
        });
    }

    public function test_a_disconnected_account_cannot_be_replied_through(): void
    {
        $t = $this->provisionTenant('A1-Group');
        [$lead] = $this->enquiry($t, hoursAgo: 1);
        $this->metaAccepts();

        // Disconnecting nulls the lead's connection, which is what the Leads
        // module already does. The enquiry survives; the route to answer it
        // does not.
        $this->inTenant($t['org'], fn () => $lead->forceFill(['connection_id' => null])->save());

        $this->inTenant($t['org'], function () use ($lead) {
            $channel = app(LeadReplies::class)->for($lead->fresh());
            $this->assertFalse($channel->canReply($lead->fresh()));
        });

        Http::assertNothingSent();
    }

    public function test_instagram_has_its_own_channel_registered(): void
    {
        $t = $this->provisionTenant('A1-Group');
        [$lead] = $this->enquiry($t, hoursAgo: 1, source: Lead::SOURCE_INSTAGRAM);
        $this->metaAccepts();

        $this->inTenant($t['org'], function () use ($lead) {
            $channel = app(LeadReplies::class)->for($lead);

            // One class serves both sources, so the failure this guards is a
            // registration that only tagged one of them - which looks fine
            // until an Instagram enquiry has no composer at all.
            $this->assertNotNull($channel);
            $this->assertSame(Lead::SOURCE_INSTAGRAM, $channel->source());
            $this->assertTrue($channel->canReply($lead));
        });
    }
}
