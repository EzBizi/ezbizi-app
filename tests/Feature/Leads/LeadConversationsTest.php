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

    private function connected(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $connection = new SocialConnection;
            $connection->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->id,
                'provider'        => SocialProvider::GoogleAds->value,
                'remote_id'       => self::CUSTOMER,
                'display_name'    => GoogleAdsOAuth::readableId(self::CUSTOMER),
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
    private function fakeGoogle(array $leads, array $conversations, array $recovered = []): void
    {
        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        Http::fake(function (Request $request) use ($leads, $conversations, $recovered) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600]);
            }

            if ($request->url() === self::RECORDING) {
                return Http::response('ID3-fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']);
            }

            $query = (string) (((array) $request->data())['query'] ?? '');

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
}
