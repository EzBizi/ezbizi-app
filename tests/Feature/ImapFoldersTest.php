<?php

namespace Tests\Feature;

use App\Support\Email\ImapFolders;
use Tests\TestCase;

/**
 * Recognising a folder on somebody else's mail server.
 *
 * ── Why this can be tested at all ────────────────────────────────
 *
 * Because the recognising was pulled out of {@see \App\Support\Email\ImapMailboxReader},
 * which needs a socket, a certificate and a login before it reaches this
 * decision — and on Windows cannot get one. The same argument
 * {@see \App\Support\Email\ReplyComposer} was split out on: the risky half
 * belongs where something can assert about it.
 *
 * ── The fixtures are real servers, not invented ones ─────────────
 *
 * Dovecot with SPECIAL-USE, Dovecot without it, Exchange's `Sent Items`,
 * cPanel's `INBOX.` prefix, and a Portuguese mailbox where every folder name
 * is a word this code has never heard of. That last one is the case that
 * decides whether SPECIAL-USE is worth reading at all — name matching cannot
 * touch it, and it is not exotic.
 */
class ImapFoldersTest extends TestCase
{
    /** Dovecot with RFC 6154, folders named in Portuguese. */
    private const PORTUGUESE = [
        ['name' => 'INBOX', 'attributes' => ['\\HasNoChildren']],
        ['name' => 'Enviados', 'attributes' => ['\\HasNoChildren', '\\Sent']],
        ['name' => 'Rascunhos', 'attributes' => ['\\HasNoChildren', '\\Drafts']],
        ['name' => 'Lixo', 'attributes' => ['\\HasNoChildren', '\\Trash']],
        ['name' => 'Spam', 'attributes' => ['\\HasNoChildren', '\\Junk']],
    ];

    /** Exchange-ish: no SPECIAL-USE, English names with spaces. */
    private const EXCHANGE = [
        ['name' => 'INBOX', 'attributes' => []],
        ['name' => 'Sent Items', 'attributes' => []],
        ['name' => 'Deleted Items', 'attributes' => []],
        ['name' => 'Junk E-Mail', 'attributes' => []],
        ['name' => 'Drafts', 'attributes' => []],
    ];

    /** cPanel-ish: everything under INBOX with a dot separator. */
    private const CPANEL = [
        ['name' => 'INBOX', 'attributes' => []],
        ['name' => 'INBOX.Sent', 'attributes' => []],
        ['name' => 'INBOX.Trash', 'attributes' => []],
        ['name' => 'INBOX.spam', 'attributes' => []],
    ];

    public function test_the_inbox_needs_no_lookup_at_all(): void
    {
        // Named in the protocol itself, so it is the one folder that does not
        // depend on what the server volunteered.
        $this->assertSame('INBOX', ImapFolders::resolve([], 'INBOX'));
        $this->assertSame('INBOX', ImapFolders::resolve([], 'inbox'));
    }

    /**
     * THE CASE THAT JUSTIFIES READING SPECIAL-USE.
     *
     * No amount of name matching finds `Enviados`. The server said `\Sent`,
     * which is the server telling us what the folder is FOR rather than us
     * guessing from a word, and it is right by definition.
     */
    public function test_a_folder_named_in_another_language_is_found_by_its_purpose(): void
    {
        $this->assertSame('Enviados', ImapFolders::resolve(self::PORTUGUESE, 'SENT'));
        $this->assertSame('Rascunhos', ImapFolders::resolve(self::PORTUGUESE, 'DRAFT'));
        $this->assertSame('Lixo', ImapFolders::resolve(self::PORTUGUESE, 'TRASH'));
    }

    public function test_english_names_are_found_where_the_server_says_nothing(): void
    {
        $this->assertSame('Sent Items', ImapFolders::resolve(self::EXCHANGE, 'SENT'));
        $this->assertSame('Deleted Items', ImapFolders::resolve(self::EXCHANGE, 'TRASH'));
        $this->assertSame('Junk E-Mail', ImapFolders::resolve(self::EXCHANGE, 'SPAM'));
        $this->assertSame('Drafts', ImapFolders::resolve(self::EXCHANGE, 'DRAFT'));
    }

    /**
     * A folder under a hierarchy is still that folder.
     *
     * `INBOX.Sent` is what a great many shared hosts produce, and matching the
     * whole string against "sent" would find nothing on any of them.
     */
    public function test_a_folder_below_the_inbox_is_matched_on_its_last_segment(): void
    {
        $this->assertSame('INBOX.Sent', ImapFolders::resolve(self::CPANEL, 'SENT'));
        $this->assertSame('INBOX.Trash', ImapFolders::resolve(self::CPANEL, 'TRASH'));
        $this->assertSame('INBOX.spam', ImapFolders::resolve(self::CPANEL, 'SPAM'));
    }

    /**
     * A folder that is not there is NULL, and null is not the Inbox.
     *
     * The whole reason this returns a nullable rather than a string with a
     * default. Substituting the Inbox is the failure this module keeps
     * finding: it succeeds, it displays, and somebody reads received mail
     * believing it is what they sent.
     */
    public function test_a_folder_the_server_does_not_have_is_null(): void
    {
        $this->assertNull(ImapFolders::resolve(self::CPANEL, 'ARCHIVE'));
        $this->assertNull(ImapFolders::resolve(self::PORTUGUESE, 'ARCHIVE'));

        // The control: this server DOES have Sent, so the null above is about
        // Archive rather than about the fixture being unreadable.
        $this->assertNotNull(ImapFolders::resolve(self::CPANEL, 'SENT'));
    }

    public function test_a_label_this_module_does_not_know_is_null(): void
    {
        $this->assertNull(ImapFolders::resolve(self::EXCHANGE, 'STARRED'));
        $this->assertNull(ImapFolders::resolve(self::EXCHANGE, 'ANYTHING'));
        $this->assertNull(ImapFolders::resolve(self::EXCHANGE, ''));
    }

    /**
     * SPECIAL-USE wins over a name that looks right.
     *
     * A server can have a folder literally called "Sent" that is not the sent
     * folder — an archive of somebody's old mail, say — while marking the real
     * one `\Sent`. The server's own answer is the better one.
     */
    public function test_the_servers_own_answer_beats_a_matching_name(): void
    {
        $folders = [
            ['name' => 'Sent', 'attributes' => []],
            ['name' => 'Correo enviado', 'attributes' => ['\\Sent']],
        ];

        $this->assertSame('Correo enviado', ImapFolders::resolve($folders, 'SENT'));
    }

    public function test_draft_and_drafts_both_work(): void
    {
        // The client sends DRAFT; the API is public enough that somebody will
        // send the plural. A refusal over one letter is a support message.
        $this->assertSame('Drafts', ImapFolders::resolve(self::EXCHANGE, 'DRAFT'));
        $this->assertSame('Drafts', ImapFolders::resolve(self::EXCHANGE, 'DRAFTS'));
    }

    // ── what to offer on screen ──────────────────────────────────

    public function test_available_lists_only_what_this_server_has(): void
    {
        $this->assertSame(
            ['INBOX', 'SENT', 'DRAFT', 'TRASH', 'SPAM'],
            ImapFolders::available(self::PORTUGUESE),
        );

        // No Drafts and no Archive on this one, and they are absent rather
        // than present-and-broken.
        $this->assertSame(
            ['INBOX', 'SENT', 'TRASH', 'SPAM'],
            ImapFolders::available(self::CPANEL),
        );
    }

    /**
     * A server with nothing but an Inbox offers exactly that.
     *
     * Which is what the screen needs to draw no folder list at all, rather
     * than six entries that each resolve to a refusal.
     */
    public function test_a_bare_server_offers_only_the_inbox(): void
    {
        $this->assertSame(['INBOX'], ImapFolders::available([
            ['name' => 'INBOX', 'attributes' => []],
        ]));

        $this->assertSame(['INBOX'], ImapFolders::available([]));
    }

    /**
     * Every label this offers can also be resolved.
     *
     * The two methods share a table, and the failure worth guarding is them
     * drifting: a folder offered on screen that the reader then refuses is a
     * broken promise made after the click instead of before it.
     */
    public function test_everything_offered_can_be_opened(): void
    {
        foreach ([self::PORTUGUESE, self::EXCHANGE, self::CPANEL] as $server) {
            foreach (ImapFolders::available($server) as $label) {
                $this->assertNotNull(
                    ImapFolders::resolve($server, $label),
                    "{$label} is offered but cannot be opened.",
                );
            }
        }
    }
}
