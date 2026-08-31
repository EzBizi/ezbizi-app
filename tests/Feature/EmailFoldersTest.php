<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Support\Email\CannotReadMailbox;
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
 *  Two of the three readers used to accept `$label` and ignore it.
 *  Asking an Outlook inbox for Sent returned RECEIVED mail, with a
 *  200 and nothing anywhere saying otherwise — somebody checking
 *  what the business had sent a customer would have been reading
 *  what the customer sent them.
 *
 *  It was not reachable from the screen, because the client only
 *  offers folders on Gmail. That makes it latent, not harmless: the
 *  endpoint takes the parameter from anyone, and the day the client
 *  widened its folder list the lie would have shipped with it.
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
