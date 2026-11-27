<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Support\Email\HtmlEmailSanitiser;
use App\Support\Email\ImapMailboxReader;
use App\Support\Email\MicrosoftMailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The three HIGH findings of the 11-27 audit, each pinned. */
class EmailAuditFixesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** "</style >" in a sender's CSS must not end our <style> and go live. */
    public function test_a_style_block_cannot_close_itself_and_inject_html(): void
    {
        $out = app(HtmlEmailSanitiser::class)->layout(
            '<style>a{color:red}</style ><meta http-equiv="refresh" content="0;url=https://evil.test"><form action="https://evil.test"></style><p>Hi</p>'
        );

        // Only our own <style> tags remain, and the sender's text cannot open a tag.
        $styles = [];
        preg_match_all('#<style>(.*?)</style>#s', $out, $styles);
        foreach ($styles[1] as $css) {
            $this->assertStringNotContainsString('<', $css);
        }
        $this->assertDoesNotMatchRegularExpression('#<meta http-equiv="refresh"#i', $out);
        $this->assertDoesNotMatchRegularExpression('#<form\b#i', $out);
        $this->assertStringContainsString("form-action 'none'", $out);
        $this->assertStringContainsString("base-uri 'none'", $out);
    }

    /** A line break in the search box cannot start a second IMAP command. */
    public function test_an_imap_search_term_carries_no_line_break(): void
    {
        $term = ImapMailboxReader::searchTerm("invoice\r\nA1 DELETE INBOX\0\"x\\");

        $this->assertDoesNotMatchRegularExpression('/[\r\n\0]/', $term);
        $this->assertSame('"invoice  A1 DELETE INBOX \"x\\\\"', $term);
    }

    /**
     * Graph's delta returns mail that was only read or flagged. Announced as
     * new, an auto-reply rule wrote to the customer again.
     */
    public function test_a_microsoft_message_is_announced_once_and_old_mail_never(): void
    {
        config([
            'services.microsoft_mail.client_id'     => 'a-microsoft-client-id',
            'services.microsoft_mail.client_secret' => 'a-microsoft-client-secret',
        ]);
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inTenant($t['org'], function () use ($t) {
            $m = Mailbox::create([
                'organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'address' => 'info@a-1chimney.com', 'display_name' => 'Info', 'provider' => 'microsoft_oauth',
            ]);
            $m->connect('a-refresh-token');
            $m->save();

            return $m;
        });

        $row = fn (string $id, string $when) => [
            'id' => $id, 'subject' => $id, 'receivedDateTime' => $when, 'isRead' => true,
            'from' => ['emailAddress' => ['address' => 'customer@example.com', 'name' => 'Customer']],
            'toRecipients' => [], 'bodyPreview' => '', 'hasAttachments' => false, 'conversationId' => 'c-'.$id,
        ];
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response([
                'value' => [
                    $row('new-1', now()->subMinutes(5)->toIso8601ZuluString()),
                    $row('read-old', now()->subDays(3)->toIso8601ZuluString()),
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=next',
            ]),
        ]);

        $reader = app(MicrosoftMailboxReader::class);
        $first = $this->inTenant($t['org'], fn () => $reader->arrivalsSince($mailbox, 'mark-1', 50));
        // The same message comes back once it has been read: not a new arrival.
        $second = $this->inTenant($t['org'], fn () => $reader->arrivalsSince($mailbox, 'mark-2', 50));

        $this->assertSame(['new-1'], array_map(fn ($m) => $m->subject, $first->messages));
        $this->assertSame([], $second->messages);
    }
}
