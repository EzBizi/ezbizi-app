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
 * Opening a conversation costs one Gmail fetch when the list said which it is.
 *
 * Measured on production 2026-10-06: every open fetched the message in full,
 * read its thread id, then fetched the thread in full -- which contains that
 * same message again. Two sequential ~165 ms fetches on every open.
 */
class GmailOpenInOneFetchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_mail.client_id'     => 'a-client-id.apps.googleusercontent.com',
            'services.google_mail.client_secret' => 'a-client-secret',
        ]);

        // Thread t-9 holds m-1 (the first) and m-2 (the latest, which is what
        // the list row's id is). Message m-7 lives in thread t-7.
        $msg = fn (string $id, string $thread, string $subject) => [
            'id' => $id, 'threadId' => $thread, 'internalDate' => '1',
            'labelIds' => ['INBOX'],
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [['name' => 'Subject', 'value' => $subject], ['name' => 'From', 'value' => 'a@b.test']],
                'body' => ['data' => rtrim(strtr(base64_encode('Hello'), '+/', '-_'), '=')],
            ],
        ];

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            'gmail.googleapis.com/gmail/v1/users/me/threads/t-9*' => Http::response([
                'id' => 't-9', 'messages' => [$msg('m-1', 't-9', 'First'), $msg('m-2', 't-9', 'Latest')],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/threads/t-7*' => Http::response([
                'id' => 't-7', 'messages' => [$msg('m-7', 't-7', 'Elsewhere')],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m-2*' => Http::response($msg('m-2', 't-9', 'Latest')),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m-7*' => Http::response($msg('m-7', 't-7', 'Elsewhere')),
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

    /** @return list<string> Gmail paths fetched, in order. */
    private function gmailFetches(): array
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'gmail.googleapis.com'))
            ->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))
            ->values()
            ->all();
    }

    public function test_with_the_thread_it_is_one_fetch(): void
    {
        [$t, $mailbox] = $this->gmailInbox();

        $detail = $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->open($mailbox, 'm-2', 't-9'));

        $this->assertSame(['/gmail/v1/users/me/threads/t-9'], $this->gmailFetches());

        // The MESSAGE asked for, not merely the first in the thread.
        $this->assertSame('m-2', $detail->summary->id);
        $this->assertSame('Latest', $detail->summary->subject);
        $this->assertCount(2, $detail->thread);
    }

    /**
     * The control: no hint is the old two fetches. Without this, a reader that
     * ignored the hint entirely would make the test above fail -- but so would
     * a broken fake, and this is what tells the two apart.
     */
    public function test_without_the_thread_it_is_the_old_two_fetches(): void
    {
        [$t, $mailbox] = $this->gmailInbox();

        $detail = $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->open($mailbox, 'm-2'));

        $this->assertSame(
            ['/gmail/v1/users/me/messages/m-2', '/gmail/v1/users/me/threads/t-9'],
            $this->gmailFetches(),
        );
        $this->assertSame('m-2', $detail->summary->id);
    }

    /**
     * A hint is checked, never trusted: a thread that does not hold the message
     * falls back to opening the message the slow way -- and returns the RIGHT
     * message and its own conversation, not the one the hint named.
     */
    public function test_a_wrong_thread_falls_back_to_the_real_message(): void
    {
        [$t, $mailbox] = $this->gmailInbox();

        $detail = $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->open($mailbox, 'm-7', 't-9'));

        $this->assertSame('m-7', $detail->summary->id);
        $this->assertSame('Elsewhere', $detail->summary->subject);
        $this->assertSame([], $detail->thread, 'It carried the hinted thread instead of its own.');
    }
}
