<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Services\Leads\LocalServicesLeads;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What was said, not just who called.
 *
 * A Local Services lead row is a header. The message text, the call length and
 * its recording live in a second Google resource this platform never read, so
 * the list showed "unnamed enquiry" and nothing under it while Rodrigo's
 * a1leads, reading `local_services_lead_conversation` from the start, showed
 * the last message under every name. Jose, 2026-09-08: "make sure to get
 * everything, we dont want to miss a single lead."
 */
class LeadConversationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const CUSTOMER   = '4941850746';
    private const RECORDING  = 'https://recordings.google.example/calls/abc123.mp3';

    private function connected(array $t, string $customer = self::CUSTOMER, ?string $via = null): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $customer, $via) {
            $connection = new SocialConnection;
            $connection->forceFill([
                'organization_id'   => $t['org']->id,
                'scope_type'        => SocialConnection::SCOPE_COMPANY,
                'scope_id'          => $t['company']->id,
                'provider'          => SocialProvider::GoogleAds->value,
                'remote_id'         => $customer,
                'login_customer_id' => $via,
                'display_name'      => GoogleAdsOAuth::readableId($customer),
            ]);
            $connection->connect(['refresh_token' => 'rt-test', 'access_token' => 'at-stale']);
            $connection->save();

            return $connection;
        });
    }

    /** @return array<string, mixed> */
    private function leadRow(string $id, string $name = 'Jane Doe'): array
    {
        return ['customer' => ['timeZone' => 'America/New_York'], 'localServicesLead' => [
            'id'               => $id,
            'leadType'         => 'MESSAGE',
            'categoryId'       => 'xcat:service_area_business_chimney_sweep',
            'serviceId'        => 'chimney_repair',
            'locale'           => 'en-US',
            'leadStatus'       => 'NEW',
            'leadCharged'      => true,
            'creationDateTime' => '2026-09-05 08:00:00',
            'contactDetails'   => ['phoneNumber' => '+1 216 555 0100', 'consumerName' => $name],
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function threadRows(string $leadId): array
    {
        $lead = [
            'customer'          => ['timeZone' => 'America/New_York'],
            'localServicesLead' => ['id' => $leadId],
        ];
        $on   = "customers/4941850746/localServicesLeads/{$leadId}";

        return [
            $lead + ['localServicesLeadConversation' => [
                'id'                  => "c-call-{$leadId}",
                'lead'                => $on,
                'conversationChannel' => 'PHONE_CALL',
                'participantType'     => 'CONSUMER',
                'eventDateTime'       => '2026-09-06 10:00:00',
                'phoneCallDetails'    => ['callDurationMillis' => '125000', 'callRecordingUrl' => self::RECORDING],
            ]],
            $lead + ['localServicesLeadConversation' => [
                'id'                  => "c-msg-in-{$leadId}",
                'lead'                => $on,
                'conversationChannel' => 'MESSAGE',
                'participantType'     => 'CONSUMER',
                'eventDateTime'       => '2026-09-07 09:00:00',
                'messageDetails'      => [
                    'text'           => "Is Saturday ok?\nHere is the chimney.",
                    'attachmentUrls' => ['https://lh3.google.example/photo1.jpg'],
                ],
            ]],
            $lead + ['localServicesLeadConversation' => [
                'id'                  => "c-msg-out-{$leadId}",
                'lead'                => $on,
                'conversationChannel' => 'MESSAGE',
                'participantType'     => 'ADVERTISER',
                'eventDateTime'       => '2026-09-07 09:30:00',
                'messageDetails'      => ['text' => 'Yes, 10am works.'],
            ]],
        ];
    }

    /**
     * One fake, routed on the GAQL in the body: the window read of leads, the
     * window read of conversations, and the by-id recovery of a lead.
     */
    private function fakeGoogle(
        array $leads,
        array $conversations,
        array $recovered = [],
        mixed $append = null,   // what Http::response() returns: a promise, not a Response
        array $clients = [],    // customer_client rows a manager lists
        array $managers = [],   // customer ids that describe themselves as managers
    ): void {
        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        // One closure, routed on URL and GAQL. A second Http::fake in the same
        // test STACKS behind this one, and this one's fall-through answers
        // everything - which is how the first reply test got a lead list back
        // from the send.
        Http::fake(function (Request $request) use ($leads, $conversations, $recovered, $append, $clients, $managers) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600]);
            }

            if (str_contains($request->url(), 'localServices:appendLeadConversation')) {
                return $append ?? Http::response(['error' => ['message' => 'unexpected send']], 500);
            }

            if ($request->url() === self::RECORDING) {
                return Http::response('ID3-fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']);
            }

            $query = (string) (((array) $request->data())['query'] ?? '');

            if (str_contains($query, 'FROM customer_client')) {
                return Http::response(['results' => $clients]);
            }

            if (str_contains($query, 'FROM customer')) {
                preg_match('#/customers/(\\d+)/#', $request->url(), $m);
                $id = $m[1] ?? '';

                return Http::response(['results' => [['customer' => [
                    'id' => $id, 'descriptiveName' => "Account {$id}", 'manager' => in_array($id, $managers, true),
                ]]]]);
            }

            if (str_contains($query, 'FROM local_services_lead_conversation')) {
                return Http::response(['results' => $conversations]);
            }

            if (str_contains($query, 'local_services_lead.id IN (')) {
                return Http::response(['results' => $recovered]);
            }

            return Http::response(['results' => $leads]);
        });
    }

    private function sync(array $t, SocialConnection $connection): array
    {
        return $this->inTenant($t['org'], fn () => app(LocalServicesLeads::class)->sync($connection));
    }

    public function test_calls_and_messages_are_stored_under_their_lead_and_the_lead_carries_the_last_one(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle([$this->leadRow('111')], $this->threadRows('111'));

        $result = $this->sync($t, $connection);

        $this->assertSame(3, $result['conversations']['fetched']);
        $this->assertSame(3, $result['conversations']['created']);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());
        $this->assertNotNull($lead);

        // The rest of what Google says about the lead, now asked for.
        $this->assertSame('chimney_repair', $lead->service);
        $this->assertSame('en-US', $lead->locale);

        // And never `note`: GAQL refuses it in a SELECT (400, measured live),
        // and one refused field fails the whole fetch.
        Http::assertNotSent(function (Request $request) {
            $query = (string) (((array) $request->data())['query'] ?? '');

            return str_contains($query, 'local_services_lead.note');
        });

        $thread = $this->inTenant($t['org'], fn () => $lead->conversations()->get());
        $this->assertCount(3, $thread);

        $call = $thread->firstWhere('remote_id', 'c-call-111');
        $this->assertSame('PHONE_CALL', $call->channel);
        $this->assertSame('CONSUMER', $call->participant);
        $this->assertSame(125000, $call->duration_ms);
        $this->assertSame(self::RECORDING, $call->recording_url);

        $in = $thread->firstWhere('remote_id', 'c-msg-in-111');
        $this->assertSame("Is Saturday ok?\nHere is the chimney.", $in->text);
        $this->assertSame(['https://lh3.google.example/photo1.jpg'], $in->attachments);

        // The preview line is the newest row, whoever wrote it, on one line.
        $this->assertSame('MESSAGE', $lead->last_contact_channel);
        $this->assertSame('ADVERTISER', $lead->last_contact_from);
        $this->assertSame('Yes, 10am works.', $lead->last_contact_preview);
        // Google said "2026-09-07 09:30:00" in America/New_York. Stored as UTC.
        $this->assertSame('2026-09-07 13:30:00', $lead->last_contact_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 12:00:00', $lead->received_at->format('Y-m-d H:i:s'));

        // The conversation read is scoped to the account and windowed on the
        // conversation's own time, not the lead's.
        Http::assertSent(function (Request $request) {
            $query = (string) (((array) $request->data())['query'] ?? '');

            return str_contains($query, 'FROM local_services_lead_conversation')
                && str_contains($query, 'local_services_lead_conversation.event_date_time >=')
                && str_contains($request->url(), '/customers/'.self::CUSTOMER.'/googleAds:search')
                && $request->header('login-customer-id') === [self::CUSTOMER];
        });

        // A second sync brings the same three back and stores nothing twice.
        $again = $this->sync($t, $connection);

        $this->assertSame(0, $again['conversations']['created']);
        $this->assertSame(3, $this->inTenant($t['org'], fn () => LeadConversation::query()->count()));
    }

    /**
     * A message this week on a lead from two months ago.
     *
     * The conversation window is on the conversation's time, so the row is
     * inside it while its lead is outside the lead window. That lead is alive
     * - somebody just wrote on it - and it is fetched by id rather than the
     * message being dropped for want of a header.
     */
    public function test_a_conversation_on_a_lead_outside_the_window_fetches_that_lead_by_id(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        // The account has produced a lead before, so conversations are read.
        // Seeded directly: a second Http::fake stacks behind the first rather
        // than replacing it, so one fake per test.
        $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'company_id'      => $t['company']->id,
            'connection_id'   => $connection->getKey(),
            'source'          => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id'       => '111',
            'status'          => Lead::STATUS_NEW,
            'received_at'     => now()->subDays(2),
        ]));

        $this->fakeGoogle(
            leads: [],
            conversations: $this->threadRows('999'),
            recovered: [$this->leadRow('999', 'Old Enquirer')],
        );

        $result = $this->sync($t, $connection);

        $this->assertSame(1, $result['conversations']['leads_recovered']);
        $this->assertSame(3, $result['conversations']['created']);

        $old = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '999')->first());
        $this->assertNotNull($old, 'A lead with a live conversation must exist however old it is.');
        $this->assertSame('Old Enquirer', $old->contact_name);
        $this->assertSame(3, $this->inTenant($t['org'], fn () => $old->conversations()->count()));
        $this->assertSame('Yes, 10am works.', $old->last_contact_preview);

        Http::assertSent(function (Request $request) {
            $query = (string) (((array) $request->data())['query'] ?? '');

            return str_contains($query, 'local_services_lead.id IN (999)');
        });
    }

    /**
     * Nine of A-1 Chimney's twenty-two accounts have never produced a lead
     * (measured 2026-09-08). At one search per account per five-minute tick,
     * asking each of them for conversations would spend 2,592 of the day's
     * 15,000 operations on accounts with nothing to say.
     */
    public function test_an_account_that_has_never_produced_a_lead_is_not_asked_for_conversations(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle([], []);

        $result = $this->sync($t, $connection);

        $this->assertSame(0, $result['conversations']['fetched']);

        Http::assertNotSent(function (Request $request) {
            $query = (string) (((array) $request->data())['query'] ?? '');

            return str_contains($query, 'FROM local_services_lead_conversation');
        });
    }

    public function test_the_lead_detail_lists_the_thread_and_streams_a_recording_through_ezbizi(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle([$this->leadRow('111')], $this->threadRows('111'));
        $this->sync($t, $connection);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());

        $detail = $this->signedInAs($t['admin'])
            ->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('Yes, 10am works.', $detail['last_contact_preview']);
        $this->assertCount(3, $detail['conversations']);

        $call = collect($detail['conversations'])->firstWhere('channel', 'PHONE_CALL');
        $this->assertTrue($call['has_recording']);
        $this->assertSame(125000, $call['duration_ms']);
        $this->assertArrayNotHasKey('recording_url', $call, 'The URL only answers to the token; it never leaves the server.');

        $message = collect($detail['conversations'])
            ->first(fn ($c) => $c['channel'] === 'MESSAGE' && $c['from'] === 'CONSUMER');
        $this->assertSame(['https://lh3.google.example/photo1.jpg'], $message['attachments']);

        // Played through EzBizi with the connection's credentials.
        $audio = $this->signedInAs($t['admin'])
            ->get("/api/v1/leads/{$lead->id}/conversations/{$call['id']}/recording")
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        $this->assertSame('ID3-fake-audio-bytes', $audio->getContent());

        Http::assertSent(fn (Request $request) => $request->url() === self::RECORDING
            && $request->hasHeader('Authorization', 'Bearer at-fresh'));

        // A message has no recording: 404, not an empty file.
        $this->signedInAs($t['admin'])
            ->get("/api/v1/leads/{$lead->id}/conversations/{$message['id']}/recording")
            ->assertNotFound();
    }

    /** "Who is waiting on me": the enquirer spoke last. The one filter drawn. */
    public function test_unreplied_lists_only_leads_where_the_enquirer_spoke_last(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $answered = $this->threadRows('111');                 // ends with ADVERTISER
        $waiting  = array_slice($this->threadRows('222'), 0, 2); // ends with CONSUMER

        $this->fakeGoogle(
            [$this->leadRow('111', 'Answered'), $this->leadRow('222', 'Waiting')],
            [...$answered, ...$waiting],
        );
        $this->sync($t, $connection);

        $names = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads?unreplied=1')
            ->assertOk()
            ->json('data.*.contact_name');

        $this->assertSame(['Waiting'], $names);

        $all = $this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data');
        $this->assertCount(2, $all);
    }

    /**
     * The filter reported OUR fetch state as the customer's behaviour.
     *
     * Conversations are read on a slower tick than leads — ten minutes against
     * five, and slower again on a day the Google quota runs out, because the
     * second pass is the half designed to lose. Until that read lands
     * `last_contact_from` is null, so a lead from somebody who had just rung
     * was absent from "who is waiting on me", then appeared minutes later with
     * nothing about the customer having changed.
     *
     * A lead exists because somebody made an enquiry. No call or message held
     * means nobody has replied to them, which is what the filter is asked.
     */
    public function test_an_enquiry_whose_conversations_have_not_been_read_yet_still_counts_as_waiting(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        // Exactly what one tick of the scheduler leaves behind: the lead
        // discovered, its conversations not fetched yet.
        $this->fakeGoogle([$this->leadRow('333', 'Just rang')], []);
        $this->sync($t, $connection);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());

        // The control: this is the state under test, not a lead that has been
        // replied to and not a lead with a thread.
        $this->assertNull($lead->last_contact_from);

        $names = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads?unreplied=1')
            ->assertOk()
            ->json('data.*.contact_name');

        $this->assertSame(['Just rang'], $names);
    }

    /**
     * Every stored time was four hours old, and a migration cannot know by
     * how much: the offset is the account's, not the platform's. Re-reading
     * a window refreshes every row in it from Google, clock included.
     */
    public function test_days_rereads_the_window_and_corrects_times_already_held(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        // Held as it was stored before the fix: New York time read as UTC.
        $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'company_id'      => $t['company']->id,
            'connection_id'   => $connection->getKey(),
            'source'          => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id'       => '111',
            'status'          => Lead::STATUS_NEW,
            'received_at'     => '2026-09-05 08:00:00',
        ]));

        $this->fakeGoogle([$this->leadRow('111')], $this->threadRows('111'));

        $this->artisan('ezbizi:sync-leads', ['--days' => 30])->assertSuccessful();

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());
        $this->assertSame('2026-09-05 12:00:00', $lead->received_at->format('Y-m-d H:i:s'));
        $this->assertSame(Lead::STATUS_NEW, $lead->status, 'A re-read refreshes the source fields, never ours.');

        Http::assertSent(function (Request $request) {
            $query = (string) (((array) $request->data())['query'] ?? '');
            $floor = now()->subDays(30)->utc()->format('Y-m-d');

            return str_contains($query, 'FROM local_services_lead ')
                && str_contains($query, "creation_date_time >= '{$floor}");
        });
    }

    /**
     * The one write to Google, and a person's message in the customer's name.
     * It is sent through localServices:appendLeadConversation, stored as ours
     * at once under the id Google returns (so the next sync does not store it
     * twice), and the lead's preview line becomes it.
     */
    public function test_a_reply_goes_through_google_and_appears_in_the_thread_as_ours(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle(
            [$this->leadRow('111')],
            array_slice($this->threadRows('111'), 1, 1),
            append: Http::response(
                ['responses' => [['localServicesLeadConversation' => 'customers/4941850746/localServicesLeadConversations/9001']]],
                200,
            ),
        );
        $this->sync($t, $connection);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());

        $sent = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$lead->id}/messages", ['text' => '  Yes, 10am works.  '])
            ->assertCreated()
            ->json('data');

        $this->assertSame('ADVERTISER', $sent['from']);
        $this->assertSame('MESSAGE', $sent['channel']);
        $this->assertSame('Yes, 10am works.', $sent['text']);

        Http::assertSent(function (Request $request) {
            $body = (array) $request->data();
            $conv = (array) (($body['conversations'] ?? [])[0] ?? []);

            return str_contains($request->url(), '/customers/4941850746/localServices:appendLeadConversation')
                && $request->header('login-customer-id') === ['4941850746']
                && $conv['localServicesLead'] === 'customers/4941850746/localServicesLeads/111'
                && $conv['text'] === 'Yes, 10am works.';
        });

        $stored = $this->inTenant($t['org'], fn () => LeadConversation::query()->where('remote_id', '9001')->first());
        $this->assertNotNull($stored, 'stored under the id Google returned');
        $this->assertSame($lead->id, $stored->lead_id);

        $fresh = $this->inTenant($t['org'], fn () => $lead->fresh());
        $this->assertSame('ADVERTISER', $fresh->last_contact_from);
        $this->assertSame('Yes, 10am works.', $fresh->last_contact_preview);

        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->json('data');
        $this->assertTrue($detail['can_reply']);
        $this->assertCount(2, $detail['conversations']);
    }

    public function test_a_call_lead_cannot_be_replied_to_and_nothing_is_sent(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $call = $this->leadRow('222', 'Caller');
        $call['localServicesLead']['leadType'] = 'PHONE_CALL';

        $this->fakeGoogle([$call], []);
        $this->sync($t, $connection);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '222')->first());

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$lead->id}/messages", ['text' => 'Hello?'])
            ->assertStatus(422);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'appendLeadConversation'));

        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->json('data');
        $this->assertFalse($detail['can_reply']);
    }

    /**
     * A refused send stores nothing and says so. And it is NOT retried: an
     * ambiguous answer after Google has accepted the message must never
     * become the same sentence arriving twice at a stranger's phone.
     */
    public function test_a_reply_google_refuses_is_not_stored_and_not_retried(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle(
            [$this->leadRow('111')],
            [],
            append: Http::response(
                ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'The lead is no longer active.']],
                400,
            ),
        );
        $this->sync($t, $connection);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$lead->id}/messages", ['text' => 'Hello?'])
            ->assertStatus(502);

        $this->assertSame(0, $this->inTenant($t['org'], fn () => LeadConversation::query()->count()));
        $sends = Http::recorded(fn (Request $request) => str_contains($request->url(), 'appendLeadConversation'));
        $this->assertCount(1, $sends, 'exactly one send: a refusal is never retried');
    }

    /**
     * Read is SHARED (Jose, 2026-10-05): once anybody opens a lead it is read
     * for everyone, and a new message after the mark makes it unread again.
     */
    public function test_read_is_shared_and_a_new_message_makes_a_lead_unread_again(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle([$this->leadRow('111'), $this->leadRow('222', 'Other')], []);
        $this->sync($t, $connection);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());

        $list = $this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data');
        $this->assertSame([true, true], array_column($list, 'is_unread'), 'nobody has read anything yet');

        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/read")->assertOk();

        $mine = collect($this->signedInAs($t['admin'])->getJson('/api/v1/leads?unread=1')->json('data'));
        $this->assertSame(['Other'], $mine->pluck('contact_name')->all(), 'the one I read is no longer unread for me');

        $colleague = $this->userWithRole($t, 'Colleague', ['leads.view', 'leads.local_services.view']);
        $theirs = collect($this->signedInAs($colleague)->getJson('/api/v1/leads?unread=1')->json('data'));
        $this->assertSame(['Other'], $theirs->pluck('contact_name')->all(), 'and for a colleague who never opened it');

        // A message arrives after my mark: unread again, for me. Seeded
        // directly (a second Http::fake would stack behind the first), a
        // minute later so the mark and the message do not share a second.
        $this->inTenant($t['org'], function () use ($lead) {
            LeadConversation::create([
                'organization_id' => $lead->organization_id,
                'lead_id'         => $lead->id,
                'remote_id'       => 'later',
                'channel'         => 'MESSAGE',
                'participant'     => 'CONSUMER',
                'occurred_at'     => now()->addMinute(),
                'text'            => 'Still there?',
            ]);
            $lead->forceFill(['last_contact_at' => now()->addMinute(), 'last_contact_from' => 'CONSUMER'])->save();
        });

        $mine = collect($this->signedInAs($t['admin'])->getJson('/api/v1/leads?unread=1')->json('data'));
        $this->assertContains('Jane Doe', $mine->pluck('contact_name')->all());

        // Everything, in one go. The clock moves past the seeded message
        // first: a mark cannot cover a message that has not happened yet.
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(2));
        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/read-all')->assertOk()->assertJsonPath('data.marked', 2);
        $this->assertSame([], $this->signedInAs($t['admin'])->getJson('/api/v1/leads?unread=1')->json('data'));
    }

    /** Jose, 2026-10-13: "for LS CALLS automatically mark them as read ... as they are non actionable". */
    public function test_an_ls_call_is_never_unread(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $call = $this->leadRow('333', 'Caller');
        $call['localServicesLead']['leadType'] = 'PHONE_CALL';
        $this->fakeGoogle([$this->leadRow('111'), $call], []);
        $this->sync($t, $connection);

        $list = collect($this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data'));
        $this->assertFalse($list->firstWhere('contact_name', 'Caller')['is_unread']);
        $this->assertTrue($list->firstWhere('contact_name', 'Jane Doe')['is_unread'], 'a text still is');

        $unread = $this->signedInAs($t['admin'])->getJson('/api/v1/leads?unread=1')->json();
        $this->assertSame(['Jane Doe'], array_column($unread['data'], 'contact_name'));
        $this->assertArrayNotHasKey('ls_call', (array) $unread['meta']['unread_by_source']);
    }

    public function test_star_and_note_are_marks_on_the_lead_and_starred_filters(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle([$this->leadRow('111'), $this->leadRow('222', 'Other')], []);
        $this->sync($t, $connection);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());

        $data = $this->signedInAs($t['admin'])
            ->patchJson("/api/v1/leads/{$lead->id}", ['starred' => true, 'note' => '  Wants Saturday.  '])
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['starred']);
        $this->assertSame('Wants Saturday.', $data['note']);
        $this->assertSame($t['admin']->name, $data['note_by']['name'], 'a shared line says who wrote it');
        $this->assertNotNull($data['note_updated_at']);
        $this->assertSame(Lead::STATUS_NEW, $data['status'], 'marks never move the status');

        $starred = $this->signedInAs($t['admin'])->getJson('/api/v1/leads?starred=1')->json('data.*.contact_name');
        $this->assertSame(['Jane Doe'], $starred);

        // A star is the starrer's own (Jose, 2026-09-30); the note is shared.
        $other = $this->userWithRole($t, 'Dispatcher', ['leads.view', 'leads.local_services.view']);
        $this->assertSame([], $this->signedInAs($other)->getJson('/api/v1/leads?starred=1')->json('data'));
        $seen = $this->signedInAs($other)->getJson("/api/v1/leads/{$lead->id}")->assertOk()->json('data');
        $this->assertFalse($seen['starred']);
        $this->assertSame('Wants Saturday.', $seen['note']);

        // The author survives a PATCH that does not touch the note - on BOTH
        // return paths. Re-sending the same star is the unchanged path.
        foreach ([['starred' => false], ['starred' => false]] as $body) {
            $again = $this->signedInAs($t['admin'])->patchJson("/api/v1/leads/{$lead->id}", $body)->assertOk()->json('data');
            $this->assertSame($t['admin']->name, $again['note_by']['name'], 'starring must not lose the author of the note');
        }

        // Blank clears; unstar clears.
        $data = $this->signedInAs($t['admin'])
            ->patchJson("/api/v1/leads/{$lead->id}", ['starred' => false, 'note' => '   '])
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['starred']);
        $this->assertNull($data['note']);
        $this->assertNull($data['note_by'], 'a cleared note has no author');
    }

    /**
     * Item 2 of the a1tools list. What a1leads books into Workiz, EzBizi
     * books into its own Jobs: one call makes the customer and the job, marks
     * the lead booked and pointing at the job, and raises lead.converted.
     */
    public function test_booking_a_lead_makes_a_customer_and_a_job_and_marks_it_booked(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle([$this->leadRow('111')], []);
        $this->sync($t, $connection);

        $lead   = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());
        $status = $this->inTenant($t['org'], fn () => \App\Models\Job::allowedStatuses($t['franchise'])[0]);

        $data = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$lead->id}/book", [
                'franchise_id'  => $t['franchise']->id,
                'customer'      => ['name' => 'Jane A. Doe', 'email' => 'jane@example.com', 'city' => 'Cleveland', 'state' => 'oh'],
                'title'         => 'Fireplace chimney',
                'status'        => $status,
                'scheduled_for' => '2026-09-17 14:00:00',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertTrue($data['customer_created']);
        $this->assertSame('Jane A. Doe', $data['customer']['name'], 'the corrected name, not the lead\'s');
        $this->assertSame('+1 216 555 0100', $data['customer']['phone'], 'the lead\'s number when none was given');
        $this->assertSame('Fireplace chimney', $data['job']['title']);
        $this->assertNotEmpty($data['job']['number']);
        $this->assertSame('booked', $data['lead']['status']);
        $this->assertSame($data['job']['id'], $data['lead']['job']['id']);

        $this->inTenant($t['org'], function () use ($lead, $t) {
            $fresh = $lead->fresh();
            $this->assertSame(Lead::STATUS_BOOKED, $fresh->status);
            $this->assertNotNull($fresh->job);
            $this->assertSame('Jane Doe', $fresh->contact_name, 'the lead stays evidence; the customer carries the correction');
            $this->assertSame('OH', $fresh->job->customer->state);
            $this->assertSame($t['franchise']->id, $fresh->job->franchise_id);
        });

        // The detail now carries the job, so the header can say so.
        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->json('data');
        $this->assertSame('Fireplace chimney', $detail['job']['title']);
    }

    public function test_booking_can_attach_the_customer_the_number_already_belongs_to(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle([$this->leadRow('111')], []);
        $this->sync($t, $connection);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());

        $existing = $this->inTenant($t['org'], fn () => \App\Models\Customer::create([
            'owning_franchise_id' => $t['franchise']->id,
            'number'              => 'C-0001',
            'name'                => 'Jane Doe (existing)',
            'phone'               => '(216) 555-0100',   // same digits, different punctuation
        ]));

        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->json('data');
        $this->assertSame([$existing->id], array_column($detail['customer_matches'], 'id'));

        $status = $this->inTenant($t['org'], fn () => \App\Models\Job::allowedStatuses($t['franchise'])[0]);

        $data = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$lead->id}/book", [
                'franchise_id' => $t['franchise']->id,
                'customer_id'  => $existing->id,
                'title'        => 'Sweep',
                'status'      => $status,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertFalse($data['customer_created']);
        $this->assertSame($existing->id, $data['customer']['id']);
        $this->assertSame(1, $this->inTenant($t['org'], fn () => \App\Models\Customer::query()->count()), 'no second Jane Doe');
    }

    public function test_a_company_level_lead_needs_a_franchise_picked_and_a_status_the_tenant_has(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);   // company-scoped: leads land with franchise_id null

        $this->fakeGoogle([$this->leadRow('111')], []);
        $this->sync($t, $connection);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', '111')->first());
        $this->assertNull($lead->franchise_id);

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$lead->id}/book", ['title' => 'Sweep', 'status' => 'new'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['franchise_id']);

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$lead->id}/book", [
                'franchise_id' => $t['franchise']->id, 'title' => 'Sweep', 'status' => 'not-a-real-status',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(0, $this->inTenant($t['org'], fn () => \App\Models\Job::query()->count()));
        $this->assertSame(Lead::STATUS_NEW, $this->inTenant($t['org'], fn () => $lead->fresh()->status));
    }

    /**
     * Jose, 2026-09-08: a lead a1tools showed at the top "was not appearing"
     * here. It was here, far down: ordered by the day it arrived while the
     * dispatcher was looking for the text that arrived five minutes ago.
     */
    public function test_the_list_is_ordered_by_latest_activity_not_by_arrival(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $old = $this->leadRow('111', 'Old lead, new text');
        $old['localServicesLead']['creationDateTime'] = '2026-09-01 08:00:00';
        $new = $this->leadRow('222', 'New lead, silent');
        $new['localServicesLead']['creationDateTime'] = '2026-09-08 08:00:00';

        // The old lead's enquirer wrote this morning; the new lead has said nothing.
        $text = array_slice($this->threadRows('111'), 1, 1);
        $text[0]['localServicesLeadConversation']['eventDateTime'] = '2026-09-08 09:00:00';

        $this->fakeGoogle([$old, $new], $text);
        $this->sync($t, $connection);

        $names = $this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data.*.contact_name');

        $this->assertSame(['Old lead, new text', 'New lead, silent'], $names);
    }

    /**
     * Where a reply goes is the source's business. A source with no channel
     * cannot be replied to and says so; a source that tags one is used
     * without the controller learning its name. Web Management's website
     * chat is the first such source.
     */
    public function test_a_reply_goes_through_the_channel_tagged_for_the_leads_source(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $chat = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'company_id'      => $t['company']->id,
            'source'          => 'website_chat',
            'remote_id'       => 'chat-1',
            'contact_name'    => 'Visitor',
            'status'          => Lead::STATUS_NEW,
            'received_at'     => now(),
        ]));

        // No channel for website_chat yet: honest refusal, nothing stored.
        $this->assertFalse($this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$chat->id}")->json('data.can_reply'));
        $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$chat->id}/messages", ['text' => 'Hi'])->assertStatus(422);

        // A source registers its channel the way Web Management will: a tag.
        $channel = new class implements \App\Support\Leads\LeadReplyChannel {
            public array $sent = [];
            public function source(): string { return 'website_chat'; }
            public function canReply(Lead $lead): bool { return true; }
            public function allowsUnattendedReply(): bool { return true; }
            public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
            {
                $this->sent[] = $text;
                return LeadConversation::create([
                    'organization_id' => $lead->organization_id, 'lead_id' => $lead->id,
                    'remote_id' => 'chat-reply-1', 'channel' => 'LIVECHAT',
                    'participant' => LeadConversation::FROM_ADVERTISER, 'occurred_at' => now(), 'text' => $text,
                ]);
            }
        };
        $this->app->instance('test.chat-channel', $channel);
        $this->app->tag(['test.chat-channel'], \App\Support\Leads\LeadReplies::TAG);

        $this->assertTrue($this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$chat->id}")->json('data.can_reply'));

        $sent = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/leads/{$chat->id}/messages", ['text' => 'Hello from the desk'])
            ->assertCreated()
            ->json('data');

        $this->assertSame(['Hello from the desk'], $channel->sent);
        $this->assertSame('LIVECHAT', $sent['channel']);
        $this->assertSame('ADVERTISER', $sent['from']);
        Http::assertNothingSent();
    }

    /** Source is the fourth filter: one list across every source, narrowed by chip. */
    public function test_the_list_narrows_by_source(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $this->fakeGoogle([$this->leadRow('111', 'From Google')], []);
        $this->sync($t, $connection);

        $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
            'source' => 'website_form', 'remote_id' => 'form-1', 'contact_name' => 'From a form',
            'status' => Lead::STATUS_NEW, 'received_at' => now(),
        ]));

        $all  = $this->signedInAs($t['admin'])->getJson('/api/v1/leads')->json('data.*.contact_name');
        $form = $this->signedInAs($t['admin'])->getJson('/api/v1/leads?source=website_form')->json('data.*.contact_name');
        $ads  = $this->signedInAs($t['admin'])->getJson('/api/v1/leads?source=local_services')->json('data.*.contact_name');

        $this->assertCount(2, $all);
        $this->assertSame(['From a form'], $form);
        $this->assertSame(['From Google'], $ads);
    }

    /**
     * Two messages inside one second keep their order. Web Management's
     * first chat test caught a staff reply rendering above the visitor's
     * question; Google stamps to the microsecond and the column dropped it.
     */
    public function test_messages_inside_one_second_keep_their_order(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $lead = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
            'source' => 'website_chat', 'remote_id' => 'chat-1', 'contact_name' => 'Visitor',
            'status' => Lead::STATUS_NEW, 'received_at' => now(),
        ]));

        $base = \Illuminate\Support\Carbon::parse('2026-09-08 21:20:40.100000', 'UTC');

        $this->inTenant($t['org'], function () use ($lead, $base) {
            $threads = app(\App\Support\Leads\LeadThread::class);
            $threads->store($lead, 'm-1', ['channel' => 'LIVECHAT', 'participant' => 'CONSUMER', 'occurred_at' => $base, 'text' => 'Are you open Saturday?']);
            $threads->store($lead, 'm-2', ['channel' => 'LIVECHAT', 'participant' => 'ADVERTISER', 'occurred_at' => $base->copy()->addMilliseconds(300), 'text' => 'We are, 8 until 1.']);
            $threads->refresh($lead);
        });

        $rows = $this->inTenant($t['org'], fn () => $lead->conversations()->get());
        $this->assertSame(['m-1', 'm-2'], $rows->pluck('remote_id')->all());
        $this->assertSame('21:20:40.400000', $rows[1]->occurred_at->format('H:i:s.u'), 'the fraction survives the write');
        $this->assertSame('We are, 8 until 1.', $this->inTenant($t['org'], fn () => $lead->fresh()->last_contact_preview));
    }

    /**
     * The thread itself, not only Web's endpoint: two rows with the SAME
     * occurred_at come back in insertion order, with rows around them,
     * which is what changed the engine's mind the first time.
     */
    public function test_the_thread_keeps_insertion_order_when_two_stamps_are_equal(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $lead = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
            'source' => 'website_chat', 'remote_id' => 'chat-1', 'contact_name' => 'Visitor',
            'status' => Lead::STATUS_NEW, 'received_at' => now(),
        ]));

        $stamp = \Illuminate\Support\Carbon::parse('2026-09-08 21:20:40', 'UTC');

        $this->inTenant($t['org'], function () use ($lead, $stamp) {
            $threads = app(\App\Support\Leads\LeadThread::class);
            $threads->store($lead, 'm-0', ['channel' => 'LIVECHAT', 'participant' => 'ADVERTISER', 'occurred_at' => $stamp->copy()->subMinute(), 'text' => 'Hello, how can we help?']);
            $threads->store($lead, 'm-1', ['channel' => 'LIVECHAT', 'participant' => 'CONSUMER', 'occurred_at' => $stamp, 'text' => 'Is the fireplace inspection something you do?']);
            $threads->store($lead, 'm-2', ['channel' => 'LIVECHAT', 'participant' => 'ADVERTISER', 'occurred_at' => $stamp, 'text' => 'We do. What is the address?']);
            $threads->store($lead, 'm-3', ['channel' => 'LIVECHAT', 'participant' => 'CONSUMER', 'occurred_at' => $stamp->copy()->addMinute(), 'text' => '14 Ash Lane.']);
            $threads->refresh($lead);
        });

        $detail = $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}")->assertOk()->json('data.conversations');

        $this->assertSame(
            ['Hello, how can we help?', 'Is the fireplace inspection something you do?', 'We do. What is the address?', '14 Ash Lane.'],
            array_column($detail, 'text'),
            'a thread that reads backwards is the one defect a thread cannot survive',
        );
    }

    /**
     * A connection reached through a manager reads with the manager as
     * login-customer-id on every call. Without it Google answers
     * PERMISSION_DENIED, which is how twenty-three of A-1 Chimney's accounts
     * stayed silent (2026-09-09).
     */
    public function test_a_connection_reached_through_a_manager_reads_through_it(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t, '2796623321', via: '4051729356');

        $this->fakeGoogle([$this->leadRow('111', 'Stamford enquirer')], []);
        $this->sync($t, $connection);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/customers/2796623321/googleAds:search')
                && $request->header('login-customer-id') === ['4051729356'];
        });

        $this->assertSame(1, $this->inTenant($t['org'], fn () => Lead::query()->where('connection_id', $connection->id)->count()));
    }

    /**
     * The rows made before login_customer_id existed: every enabled client
     * under a connected manager is connected under the same grant, reading
     * through it. Idempotent, and never a cancelled or manager client.
     */
    public function test_adopt_manager_clients_connects_what_the_manager_reaches_and_nothing_twice(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $manager   = $this->connected($t, '4051729356');
        $cleveland = $this->connected($t, self::CUSTOMER);

        $this->fakeGoogle([], [], managers: ['4051729356'], clients: [
            ['customerClient' => ['id' => '4051729356', 'descriptiveName' => 'A1 Chimney - Ads Manager', 'level' => 0, 'manager' => true, 'status' => 'ENABLED']],
            ['customerClient' => ['id' => self::CUSTOMER, 'descriptiveName' => 'A1 Chimney - Cleveland', 'level' => 1, 'manager' => false, 'status' => 'ENABLED']],
            ['customerClient' => ['id' => '2796623321', 'descriptiveName' => 'A1 Chimney - Stamford', 'level' => 2, 'manager' => false, 'status' => 'ENABLED']],
            ['customerClient' => ['id' => '8839813550', 'descriptiveName' => 'A1 Chimney - Hamden', 'level' => 2, 'manager' => false, 'status' => 'ENABLED']],
            ['customerClient' => ['id' => '4634809513', 'descriptiveName' => 'A1 Chimney - Connecticut', 'level' => 1, 'manager' => false, 'status' => 'CANCELED']],
        ]);

        $this->artisan('leads:adopt-manager-clients')->assertSuccessful();

        $rows = $this->inTenant($t['org'], fn () => SocialConnection::query()
            ->where('provider', 'google_ads')->orderBy('remote_id')->get(['remote_id', 'login_customer_id', 'display_name', 'scope_type', 'scope_id', 'is_manager']));

        $this->assertSame(['2796623321', '4051729356', self::CUSTOMER, '8839813550'], $rows->pluck('remote_id')->all());
        $stamford = $rows->firstWhere('remote_id', '2796623321');
        $this->assertSame('4051729356', $stamford->login_customer_id);
        $this->assertSame('A1 Chimney - Stamford', $stamford->display_name);
        $this->assertSame($manager->scope_id, $stamford->scope_id, 'placed where the manager is placed');
        $this->assertNull($rows->firstWhere('remote_id', self::CUSTOMER)->login_customer_id, 'a direct row stays direct');

        // The adopted row can refresh a token: it carries the manager's grant.
        $refresh = $this->inTenant($t['org'], fn () => SocialConnection::query()->where('remote_id', '2796623321')->first()->credentials['refresh_token'] ?? null);
        $this->assertSame('rt-test', $refresh);

        // Google said the manager is one: kept on its row for the Ads report
        // (2026-10-12); a client is not.
        $this->assertTrue($rows->firstWhere('remote_id', '4051729356')->is_manager);
        $this->assertFalse($rows->firstWhere('remote_id', self::CUSTOMER)->is_manager);

        // Second run: nothing new.
        $this->artisan('leads:adopt-manager-clients')->assertSuccessful();
        $this->assertSame(4, $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', 'google_ads')->count()));
    }

    /**
     * "609", "(609)" and "609-246" all find +1 216 555 0100's neighbour; the
     * four-digit floor made the short form find nothing (Jose, 2026-09-09).
     * Words in the last message count too.
     */
    public function test_search_matches_a_number_in_any_format_from_two_digits_and_the_last_message(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connected($t);

        $lead = $this->leadRow('111', 'Jane Doe');
        $lead['localServicesLead']['contactDetails']['phoneNumber'] = '+1 (609) 246-1458';
        $this->fakeGoogle([$lead], array_slice($this->threadRows('111'), 1, 1));
        $this->sync($t, $connection);

        foreach (['609', '(609)', '609-246', '609 246 1', '2461458', 'Saturday'] as $needle) {
            $names = $this->signedInAs($t['admin'])->getJson('/api/v1/leads?search='.urlencode($needle))->assertOk()->json('data.*.contact_name');
            $this->assertSame(['Jane Doe'], $names, "'$needle' must find the lead");
        }

        $this->assertSame([], $this->signedInAs($t['admin'])->getJson('/api/v1/leads?search=777')->json('data'));

        // Words from ANY message in the thread, not only the last (Jose,
        // 2026-09-30, "search inside messages" as A1 Leads had it).
        $this->inTenant($t['org'], fn () => LeadConversation::create([
            'organization_id' => $t['org']->id,
            'lead_id' => Lead::query()->where('remote_id', '111')->value('id'),
            'remote_id' => 'older-1', 'channel' => 'MESSAGE', 'participant' => 'CONSUMER',
            'occurred_at' => now()->subYear(), 'text' => 'The chimney crown is cracked',
        ]));
        $this->assertSame(['Jane Doe'], $this->getJson('/api/v1/leads?search='.urlencode('crown is'))->json('data.*.contact_name'));
    }
}
