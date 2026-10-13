<?php

namespace Tests\Feature\Social;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Services\Leads\ThumbtackLeads;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackWebhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A Thumbtack enquiry becoming a Lead row.
 *
 * The entry point is an unauthenticated endpoint and the only delivery path
 * there is — their API cannot list negotiations, so anything dropped here is
 * gone permanently. What is pinned is therefore mostly about repeats and
 * failures: the same event twice, a message arriving before its negotiation,
 * and what happens when the fetch fails.
 */
class ThumbtackLeadIngestionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const URL = '/api/v1/social/thumbtack/webhook';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.thumbtack.api_base', 'https://api.thumbtack.com/api');
    }

    public function test_a_negotiation_event_becomes_a_lead_with_the_customer_on_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t);
        $this->fakeThumbtack();

        $this->send($t, ['eventType' => 'NegotiationCreatedV4', 'negotiationID' => 'neg-1'])
            ->assertOk()
            ->assertJsonPath('data.handled', true);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());

        $this->assertSame(Lead::SOURCE_THUMBTACK, $lead->source);
        $this->assertSame('neg-1', $lead->remote_id);
        $this->assertSame('Ada Sweep', $lead->contact_name);
        $this->assertSame('+15552345678', $lead->phone);
        $this->assertSame('Chimney Cleaning', $lead->category);
        $this->assertSame(Lead::STATUS_NEW, $lead->status);

        // The enquiry's own time, not the moment the callback landed. Their
        // timestamps carry an offset, so no account clock is needed.
        $this->assertSame('2026-09-08 14:30:00', $lead->received_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_thread_arrives_with_the_lead_and_the_sides_are_not_swapped(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t);
        $this->fakeThumbtack();

        $this->send($t, ['eventType' => 'NegotiationCreatedV4', 'negotiationID' => 'neg-1'])->assertOk();

        $rows = $this->inTenant(
            $t['org'],
            fn () => LeadConversation::query()->orderBy('occurred_at')->get(),
        );

        $this->assertCount(2, $rows);

        // Their vocabulary is Business/Customer and ours is
        // ADVERTISER/CONSUMER. Swapped, the business's own reply would emit
        // lead.contact_received and tell somebody the customer had written.
        $this->assertSame(LeadConversation::FROM_CONSUMER, $rows[0]->participant);
        $this->assertSame('Do you clean flues?', $rows[0]->text);
        $this->assertSame(LeadConversation::FROM_ADVERTISER, $rows[1]->participant);

        $this->assertSame(['https://cdn.thumbtack.test/flue.jpg'], $rows[0]->attachments);
    }

    public function test_the_same_event_twice_does_not_make_a_second_lead(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t);
        $this->fakeThumbtack();

        // Thumbtack retries on any non-2xx and can deliver twice regardless.
        // Nothing upstream dedupes, so the keys have to.
        foreach (range(1, 3) as $ignored) {
            $this->send($t, ['eventType' => 'NegotiationCreatedV4', 'negotiationID' => 'neg-1'])->assertOk();
        }

        $this->inTenant($t['org'], function () {
            $this->assertSame(1, Lead::query()->count());
            $this->assertSame(2, LeadConversation::query()->count());
        });
    }

    public function test_a_replay_does_not_drag_an_archived_lead_back_to_new(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t);
        $this->fakeThumbtack();

        $this->send($t, ['eventType' => 'NegotiationCreatedV4', 'negotiationID' => 'neg-1'])->assertOk();

        $this->inTenant($t['org'], function () {
            Lead::query()->firstOrFail()->forceFill(['status' => Lead::STATUS_ARCHIVED])->save();
        });

        $this->send($t, ['eventType' => 'NegotiationCreatedV4', 'negotiationID' => 'neg-1'])->assertOk();

        // Somebody's decision outlives the source's repetition.
        $this->assertSame(
            Lead::STATUS_ARCHIVED,
            $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail())->status,
        );
    }

    /**
     * Disconnecting takes the connection row with it and the lead's
     * `connection_id` goes null: no token to read the thread, none to reply,
     * and a lead with no company shows in every view. Jose disconnected
     * Thumbtack and connected it again under A-1 Chimney on 2026-09-10 —
     * exactly the right move — and a real customer's enquiry was left
     * belonging to nothing. The next event on it adopts it.
     */
    public function test_an_orphaned_enquiry_is_adopted_by_the_account_that_answers_for_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $connection = $this->connection($t);
        $this->fakeThumbtack();

        // The state a disconnect leaves behind: the row is here, and nothing
        // it needs is.
        $orphan = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->getKey(),
            'company_id'      => null,
            'connection_id'   => null,
            'source'          => Lead::SOURCE_THUMBTACK,
            'remote_id'       => 'neg-1',
            'contact_name'    => 'Reinaldo Ortiz',
            'status'          => Lead::STATUS_NEW,
            'received_at'     => now()->subMonth(),
        ]));

        $this->send($t, ['eventType' => 'MessageCreatedV4', 'negotiationID' => 'neg-1'])->assertOk();

        $fresh = $this->inTenant($t['org'], fn () => Lead::query()->findOrFail($orphan->getKey()));

        $this->assertSame($connection->getKey(), $fresh->connection_id);
        $this->assertSame($t['company']->getKey(), $fresh->company_id, 'an orphan is also filed as nobody\'s');
        $this->assertSame(1, $this->inTenant($t['org'], fn () => Lead::query()->count()), 'adopted, not duplicated');
    }

    /**
     * Connecting again takes back the business's enquiries at once, without
     * waiting for a webhook to name each one (2026-09-30: three stayed hidden
     * after Jose reconnected). Only its own, and only orphans.
     */
    public function test_reconnecting_takes_back_the_business_enquiries(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->fakeThumbtack();
        $first = $this->connection($t);

        $this->send($t, ['eventType' => 'NegotiationCreatedV4', 'negotiationID' => 'neg-1'])->assertOk();
        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());
        $this->assertSame('biz-1', $lead->remote_customer_id, 'the business is remembered on the lead');

        $other = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->getKey(),
            'source' => Lead::SOURCE_THUMBTACK,
            'remote_id' => 'neg-2',
            'remote_customer_id' => 'biz-9',
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]));

        // The disconnect: the row goes, the lead's connection_id with it.
        $this->inTenant($t['org'], fn () => $first->delete());
        $this->assertNull($this->inTenant($t['org'], fn () => $lead->fresh()->connection_id));

        $again = $this->connection($t);
        $taken = app(ThumbtackLeads::class)->reclaim($again);

        $this->assertSame(1, $taken);
        $this->assertSame($again->getKey(), $this->inTenant($t['org'], fn () => $lead->fresh()->connection_id));
        $this->assertNull($this->inTenant($t['org'], fn () => $other->fresh()->connection_id), 'another business keeps waiting');
    }

    /**
     * The other direction, which is the one that would do harm: a lead that
     * already has an account keeps it. Two businesses can appear on one
     * negotiation, and the one whose token filed it is the one that works.
     */
    public function test_a_lead_that_already_has_an_account_is_not_moved_to_another(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $first = $this->connection($t);
        $this->fakeThumbtack();

        $this->send($t, ['eventType' => 'NegotiationCreatedV4', 'negotiationID' => 'neg-1'])->assertOk();

        $second = $this->inTenant($t['org'], function () use ($t) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->getKey(),
                'provider'        => SocialProvider::Thumbtack->value,
                'remote_id'       => 'biz-2',
                'display_name'    => 'A second business',
            ]);
            $row->connect(['access_token' => 'a', 'refresh_token' => 'r', ThumbtackWebhooks::SECRET_KEY => 'the-secret']);
            $row->save();

            return $row;
        });

        $this->withHeader(ThumbtackWebhooks::AUTH_HEADER, 'the-secret')
            ->postJson(self::URL, ['eventType' => 'MessageCreatedV4', 'negotiationID' => 'neg-1', 'businessID' => 'biz-2'])
            ->assertOk();

        $this->assertSame(
            $first->getKey(),
            $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail())->connection_id,
        );
        $this->assertNotSame($second->getKey(), $first->getKey());
    }

    public function test_a_message_event_files_the_enquiry_it_belongs_to(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t);
        $this->fakeThumbtack();

        // A message can be the FIRST thing we hear: the negotiation event can
        // be missed, or predate the connection. Without absorbing the
        // negotiation first it would have nowhere to be filed.
        $this->send($t, ['eventType' => 'MessageCreatedV4', 'negotiationID' => 'neg-1'])
            ->assertOk()
            ->assertJsonPath('data.handled', true);

        $this->inTenant($t['org'], function () {
            $this->assertSame(1, Lead::query()->count());
            $this->assertSame(2, LeadConversation::query()->count());
        });
    }

    public function test_a_failed_fetch_answers_500_so_thumbtack_retries_rather_than_losing_the_lead(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t);

        Http::fake(['api.thumbtack.com/*' => Http::response(['message' => 'upstream is unwell'], 503)]);

        // The opposite of everywhere else in this controller, and deliberate.
        // A disabled webhook is recoverable by reconnecting; an acknowledged
        // enquiry that was never stored is gone, because nothing can list it.
        // withExceptionHandling, not without: the point is that the FRAMEWORK
        // turns the failure into a 500 response Thumbtack can see and retry,
        // not that the exception reaches this test.
        $this->withExceptionHandling();

        $this->send($t, ['eventType' => 'NegotiationCreatedV4', 'negotiationID' => 'neg-1'])
            ->assertStatus(500);

        $this->inTenant($t['org'], fn () => $this->assertSame(0, Lead::query()->count()));
    }

    public function test_a_review_event_is_acknowledged_and_writes_no_lead(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t);
        $this->fakeThumbtack();

        // Reviews is its own module. Filing one as a Lead would put it in the
        // wrong list under a name that is not an enquirer.
        $this->send($t, ['eventType' => 'ReviewCreatedV4', 'negotiationID' => 'neg-1'])
            ->assertOk()
            ->assertJsonPath('data.handled', false);

        $this->inTenant($t['org'], fn () => $this->assertSame(0, Lead::query()->count()));
        Http::assertNothingSent();
    }

    public function test_an_event_with_no_negotiation_id_is_acknowledged_and_does_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t);
        $this->fakeThumbtack();

        $this->send($t, ['eventType' => 'NegotiationCreatedV4'])
            ->assertOk()
            ->assertJsonPath('data.handled', false);

        $this->inTenant($t['org'], fn () => $this->assertSame(0, Lead::query()->count()));
    }

    // ── helpers ──────────────────────────────────────────────────

    /** @param  array<string, mixed>  $body */
    /** Jose, 2026-10-13: Thumbtack financials - what each lead cost, and a refund showing later. */
    public function test_a_lead_keeps_its_price_and_a_later_refund_shows(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t);
        $negotiation = fn (string $state) => [
            'negotiationID' => 'neg-1', 'createdAt' => '2026-09-08T14:30:00Z',
            'customer' => ['firstName' => 'Ada', 'lastName' => 'Sweep', 'phone' => '+15552345678'],
            'request' => ['category' => ['name' => 'Chimney Cleaning']],
            'leadPrice' => '$14.90', 'chargeState' => $state,
        ];
        // A callback, not URL patterns: the messages URL also matches negotiations/*.
        $states = ['Charged', 'Refunded'];
        Http::fake(function ($request) use (&$states, $negotiation) { return str_ends_with($request->url(), '/messages')
            ? Http::response(['data' => []])
            : Http::response($negotiation(array_shift($states) ?? 'Refunded')); });

        $this->send($t, ['eventType' => 'NegotiationCreatedV4', 'negotiationID' => 'neg-1'])->assertOk();

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());
        $this->assertSame(1490, $lead->lead_cost_minor);
        $this->assertSame('USD', $lead->lead_cost_currency);
        $this->assertSame('CHARGED', $lead->charge_status);

        $this->artisan('ezbizi:thumbtack-lead-costs')->expectsOutputToContain('Read 1 of 1')->assertSuccessful();

        $lead = $this->inTenant($t['org'], fn () => $lead->fresh());
        $this->assertSame('CREDITED', $lead->charge_status, 'the refund shows');
        $this->assertSame(1490, $lead->lead_cost_minor);
    }

    public function test_a_price_reads_in_cents_and_nothing_is_guessed(): void
    {
        $this->assertSame(123450, ThumbtackLeads::cost(['leadPrice' => '$1,234.5'])['lead_cost_minor']);
        $this->assertSame(['lead_cost_minor' => null, 'lead_cost_currency' => null, 'charge_status' => null], ThumbtackLeads::cost([]));
    }

    private function send(array $t, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader(ThumbtackWebhooks::AUTH_HEADER, 'the-secret')
            ->postJson(self::URL, $body + ['businessID' => 'biz-1']);
    }

    /**
     * One fake for the whole exchange.
     *
     * Seeded in ONE call because a second Http::fake in the same test never
     * replaces the first — it stacks, and the earlier stub keeps winning.
     */
    private function fakeThumbtack(): void
    {
        Http::fake([
            'api.thumbtack.com/*/messages' => Http::response(['data' => [
                [
                    'messageID'     => 'msg-1',
                    'negotiationID' => 'neg-1',
                    'from'          => 'Customer',
                    'text'          => 'Do you clean flues?',
                    'sentAt'        => '2026-09-08T14:31:00Z',
                    'attachments'   => [['url' => 'https://cdn.thumbtack.test/flue.jpg']],
                ],
                [
                    'messageID'     => 'msg-2',
                    'negotiationID' => 'neg-1',
                    'from'          => 'Business',
                    'text'          => 'We do — when suits you?',
                    'sentAt'        => '2026-09-08T14:45:00Z',
                ],
            ]]),
            'api.thumbtack.com/*/negotiations/*' => Http::response([
                'negotiationID' => 'neg-1',
                'createdAt'     => '2026-09-08T14:30:00Z',
                'customer'      => [
                    'customerID' => 'cus-1',
                    'firstName'  => 'Ada',
                    'lastName'   => 'Sweep',
                    'phone'      => '+15552345678',
                ],
                'request' => [
                    'requestID' => 'req-1',
                    'category'  => ['categoryID' => 'cat-1', 'name' => 'Chimney Cleaning'],
                ],
            ]),
        ]);
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
}
