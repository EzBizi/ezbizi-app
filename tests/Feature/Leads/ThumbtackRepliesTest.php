<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackWebhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Answering a Thumbtack enquiry, and not being told Google's rules about it.
 *
 * ── What happened ──
 *
 * The first real Thumbtack enquiry arrived on 2026-09-10 — Reinaldo Oakes, a
 * message thread — and the composer was not drawn. In its place the screen
 * said "Google only accepts replies to text enquiries. This one came in as a
 * call, so it is answered by calling back": wrong provider, wrong channel,
 * wrong remedy, about a lead Google has never heard of.
 *
 * TWO faults, one visible. No channel was tagged for `thumbtack`, so
 * `canReply` was false; and the sentence explaining a refusal was hard-coded
 * in the client and in the controller, both times as Google's. A reason now
 * belongs to the channel that refuses, through [ExplainsRefusal].
 */
class ThumbtackRepliesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.thumbtack.api_base', 'https://api.thumbtack.com/api');
    }

    private function connection(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->getKey(),
                'provider'        => SocialProvider::Thumbtack->value,
                'remote_id'       => 'biz-1',
                'display_name'    => 'A-1 Chimney on Thumbtack',
            ]);
            $row->connect([
                'access_token'                => 'a-token',
                'refresh_token'               => 'r-token',
                ThumbtackWebhooks::SECRET_KEY => 'the-secret',
            ]);
            $row->save();

            return $row;
        });
    }

    private function lead(array $t, SocialConnection $connection, bool $test = false): Lead
    {
        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->getKey(),
            'company_id'      => $t['company']->getKey(),
            'connection_id'   => $connection->getKey(),
            'source'          => Lead::SOURCE_THUMBTACK,
            'is_test'         => $test,
            'remote_id'       => 'neg-1',
            'contact_name'    => 'Reinaldo Oakes',
            'status'          => Lead::STATUS_NEW,
            'received_at'     => now()->subHour(),
        ]));
    }

    public function test_a_thumbtack_enquiry_can_be_answered_and_the_thread_keeps_what_was_said(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t);
        $lead       = $this->lead($t, $connection);

        Http::fake(['api.thumbtack.com/*' => Http::response([
            'messageID'     => 'msg-99',
            'negotiationID' => 'neg-1',
            'from'          => 'Business',
            'text'          => 'We can come Tuesday.',
            'sentAt'        => '2026-09-10T15:04:00Z',
        ])]);

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/leads/'.$lead->getKey().'/messages', ['text' => 'We can come Tuesday.'])
            ->assertCreated();

        // It went to the negotiation, as the business.
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/v4/negotiations/neg-1/messages')
            && $r->data()['text'] === 'We can come Tuesday.');

        $row = $this->inTenant($t['org'], fn () => LeadConversation::query()->firstOrFail());

        $this->assertSame(LeadConversation::FROM_ADVERTISER, $row->participant);
        $this->assertSame('We can come Tuesday.', $row->text);
        // Their id, not one of ours: it is what the next MessageCreatedV4
        // carries, and it is what stops this sentence arriving twice.
        $this->assertSame('msg-99', $row->remote_id);

        // The list's preview line is a cache of the newest row. Without the
        // refresh the reply is in the thread and the list still shows the
        // customer's last message as the most recent thing said.
        $this->assertSame(
            LeadConversation::FROM_ADVERTISER,
            $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail())->last_contact_from,
        );
    }

    /**
     * A RULE MAY REPLY ON THUMBTACK NOW, AND THE ROW SAYS SOFTWARE WROTE IT.
     *
     * Jose, 2026-09-24: *"it should be for any lead, i don't know why you set a
     * restriction on it."* Thumbtack's refusal was a choice, not a filing.
     *
     * THE PAIR IS THE TEST. A person's reply (above) is unmarked and takes the
     * enquiry off the Unreplied list, which is correct; a rule's reply must be
     * marked, or every lead a rule answers quietly leaves the list somebody
     * works from. Asserting only the automatic half would pass on a column that
     * defaulted to true.
     */
    public function test_a_rule_may_reply_and_the_row_is_marked_automatic(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t);
        $lead       = $this->lead($t, $connection);

        Http::fake(['api.thumbtack.com/*' => Http::response([
            'messageID'     => 'msg-auto',
            'negotiationID' => 'neg-1',
            'from'          => 'Business',
            'text'          => 'Thanks, we will call you shortly.',
            'sentAt'        => '2026-09-10T15:04:00Z',
        ])]);

        $row = $this->inTenant($t['org'], fn () => app(\App\Services\Leads\ThumbtackLeads::class)
            ->send($lead, 'Thanks, we will call you shortly.', automatic: true));

        $this->assertTrue((bool) $row->is_automatic,
            'an unmarked automatic reply would take the lead off the Unreplied list');
    }

    public function test_the_thread_says_a_reply_can_be_sent(): void
    {
        $t    = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t, $this->connection($t));

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads/'.$lead->getKey())
            ->assertOk()
            ->assertJsonPath('data.can_reply', true)
            ->assertJsonPath('data.cannot_reply_because', null);
    }

    /**
     * A specimen's ids "will not refer to any real entities" (Thumbtack's own
     * words), so a reply would 404 against nobody. The screen says that rather
     * than relaying an HTTP status.
     */
    public function test_a_test_enquiry_says_there_is_nobody_to_reply_to(): void
    {
        $t    = $this->provisionTenant('A1-Group');
        $lead = $this->lead($t, $this->connection($t), test: true);

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads/'.$lead->getKey())
            ->assertOk()
            ->assertJsonPath('data.can_reply', false)
            ->assertJsonPath('data.cannot_reply_because', 'This is a test enquiry from Thumbtack, so there is nobody to reply to.');

        Http::fake();

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/leads/'.$lead->getKey().'/messages', ['text' => 'Hello?'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * The sentence that was shown for every source now belongs to Google, and
     * only Google. This is the control that would have caught the defect: a
     * Google call lead must still say it, while the Thumbtack cases above must
     * not.
     */
    public function test_a_google_call_still_says_google_only_takes_text_replies(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $connection = $this->inTenant($t['org'], function () use ($t) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->getKey(),
                'provider'        => SocialProvider::GoogleAds->value,
                'remote_id'       => '4941850746',
                'display_name'    => '494-185-0746',
            ]);
            $row->connect(['refresh_token' => 'rt']);
            $row->save();

            return $row;
        });

        $lead = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->getKey(),
            'company_id'      => $t['company']->getKey(),
            'connection_id'   => $connection->getKey(),
            'source'          => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id'       => 'L-1',
            'lead_type'       => 'PHONE_CALL',
            'status'          => Lead::STATUS_NEW,
            'received_at'     => now(),
        ]));

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads/'.$lead->getKey())
            ->assertOk()
            ->assertJsonPath('data.can_reply', false)
            ->assertJsonPath(
                'data.cannot_reply_because',
                'Google only accepts replies to text enquiries. This one came in as a call, so it is answered by calling back.',
            );
    }
}
