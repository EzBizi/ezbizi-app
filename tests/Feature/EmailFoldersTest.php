<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\GmailMailboxReader;
use App\Support\Email\ImapMailboxReader;
use App\Support\Email\MicrosoftMailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Which folder you asked for, and whether you got it.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THE FAILURE THIS FILE EXISTS FOR: it succeeds, it displays, and
 *  it is wrong.
 *
 *  ALL THREE readers had it, in three different spellings, and the
 *  third was found only after the first two were fixed and reported.
 *
 *    Graph  a hardcoded /mailFolders/inbox URL
 *    IMAP   the argument ignored outright
 *    Gmail  a mapping that answered INBOX for anything unrecognised
 *
 *  Gmail was the one written up as honouring the label. It honoured
 *  the six it knew and quietly answered the Inbox for everything
 *  else, behind a comment — "anything else is Inbox, not a guessed
 *  label" — that had the right instinct about guessing and the wrong
 *  safe default underneath it.
 *
 *  Asking for Sent and getting RECEIVED mail, with a 200 and nothing
 *  anywhere saying otherwise: somebody checking what the business had
 *  sent a customer would have been reading what the customer sent
 *  them.
 *
 *  None of it was reachable from the screen. That makes it latent,
 *  not harmless — the endpoint takes the parameter from anyone, and
 *  the day the client widened its folder list the lie would have
 *  shipped with it.
 *
 *  The lesson is in the third instance rather than the first: a
 *  fallback that looks like caution IS this bug. "Default to the safe
 *  thing" and "return the wrong thing confidently" are the same line
 *  of code when the safe thing is a different answer to the question
 *  that was asked.
 * ═══════════════════════════════════════════════════════════════════
 */
class EmailFoldersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Without these the reader refuses before it ever looks at a folder,
        // and every assertion below would pass for the wrong reason.
        config([
            'services.microsoft_mail.client_id'     => 'a-microsoft-client-id',
            'services.microsoft_mail.client_secret' => 'a-microsoft-client-secret',
        ]);
    }

    private function mailbox(array $t, string $provider): Mailbox
    {
        return $this->inTenant($t['org'], function () use ($t, $provider) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => 'info@a-1chimney.com',
                'display_name'    => 'Info',
                'provider'        => $provider,
                'mail_server'     => ['imap_host' => '93.184.216.34', 'imap_port' => 993],
            ]);

            $mailbox->connect('a-credential');
            $mailbox->save();

            return $mailbox;
        });
    }

    // ── Outlook: a real folder, addressed ────────────────────────

    /**
     * Every folder the client can offer reaches the folder it names.
     *
     * One test over the whole map rather than a case each: the property is
     * that the mapping is honoured, and a map with one wrong entry is the
     * failure — which a loop catches and names.
     */
    public function test_every_outlook_folder_is_read_from_that_folder(): void
    {
        foreach ([
            'INBOX'   => 'inbox',
            'SENT'    => 'sentitems',
            'DRAFT'   => 'drafts',
            'TRASH'   => 'deleteditems',
            'SPAM'    => 'junkemail',
            'ARCHIVE' => 'archive',
        ] as $label => $graphFolder) {
            Http::fake([
                'login.microsoftonline.com/*' => Http::response(
                    ['access_token' => 'an-access-token', 'expires_in' => 3599],
                ),
                'graph.microsoft.com/*' => Http::response(['value' => []]),
            ]);

            $t       = $this->provisionTenant('A1-Group-'.$label);
            $mailbox = $this->mailbox($t, 'microsoft_oauth');

            $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->list($mailbox, label: $label));

            Http::assertSent(
                fn ($request) => str_contains($request->url(), "/mailFolders/{$graphFolder}/messages"),
            );
        }
    }

    /**
     * The one that would have been silent: Sent must not read the Inbox.
     *
     * Asserted as an ABSENCE as well as a presence, because a reader that
     * requested both would pass a "did it ask for sentitems" check while still
     * showing the wrong list.
     */
    public function test_asking_outlook_for_sent_does_not_read_the_inbox(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(
                ['access_token' => 'an-access-token', 'expires_in' => 3599],
            ),
            'graph.microsoft.com/*' => Http::response(['value' => []]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->mailbox($t, 'microsoft_oauth');

        $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->list($mailbox, label: 'SENT'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/mailFolders/sentitems/messages'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/mailFolders/inbox/messages'));
    }

    /**
     * A folder Graph cannot address REFUSES; it does not fall back.
     *
     * Starred is the live example: Graph has no starred folder, only a flag
     * filter over the inbox. Offering it and showing the inbox is precisely
     * the failure above wearing a different label.
     */
    public function test_a_folder_outlook_does_not_have_is_refused_not_substituted(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(
                ['access_token' => 'an-access-token', 'expires_in' => 3599],
            ),
            'graph.microsoft.com/*' => Http::response(['value' => []]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->mailbox($t, 'microsoft_oauth');

        try {
            $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->list($mailbox, label: 'STARRED'));

            $this->fail('A folder Outlook does not have was accepted, so something was shown for it.');
        } catch (CannotReadMailbox $e) {
            $this->assertStringContainsString('STARRED', $e->getMessage());
            $this->assertStringContainsString('Sent', $e->getMessage());
        }

        // And nothing was fetched — the refusal happens before the request, so
        // a wrong list cannot have been built and thrown away.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com'));
    }

    /**
     * A label that is not a folder at all cannot address a Graph resource.
     *
     * The map is what makes this true: an unrecognised label produces a
     * refusal rather than a request, so there is no string that reaches a
     * resource the map does not name.
     */
    public function test_a_label_cannot_be_used_to_address_anything_else(): void
    {
        Http::fake(['*' => Http::response(['value' => []])]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->mailbox($t, 'microsoft_oauth');

        foreach (['../../users', 'inbox/messages?$top=999', 'INBOX/../drafts'] as $hostile) {
            try {
                $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->list($mailbox, label: $hostile));

                $this->fail("A label of \"{$hostile}\" was accepted.");
            } catch (CannotReadMailbox) {
                // The refusal is the pass.
            }
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'graph.microsoft.com'));
    }

    // ── IMAP: honest about offering only the Inbox ───────────────

    // ── Gmail: the provider that was said to honour the label ────

    /**
     * A folder Gmail does not have is refused, NOT quietly turned into Inbox.
     *
     * This is the third instance of one bug in one module. Graph had it in a
     * hardcoded URL, IMAP had it by ignoring the argument, and Gmail had it in
     * a mapping whose comment said "anything else is Inbox, not a guessed
     * label" — the right instinct about guessing, with the wrong safe default
     * chosen underneath it.
     *
     * Gmail was reported as honouring the label while the other two were
     * fixed. It honoured the six it knew, and answered the Inbox for
     * everything else.
     */
    public function test_a_folder_gmail_does_not_have_is_refused_not_turned_into_the_inbox(): void
    {
        config([
            'services.google_mail.client_id'     => 'a-google-client-id',
            'services.google_mail.client_secret' => 'a-google-client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*'   => Http::response(['access_token' => 'a-token', 'expires_in' => 3599]),
            'gmail.googleapis.com/*'    => Http::response(['threads' => []]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->mailbox($t, 'gmail_oauth');

        try {
            $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->list($mailbox, label: 'ARCHIVE'));

            $this->fail('Gmail accepted a folder it does not have, so something was shown for it.');
        } catch (CannotReadMailbox $e) {
            $this->assertStringContainsString('ARCHIVE', $e->getMessage());
        }

        // Nothing was fetched: the refusal precedes the request, so no Inbox
        // listing was built and discarded.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'gmail.googleapis.com'));
    }

    /** 11-02: unread was read from /profile, which has no such field. */
    public function test_gmail_unread_is_the_inbox_labels_count(): void
    {
        config([
            'services.google_mail.client_id'     => 'a-google-client-id',
            'services.google_mail.client_secret' => 'a-google-client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*'                => Http::response(['access_token' => 'a-token', 'expires_in' => 3599]),
            'gmail.googleapis.com/*/labels/INBOX*'   => Http::response(['id' => 'INBOX', 'messagesUnread' => 7]),
            'gmail.googleapis.com/*'                 => Http::response(['emailAddress' => 'x', 'messagesTotal' => 9]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->mailbox($t, 'gmail_oauth');

        $this->assertSame(7, $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->unreadCount($mailbox)));
    }

    public function test_the_folders_gmail_does_have_still_work(): void
    {
        config([
            'services.google_mail.client_id'     => 'a-google-client-id',
            'services.google_mail.client_secret' => 'a-google-client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'a-token', 'expires_in' => 3599]),
            'gmail.googleapis.com/*'  => Http::response(['threads' => []]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->mailbox($t, 'gmail_oauth');

        $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->list($mailbox, label: 'SENT'));

        // The control for the refusal above: SENT is not caught by the guard,
        // and it reaches Gmail as SENT rather than as INBOX.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'labelIds=SENT'));
    }

    // ── searching inside a folder, not across the mailbox ────────

    /**
     * Searching in Sent must not return the Inbox as well.
     *
     * Graph's `/me/messages` is every message in the account, so search
     * ignored the folder entirely — a quieter version of the listing bug, and
     * one that reads as "search is a bit odd" rather than as a fault.
     */
    public function test_searching_outlook_stays_inside_the_folder(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(
                ['access_token' => 'an-access-token', 'expires_in' => 3599],
            ),
            'graph.microsoft.com/*' => Http::response(['value' => []]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->mailbox($t, 'microsoft_oauth');

        $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->search($mailbox, 'invoice', label: 'SENT'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/mailFolders/sentitems/messages'));
        Http::assertNotSent(
            fn ($request) => str_contains($request->url(), '/me/messages'),
        );
    }

    /**
     * IMAP asks the server, so the refusal is now about THIS server.
     *
     * The recognising itself is covered by {@see \Tests\Feature\ImapFoldersTest},
     * which needs no socket. What is left to assert here is the wiring: that a
     * label reaches the resolver at all, and that an unconnected mailbox
     * refuses for the connection reason rather than the folder one.
     */
    public function test_an_unconnected_imap_mailbox_refuses_before_it_looks_for_folders(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => 'info@a-1chimney.com',
            'display_name'    => 'Info',
            'provider'        => 'imap',
        ]));

        foreach (['INBOX', 'SENT'] as $label) {
            try {
                $this->inTenant($t['org'], fn () => app(ImapMailboxReader::class)->list($mailbox, label: $label));

                $this->fail("An unconnected mailbox was read for {$label}.");
            } catch (CannotReadMailbox $e) {
                // The connection reason, not a folder one. A mailbox with no
                // password has not got a folder problem, and telling somebody
                // it has no Sent folder would send them looking in the wrong
                // place entirely.
                $this->assertStringContainsString('not connected', $e->getMessage());
                $this->assertStringNotContainsString('folder', $e->getMessage());
            }
        }
    }

    /**
     * Asking for the folder list is the same read gate as reading the mail.
     *
     * A folder list is a small fact about somebody's mail, and small is not
     * none — there is no reason for it to be reachable by somebody who may not
     * open the inbox.
     */
    public function test_the_folder_list_needs_the_same_permission_as_the_mail(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->mailbox($t, 'microsoft_oauth');
        $clerk   = $this->userWithRole($t, 'Clerk', ['email.view']);

        $this->signedInAs($clerk)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/folders")
            ->assertForbidden();
    }
}
