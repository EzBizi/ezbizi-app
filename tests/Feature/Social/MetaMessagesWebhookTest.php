<?php

namespace Tests\Feature\Social;

use App\Http\Controllers\Api\MetaMediaController;
use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Social\MetaMessages;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Somebody messages the business's Facebook Page or Instagram.
 *
 * Every failure guarded here is silent in production. A rejected webhook looks
 * exactly like nobody having messaged; an echo absorbed as a customer message
 * looks like a conversation; a lead per message looks like a busy morning.
 */
class MetaMessagesWebhookTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PAGE_ID = '102938475610293';

    private const SENDER = '7421938475610';

    private const SECRET = 'app-secret-from-meta';

    private function configured(): void
    {
        config([
            'services.meta.client_secret' => self::SECRET,
            'services.meta.messages_verify_token' => 'a-token-we-invented',
        ]);
    }

    private function pageBelongsTo(array $t, ?string $provider = null): SocialConnection
    {
        // Created INSIDE the tenant: `withoutGlobalScopes` lifts the read scope
        // and not the write guard, which is the right asymmetry - a row must
        // always be written as somebody's.
        return $this->inTenant($t['org'], fn () => SocialConnection::create([
            'organization_id' => $t['org']->id,
            // Organization-scoped, which is the shape A-1's own Page has: the
            // connection belongs to the business rather than to one branch, so
            // the leads it brings carry neither company nor franchise.
            'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'provider' => $provider ?? SocialProvider::FacebookPage->value,
            'remote_id' => self::PAGE_ID,
            'display_name' => 'A-1 Chimney',
        ]));
    }

    private function deliver(string $json, ?string $signature = null)
    {
        return $this->call('POST', '/api/v1/social/meta/messages/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature
                ?? 'sha256='.hash_hmac('sha256', $json, self::SECRET),
        ], $json);
    }

    /** @param array<string, mixed> $message */
    private function body(array $message, string $object = 'page'): string
    {
        return (string) json_encode([
            'object' => $object,
            'entry' => [[
                'id' => self::PAGE_ID,
                'messaging' => [[
                    'sender' => ['id' => self::SENDER],
                    'recipient' => ['id' => self::PAGE_ID],
                    'timestamp' => 1789000000000,
                    'message' => $message,
                ]],
            ]],
        ]);
    }

    public function test_the_handshake_echoes_the_challenge_as_plain_text(): void
    {
        $this->configured();

        $response = $this->get('/api/v1/social/meta/messages/webhook?'
            .'hub.mode=subscribe&hub.verify_token=a-token-we-invented&hub.challenge=4471129');

        $response->assertOk();
        $this->assertSame('4471129', $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_an_unconfigured_deployment_verifies_nobody(): void
    {
        config(['services.meta.messages_verify_token' => '']);

        // Without this guard an install that has not set the token accepts an
        // EMPTY one, and anybody can point their own Meta app at us.
        $this->get('/api/v1/social/meta/messages/webhook?'
            .'hub.mode=subscribe&hub.verify_token=&hub.challenge=4471129')
            ->assertForbidden();
    }

    public function test_an_unsigned_delivery_is_refused_and_records_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->pageBelongsTo($t);

        $this->deliver($this->body(['mid' => 'm_FORGED', 'text' => 'let me in']), 'sha256=deadbeef')
            ->assertUnauthorized();

        $this->assertSame(0, Lead::query()->withoutGlobalScopes()->count());
    }

    public function test_a_message_becomes_a_lead_against_the_right_tenant(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $other = $this->provisionTenant('Premier-Garage');
        $this->configured();
        $this->pageBelongsTo($t);

        $this->deliver($this->body([
            'mid' => 'm_AAA',
            'text' => 'Do you clean chimneys in Hallandale?',
        ]))->assertOk()->assertJsonPath('data.handled', 1);

        $lead = Lead::query()->withoutGlobalScopes()->firstOrFail();

        // The tenant comes from the connection row WE wrote, never from the
        // body - a payload naming an organization would be a claim by the
        // caller about which tenant to write to.
        $this->assertSame($t['org']->id, $lead->organization_id);
        $this->assertNotSame($other['org']->id, $lead->organization_id);
        $this->assertSame(Lead::SOURCE_FACEBOOK, $lead->source);
        $this->assertSame(self::SENDER, $lead->remote_id);
        $this->assertSame('MESSAGE', $lead->lead_type); // "Meta Text" in its notification
        // So a "new lead" rule hears of it, as it does of every other source.
        $this->assertSame(1, AutomationEvent::query()->withoutGlobalScopes()
            ->where('event_key', 'lead.received')->where('subject_id', $lead->id)->count());

        $said = LeadConversation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Do you clean chimneys in Hallandale?', $said->text);
        $this->assertSame(LeadConversation::FROM_CONSUMER, $said->participant);
    }

    public function test_our_own_reply_echoed_back_is_not_a_customer_message(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->pageBelongsTo($t);

        /*
         * THE CASE THAT WOULD HAVE THE BUSINESS ANSWERING ITSELF.
         *
         * Meta echoes everything the Page sends back down the same webhook,
         * flagged `is_echo`. Absorbed as a customer message it would take the
         * enquiry off the Unreplied list, fire `lead.contact_received`, and any
         * rule watching for a customer message would answer its own answer.
         *
         * The assertion is that NO lead exists, not merely no conversation: an
         * echo arriving first - a business messaging somebody who never wrote
         * in - must not manufacture an enquiry nobody made.
         */
        $this->deliver($this->body([
            'mid' => 'm_ECHO',
            'text' => 'Yes, we do. When suits you?',
            'is_echo' => true,
        ]))->assertOk()->assertJsonPath('data.handled', 0);

        $this->assertSame(0, Lead::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, LeadConversation::query()->withoutGlobalScopes()->count());
    }

    public function test_a_second_message_lands_on_the_same_enquiry(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->pageBelongsTo($t);

        $this->deliver($this->body(['mid' => 'm_1', 'text' => 'Hello?']))->assertOk();
        $this->deliver($this->body(['mid' => 'm_2', 'text' => 'Anyone there?']))->assertOk();
        $this->deliver($this->body(['mid' => 'm_3', 'text' => 'It is urgent']))->assertOk();

        // One person asking three times is ONE enquiry. Keying the lead on the
        // message id instead would read as three, which is a list of enquiries
        // that cannot be worked.
        $this->assertSame(1, Lead::query()->withoutGlobalScopes()->count());
        $this->assertSame(3, LeadConversation::query()->withoutGlobalScopes()->count());
    }

    /** Jose, 11-04: seven Messenger messages and her row stayed at "49m ago". */
    public function test_a_later_message_moves_the_enquiry_to_its_time(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->pageBelongsTo($t);

        $this->deliver($this->body(['mid' => 'm_1', 'text' => 'Hello?']))->assertOk();
        $this->deliver(str_replace('1789000000000', '1789000600000',
            $this->body(['mid' => 'm_2', 'text' => 'Anyone there?'])))->assertOk();

        $lead = Lead::query()->withoutGlobalScopes()->sole();
        $this->assertSame(1789000600, $lead->last_contact_at?->getTimestamp());
        $this->assertSame('Anyone there?', $lead->last_contact_preview);
    }

    /** A-1, 2026-10-29: questions tapped from a Click-to-Message ad are not typed by the customer. */
    public function test_questions_tapped_from_an_ad_are_marked_and_typed_text_is_not(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->pageBelongsTo($t);

        $this->deliver($this->body(['mid' => 'm_A1', 'text' => 'How long does the chimney sweep take?',
            'referral' => ['source' => 'ADS', 'type' => 'OPEN_THREAD', 'ad_id' => '1', 'ads_context_data' => ['ad_title' => 'Fall sweep']]]))->assertOk();
        $this->deliver($this->body(['mid' => 'm_A2', 'text' => 'What is included?', 'quick_reply' => ['payload' => 'q2']]))->assertOk();
        $this->deliver($this->body(['mid' => 'm_A3', 'text' => 'I need a sweep']))->assertOk();

        $this->assertSame(['m_A1' => true, 'm_A2' => true, 'm_A3' => false], LeadConversation::query()->withoutGlobalScopes()
            ->orderBy('remote_id')->pluck('is_ad_question', 'remote_id')->map(fn ($v) => (bool) $v)->all());
        $this->assertSame(['source' => 'Facebook ad', 'ad_name' => 'Fall sweep', 'headline' => 'Fall sweep'], Lead::query()->withoutGlobalScopes()->sole()->referral);
    }

    public function test_a_retried_delivery_does_not_double_the_message(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->pageBelongsTo($t);

        $json = $this->body(['mid' => 'm_SAME', 'text' => 'hello']);

        // Meta retries anything it did not get a 200 for, carrying the same id.
        $this->deliver($json)->assertOk();
        $this->deliver($json)->assertOk();

        $this->assertSame(1, LeadConversation::query()->withoutGlobalScopes()->count());
    }

    public function test_an_instagram_message_is_its_own_source(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->pageBelongsTo($t, SocialProvider::InstagramProfessional->value);

        $this->deliver($this->body(['mid' => 'm_IG', 'text' => 'saw your reel'], 'instagram'))
            ->assertOk()->assertJsonPath('data.handled', 1);

        $lead = Lead::query()->withoutGlobalScopes()->firstOrFail();

        // Not `facebook`. The two arrive in the same envelope and are told
        // apart only by `object`, so a single source here would put Instagram
        // DMs behind the Messenger chip and make one of them unreachable.
        $this->assertSame(Lead::SOURCE_INSTAGRAM, $lead->source);
    }

    private function holdsToken(SocialConnection $connection, array $t): void
    {
        $this->inTenant($t['org'], fn () => $connection->forceFill(['credentials' => ['access_token' => 'page-token']])->save());
    }

    /** Health sweep, 2026-11-05: 551 is permanent, so it is a 422 with a sentence, not a bare 502. */
    public function test_a_person_who_cannot_be_messaged_is_a_plain_refusal(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->pageBelongsTo($t);
        $this->holdsToken($page, $t);
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['code' => 551, 'error_subcode' => 1545041, 'message' => 'This person is not available right now.']], 400)
            ->push(['error' => ['code' => 10, 'message' => 'Outside the window']], 400)]);

        $send = fn () => $this->inTenant($t['org'], fn () => app(MetaMessages::class)->send($page, 'PSID-1', 'Hello'));

        try {
            $send();
            $this->fail('551 was not refused');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('cannot receive messages', $e->getMessage());
        }

        // The control: any other refusal stays a provider failure.
        try {
            $send();
            $this->fail('code 10 was not refused');
        } catch (\RuntimeException $e) {
            $this->assertSame(0, $e->getCode());
        }
    }

    public function test_a_messenger_lead_is_named_from_the_conversation(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->holdsToken($this->pageBelongsTo($t), $t);

        // The participants list, not GET /{PSID}: the profile route needs a
        // feature this app does not hold and refused every real customer.
        Http::fake(['graph.facebook.com/*/'.self::PAGE_ID.'/conversations*' => Http::response(['data' => [[
            'participants' => ['data' => [
                ['name' => 'Scarllet Peter', 'id' => self::SENDER],
                ['name' => 'A-1 Chimney', 'id' => self::PAGE_ID],
            ]],
        ]]])]);

        $this->deliver($this->body(['mid' => 'm_N', 'text' => 'hi']))->assertOk();

        $this->assertSame('Scarllet Peter', Lead::query()->withoutGlobalScopes()->firstOrFail()->contact_name);
    }

    public function test_an_instagram_lead_without_a_name_is_named_by_username(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->holdsToken($this->pageBelongsTo($t, SocialProvider::InstagramProfessional->value), $t);

        Http::fake(['graph.instagram.com/*/'.self::SENDER.'*' => Http::response(['name' => '', 'username' => 'jldemasi'])]);

        $this->deliver($this->body(['mid' => 'm_IGN', 'text' => 'hi'], 'instagram'))->assertOk();

        $this->assertSame('@jldemasi', Lead::query()->withoutGlobalScopes()->firstOrFail()->contact_name);
    }

    public function test_a_name_staff_typed_is_never_overwritten_and_a_blank_one_is_filled(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->holdsToken($this->pageBelongsTo($t), $t);

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => [['participants' => ['data' => [['name' => 'Meta Name', 'id' => self::SENDER]]]]]])
            ->push(['data' => [['participants' => ['data' => [['name' => 'Meta Name', 'id' => self::SENDER]]]]]]),
        ]);

        // Meta says nothing the first time: unnamed, the honest state.
        $this->deliver($this->body(['mid' => 'm_B1', 'text' => 'hi']))->assertOk();
        $lead = Lead::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertNull($lead->contact_name);

        // The next message fills the blank.
        $this->deliver($this->body(['mid' => 'm_B2', 'text' => 'hello?']))->assertOk();
        $this->assertSame('Meta Name', $lead->fresh()->contact_name);

        // Staff correct it; Meta's name must not come back over theirs.
        $this->inTenant($t['org'], fn () => $lead->fresh()->forceFill(['contact_name' => 'Mrs Peters'])->save());
        $this->deliver($this->body(['mid' => 'm_B3', 'text' => 'still there?']))->assertOk();
        $this->assertSame('Mrs Peters', $lead->fresh()->contact_name);
    }

    public function test_a_reconnected_page_takes_back_only_its_own_hidden_enquiries(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->pageBelongsTo($t);
        $this->holdsToken($page, $t);

        $orphan = fn (string $psid) => $this->inTenant($t['org'], fn () => Lead::query()->create([
            'organization_id' => $t['org']->id, 'source' => Lead::SOURCE_FACEBOOK, 'remote_id' => $psid,
            'status' => Lead::STATUS_NEW, 'received_at' => now(),
        ]));
        $ours = $orphan('PSID-OURS');
        $other = $orphan('PSID-OTHER-PAGE');
        $erred = $orphan('PSID-META-ERRED');

        // A sender id is scoped to one Page: only this Page has a conversation
        // with ours. An error answer must not read as "yes".
        Http::fake([
            '*user_id=PSID-OURS*' => Http::response(['data' => [['id' => 't_1']]]),
            '*user_id=PSID-OTHER-PAGE*' => Http::response(['data' => []]),
            '*user_id=PSID-META-ERRED*' => Http::response(['error' => ['message' => 'nope']], 400),
        ]);

        $adopted = $this->inTenant($t['org'], fn () => app(MetaMessages::class)->reclaim($page));

        $this->assertSame(1, $adopted);
        $this->assertSame($page->id, $ours->fresh()->connection_id);
        $this->assertNull($other->fresh()->connection_id);
        $this->assertNull($erred->fresh()->connection_id);
    }

    public function test_an_instagram_delivery_signed_with_the_instagram_secret_is_accepted(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        config(['services.meta.instagram_secret' => 'instagram-app-secret']);
        $this->pageBelongsTo($t, SocialProvider::InstagramProfessional->value);

        // Instagram Login signs with the Instagram app's secret, not Facebook's.
        $json = $this->body(['mid' => 'm_IG2', 'text' => 'price?'], 'instagram');
        $this->deliver($json, 'sha256='.hash_hmac('sha256', $json, 'instagram-app-secret'))
            ->assertOk()->assertJsonPath('data.handled', 1);

        // The control: a third secret is still nobody's.
        $this->deliver($json, 'sha256='.hash_hmac('sha256', $json, 'somebody-else'))->assertUnauthorized();
    }

    public function test_a_page_we_do_not_hold_is_acknowledged_and_dropped(): void
    {
        $this->provisionTenant('A1-Group');
        $this->configured();
        // Deliberately no connection row.

        // 200, not 4xx: Meta disables a subscription that keeps failing, which
        // would stop delivery for the Pages that DO work.
        $this->deliver($this->body(['mid' => 'm_X', 'text' => 'hi']))
            ->assertOk()->assertJsonPath('data.handled', 0);

        $this->assertSame(0, Lead::query()->withoutGlobalScopes()->count());
    }

    public function test_an_object_we_do_not_read_is_acknowledged(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->pageBelongsTo($t);

        $json = (string) json_encode(['object' => 'permissions', 'entry' => [['id' => self::PAGE_ID]]]);

        $this->deliver($json)->assertOk()->assertJsonPath('data.handled', 0);
    }

    public function test_a_sticker_arrives_as_a_row_with_no_words(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->pageBelongsTo($t);

        $this->deliver($this->body(['mid' => 'm_STICKER']))->assertOk();

        $said = LeadConversation::query()->withoutGlobalScopes()->firstOrFail();

        // Null rather than an empty string. An empty string reads on the screen
        // as somebody having sent a blank message; null reads as "something
        // that is not words", which is what happened.
        $this->assertNull($said->text);
        $this->assertSame(LeadConversation::CHANNEL_MESSAGE, $said->channel);
    }

    /** UI-UX, 2026-11-02: a customer's photo arrived as an empty row. */
    public function test_a_photo_is_streamed_from_meta_on_demand_and_never_stored(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $page = $this->pageBelongsTo($t);
        $this->holdsToken($page, $t);
        Http::fake([
            'graph.facebook.com/*m_PHOTO*' => Http::response(['attachments' => ['data' => [['image_data' => ['url' => 'https://cdn.meta.test/fresh.jpg']]]]]),
            'cdn.meta.test/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response([]),
        ]);

        $this->deliver($this->body(['mid' => 'm_PHOTO', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.meta.test/expires.jpg']], ['type' => 'fallback', 'payload' => ['url' => 'https://example.test/shared']]]]))->assertOk();

        $refs = LeadConversation::query()->withoutGlobalScopes()->firstOrFail()->attachments;
        $this->assertCount(1, $refs);   // a share has no media to stream: no ref, no 404
        $ref = $refs[0];
        $this->assertStringStartsWith(MetaMediaController::PREFIX, $ref);   // not Meta's expiring URL

        $res = $this->get(MetaMediaController::url($ref))->assertOk();
        $this->assertSame('JPEGBYTES', $res->getContent());
        $this->get(str_replace('signature=', 'signature=x', MetaMediaController::url($ref)))->assertForbidden();
    }
}
