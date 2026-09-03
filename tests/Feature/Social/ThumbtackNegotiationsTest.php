<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackAccount;
use App\Support\Social\ThumbtackNegotiations;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Reading a Thumbtack enquiry, replying to it, and reporting what happened.
 *
 * The job status is the part worth guarding hardest. It is not a display value:
 * Thumbtack measures a Pro's completion rate on it, so a wrong one is a false
 * claim about somebody's work made to the platform that scores them.
 */
class ThumbtackNegotiationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pinned: a developer holding staging credentials has a different
        // THUMBTACK_API_BASE, and these assert on whole URLs.
        config()->set('services.thumbtack.api_base', 'https://api.thumbtack.com/api');
    }

    public function test_an_enquiry_is_fetched_by_id_because_nothing_lists_them(): void
    {
        Http::fake(['*/negotiations/neg-1' => Http::response([
            'negotiationID' => 'neg-1',
            'customer'      => ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'phone' => '+15552345678'],
        ])]);

        $found = ThumbtackNegotiations::for($this->connection())->find('neg-1');

        $this->assertSame('Ada', $found['customer']['firstName']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.thumbtack.com/api/v4/negotiations/neg-1');
    }

    public function test_a_reply_goes_to_the_thread_and_an_empty_one_never_leaves(): void
    {
        Http::fake(['*/messages' => Http::response(['messageID' => 'msg-1'])]);

        $api = ThumbtackNegotiations::for($this->connection());

        $this->assertSame('msg-1', $api->reply('neg-1', 'On my way.')['messageID']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->data() === ['text' => 'On my way.']);

        $this->expectException(InvalidArgumentException::class);
        $api->reply('neg-1', '   ');
    }

    public function test_a_status_thumbtack_does_not_know_is_refused_before_it_is_sent(): void
    {
        Http::fake();

        try {
            ThumbtackNegotiations::for($this->connection())->setJobStatus('neg-1', 'finished');
            $this->fail('an invented status should have been refused');
        } catch (InvalidArgumentException $e) {
            // Their own refusal names the field and not the value, so it reads
            // as "we sent something wrong" without saying what would be right.
            $this->assertStringContainsString('job_complete', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_status_carries_when_it_happened_rather_than_when_it_synced(): void
    {
        Http::fake(['*/job-status' => Http::response([])]);

        $yesterday = now()->subDay();

        ThumbtackNegotiations::for($this->connection())
            ->setJobStatus('neg-1', 'job_complete', $yesterday, invoiceCents: 24500);

        Http::assertSent(function ($request) use ($yesterday) {
            $body = $request->data();

            // Thumbtack requires partnerStatusChangedAt precisely so a status
            // that took a day to reach them still reports the day it happened.
            return $body['status'] === 'job_complete'
                && $body['partnerStatusChangedAt'] === $yesterday->format(DATE_ATOM)
                && $body['invoiceAmountInCents'] === 24500;
        });
    }

    // ── letting go ───────────────────────────────────────────────

    public function test_disconnecting_removes_the_numbers_and_the_webhook_before_revoking(): void
    {
        Http::fake([
            '*/associate-phone-numbers/*' => Http::response([]),
            '*/associate-phone-numbers'   => Http::response(['phoneNumbers' => [
                ['phoneNumberID' => 'pn-1', 'businessID' => 'biz-1',
                    'phoneNumber' => '+15552345678', 'createdTime' => 'then'],
            ]]),
            '*/webhooks/wh-1' => Http::response([]),
            '*/webhooks'      => Http::response(['data' => [['webhookID' => 'wh-1']]]),
            '*/users/disconnect' => Http::response([]),
        ]);

        $this->assertSame([], ThumbtackAccount::tidyUp($this->connection()));

        // Order is the point. Revoking first makes the other two impossible,
        // and the one that most needs doing is the phone numbers — a stale one
        // keeps identifying somebody else's handset as this business.
        Http::assertSentInOrder([
            fn ($r) => str_ends_with($r->url(), '/associate-phone-numbers'),
            fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/associate-phone-numbers/pn-1'),
            fn ($r) => str_ends_with($r->url(), '/webhooks'),
            fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/webhooks/wh-1'),
            fn ($r) => str_ends_with($r->url(), '/users/disconnect'),
        ]);
    }

    public function test_a_dead_connection_still_lets_go_and_says_what_it_could_not_tidy(): void
    {
        // The state this exists to escape: the account somebody is trying to
        // disconnect is exactly the one whose token already stopped working.
        // Refusing to let go because the far end will not answer would trap
        // them in a broken connection with no way out but the database.
        Http::fake(['*' => Http::response(['message' => 'token expired'], 401)]);

        $problems = ThumbtackAccount::tidyUp($this->connection());

        $this->assertCount(3, $problems);
        $this->assertStringContainsString('phone numbers', $problems[0]);
        $this->assertStringContainsString('webhook', $problems[1]);
        $this->assertStringContainsString('revoke', $problems[2]);
    }

    private function connection(): SocialConnection
    {
        $connection = new SocialConnection;
        $connection->forceFill([
            'provider'     => SocialProvider::Thumbtack->value,
            'remote_id'    => 'biz-1',
            'display_name' => 'A-1 Chimney on Thumbtack',
        ]);
        $connection->connect(['access_token' => 'a-token', 'refresh_token' => 'r']);

        return $connection;
    }
}
