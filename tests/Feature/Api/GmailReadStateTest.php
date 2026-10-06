<?php

namespace Tests\Feature\Api;

use App\Models\Mailbox;
use App\Support\Email\GmailMailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What Gmail is actually asked when read state is carried across.
 *
 * The controller tests use a fake reader, so they prove EzBizi DECIDES to sync
 * and say nothing about the request Google receives. Until this file nothing
 * tested that request at all -- for reading either -- so a wrong label, a
 * wrong endpoint or a thread id where a message id belongs would all have
 * passed every test and changed nothing in anybody's Gmail.
 */
class GmailReadStateTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_mail.client_id'     => 'a-client-id.apps.googleusercontent.com',
            'services.google_mail.client_secret' => 'a-client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m-1?*' => Http::response(
                ['id' => 'm-1', 'threadId' => 't-9'],
            ),
            'gmail.googleapis.com/*' => Http::response(['id' => 'ok']),
        ]);
    }

    /** @return array{0: array, 1: Mailbox} */
    private function gmailInbox(): array
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => 'info@a-1chimney.com',
                'display_name'    => 'Info',
                'provider'        => 'gmail_oauth',
            ]);
            $mailbox->connect('a-refresh-token');
            $mailbox->save();

            return $mailbox;
        });

        return [$t, $mailbox];
    }

    /** @return list<Request> */
    private function modifies(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/modify'))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
    }

    /**
     * Reading marks the whole CONVERSATION: a thread is bold in Gmail while any
     * message in it is unread, so marking one message would leave it bold.
     */
    public function test_reading_removes_unread_from_the_thread(): void
    {
        [$t, $mailbox] = $this->gmailInbox();

        $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->markReadAtSource($mailbox, 'm-1'));

        $modifies = $this->modifies();
        $this->assertCount(1, $modifies);
        $this->assertSame(self::API.'/threads/t-9/modify', $modifies[0]->url());
        $this->assertSame(['removeLabelIds' => ['UNREAD']], $modifies[0]->data());
    }

    /**
     * Unread marks the MESSAGE: one unread message makes the thread bold again
     * without claiming nobody read the earlier replies. The message id, never
     * the thread id -- and ADD, never remove.
     */
    public function test_marking_unread_adds_unread_to_the_message(): void
    {
        [$t, $mailbox] = $this->gmailInbox();

        $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->markUnreadAtSource($mailbox, 'm-1'));

        $modifies = $this->modifies();
        $this->assertCount(1, $modifies);
        $this->assertSame(self::API.'/messages/m-1/modify', $modifies[0]->url());
        $this->assertSame(['addLabelIds' => ['UNREAD']], $modifies[0]->data());
    }

    /**
     * The arrivals fetch ASKS Gmail for the headers `is_bulk` reads. Gmail
     * returns only the headers it is asked for, so a mapper reading
     * List-Unsubscribe from a fetch that never requested it would see nothing
     * in production -- while any fake, which answers whatever it likes, passed.
     */
    public function test_the_arrivals_fetch_asks_for_the_bulk_headers(): void
    {
        [$t, $mailbox] = $this->gmailInbox();

        $fetch = new \ReflectionMethod(GmailMailboxReader::class, 'summariesFor');
        $fetch->setAccessible(true);
        $this->inTenant($t['org'], fn () => $fetch->invoke(app(GmailMailboxReader::class), $mailbox, ['m-1']));

        $asked = urldecode((string) Http::recorded(fn (Request $r) => str_contains($r->url(), '/messages/m-1'))
            ->map(fn ($pair) => $pair[0]->url())->first());

        foreach (['List-Unsubscribe', 'Precedence', 'Auto-Submitted'] as $header) {
            $this->assertStringContainsString($header, $asked, "{$header} was not requested.");
        }
    }
}
