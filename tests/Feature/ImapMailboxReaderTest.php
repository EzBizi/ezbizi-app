<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Support\Email\BodySanitiser;
use App\Support\Email\CannotReachHost;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\ImapMailboxReader;
use App\Support\Email\ImapMessageMapper;
use App\Support\Email\ImapReplySender;
use App\Support\Email\MailboxFault;
use App\Support\Email\RemoteHostGuard;
use App\Support\Email\ReplyComposer;
use Tests\TestCase;

/**
 * The reader's preflight — everything that happens BEFORE a socket exists.
 *
 * ── Why none of these open a connection ──────────────────────────
 *
 * Because the property being tested is that they DO NOT. The ordering in
 * {@see ImapMailboxReader::approveImap} is the whole point: a mailbox pointed at
 * the cloud metadata endpoint must be refused with the sentence about where it
 * points, and it must be refused before anything is dialled. A test that needed
 * a server running would be testing the wrong end.
 *
 * The mailboxes here are built and never saved. The reader touches only
 * `hasCredential()`, `host()`, `smtpHost()`, `credential()`, `address` and the
 * `mail_server` attribute, none of which need a row — so these run without
 * touching the database at all, which is what keeps them honest about ordering.
 */
class ImapMailboxReaderTest extends TestCase
{
    private ImapMailboxReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new ImapMailboxReader(
            new RemoteHostGuard,
            new ImapMessageMapper(new BodySanitiser),
            new ReplyComposer,
            new ImapReplySender,
        );
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function mailbox(array $server, bool $connected = true): Mailbox
    {
        $mailbox = new Mailbox;

        $mailbox->forceFill([
            'address'      => 'info@ezbizi.test',
            'display_name' => 'Info',
            'provider'     => 'imap',
            'mail_server'  => $server,
        ]);

        if ($connected) {
            $mailbox->connect('a-secret');
        }

        return $mailbox;
    }

    // ── the installation's own answer ────────────────────────────

    /**
     * IMAP is READY on this installation, and says so.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THE SENTENCE THIS METHOD USED TO RETURN WAS TRUE AND IS NOT
     *  ANY MORE.
     *
     *  It reported that PHP's `imap` extension was missing — correct
     *  when the transport was the extension, and PHP removed it from
     *  core in 8.4. The transport is now {@see \App\Support\Email\ImapSocket},
     *  built from stream functions that are always compiled in, so the
     *  old sentence would be a working provider telling every customer
     *  it does not work and a connect form that never appears.
     *
     *  Asserted as null AND as not mentioning the extension, because
     *  those are different failures: one is the method not being
     *  updated, the other is somebody reinstating the old text.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_imap_reports_itself_ready_on_this_installation(): void
    {
        $this->assertTrue(extension_loaded('openssl'), 'This installation has no OpenSSL, so IMAP genuinely cannot work.');

        $this->assertNull(
            $this->reader->notReadyBecause(),
            'IMAP reports itself unusable even though the transport is built.',
        );
    }

    /**
     * A configured mailbox is now openable — which is what changed.
     */
    public function test_a_mailbox_with_a_host_and_a_password_is_configured(): void
    {
        $mailbox = $this->mailbox(['imap_host' => 'imap.example.com', 'imap_port' => 993]);

        $this->assertTrue($this->reader->isConfigured($mailbox));
        $this->assertNull($this->reader->unavailableBecause($mailbox));
    }

    /**
     * A mailbox nobody has entered details for still says so, in a sentence.
     */
    public function test_a_mailbox_with_no_password_says_what_is_missing(): void
    {
        $mailbox = $this->mailbox(['imap_host' => 'imap.example.com', 'imap_port' => 993], connected: false);

        $this->assertFalse($this->reader->isConfigured($mailbox));
        $this->assertStringContainsString('have not been entered yet', (string) $this->reader->unavailableBecause($mailbox));
    }

    // ── the guard, still first ───────────────────────────────────

    /**
     * A mailbox pointed at the cloud metadata endpoint is refused, by name.
     *
     * `169.254.169.254` answers over plain HTTP with no authentication and hands
     * back the instance's IAM role credentials. The refusal happens in
     * {@see ImapMailboxReader::approveImap}, before {@see \App\Support\Email\ImapSocket}
     * is reached, so nothing is ever dialled — and the sentence names what it
     * points at rather than complaining about a connection that was never made.
     */
    public function test_a_mailbox_pointed_at_the_metadata_endpoint_is_refused_before_anything_is_dialled(): void
    {
        $mailbox = $this->mailbox(['imap_host' => '169.254.169.254', 'imap_port' => 993]);

        try {
            $this->reader->list($mailbox);

            $this->fail('A mailbox pointed at the EC2 metadata endpoint was allowed to connect.');
        } catch (CannotReachHost $e) {
            $this->assertStringContainsString('metadata', $e->getMessage());
            $this->assertSame(422, $e->status());
        }
    }

    /** Loopback is somebody trying to reach services on our own box. */
    public function test_a_mailbox_pointed_at_this_server_is_refused(): void
    {
        $mailbox = $this->mailbox(['imap_host' => '127.0.0.1', 'imap_port' => 993]);

        $this->expectException(CannotReachHost::class);

        $this->reader->search($mailbox, 'anything');
    }

    /**
     * The guard runs on OPEN too, not only on the list.
     *
     * Worth asserting per method rather than once: a guard called from three of
     * four read paths is a guard with a hole in it, and the hole is always in
     * the path somebody added later.
     */
    public function test_the_guard_runs_on_opening_a_single_message_too(): void
    {
        $mailbox = $this->mailbox(['imap_host' => '169.254.169.254', 'imap_port' => 993]);

        $this->expectException(CannotReachHost::class);

        $this->reader->open($mailbox, '1');
    }

    /**
     * Replying is judged on the SMTP port list, where 25 is deliberately absent.
     *
     * Port 25 is the server-to-server port and where an open relay lives; a
     * submission from an authenticated client never uses it.
     */
    public function test_a_reply_through_port_25_is_refused(): void
    {
        $mailbox = $this->mailbox([
            'imap_host' => 'imap.example.com', 'imap_port' => 993,
            'smtp_host' => 'mail.example.com', 'smtp_port' => 25,
        ]);

        try {
            $this->reader->reply($mailbox, '1', 'Thanks.');

            $this->fail('A reply was allowed out through port 25.');
        } catch (CannotReachHost $e) {
            $this->assertStringContainsString('25', $e->getMessage());
            $this->assertStringContainsString('587', $e->getMessage());
        }
    }

    /** And the SMTP host itself is judged by the same address rules. */
    public function test_a_reply_through_the_metadata_endpoint_is_refused(): void
    {
        $mailbox = $this->mailbox([
            'imap_host' => 'imap.example.com', 'imap_port' => 993,
            'smtp_host' => '169.254.169.254', 'smtp_port' => 587,
        ]);

        $this->expectException(CannotReachHost::class);

        $this->reader->reply($mailbox, '1', 'Thanks.');
    }

    // ── what arrives from outside ────────────────────────────────

    /**
     * A message id that is not a number never reaches an IMAP command.
     *
     * `(int) "5 OR 1:*"` is 5, which is safe by luck. Refusing anything that is
     * not digits is safe by construction, and this value arrives in a URL.
     */
    public function test_a_message_id_that_is_not_a_number_is_refused(): void
    {
        $mailbox = $this->mailbox(['imap_host' => 'imap.example.com', 'imap_port' => 993]);

        foreach (['1 OR 1:*', '*', '1:99999', 'abc', '0', '-3', ''] as $bad) {
            try {
                $this->reader->open($mailbox, $bad);

                $this->fail(sprintf('The message id "%s" was accepted.', $bad));
            } catch (CannotReadMailbox $e) {
                $this->assertSame(MailboxFault::ProviderRefused, $e->fault);
            }
        }
    }

    /**
     * An unconfigured mailbox THROWS rather than returning an empty page.
     *
     * The guarantee {@see CannotReadMailbox} exists for: a blank message list in
     * this module always means an empty mailbox, because the other case cannot
     * reach the screen.
     */
    public function test_an_unconfigured_mailbox_throws_rather_than_returning_nothing(): void
    {
        $mailbox = $this->mailbox(['imap_host' => 'imap.example.com', 'imap_port' => 993], connected: false);

        try {
            $this->reader->list($mailbox);

            $this->fail('An unconfigured mailbox returned a page instead of throwing.');
        } catch (CannotReadMailbox $e) {
            $this->assertSame(MailboxFault::NotConnected, $e->fault);
            $this->assertSame(409, $e->status());
        }
    }

    /** A mailbox with no host at all is the same case, not a crash. */
    public function test_a_mailbox_with_no_host_throws_not_connected(): void
    {
        $mailbox = $this->mailbox([]);

        try {
            $this->reader->list($mailbox);

            $this->fail('A mailbox with no mail server returned a page instead of throwing.');
        } catch (CannotReadMailbox $e) {
            $this->assertSame(MailboxFault::NotConnected, $e->fault);
        }
    }

    /**
     * A port a mail server does not answer on is refused with a sentence.
     *
     * The host field would otherwise be a port scanner with our source address
     * on it.
     */
    public function test_an_arbitrary_port_is_refused(): void
    {
        $mailbox = $this->mailbox(['imap_host' => 'imap.example.com', 'imap_port' => 22]);

        try {
            $this->reader->list($mailbox);

            $this->fail('A mailbox pointed at port 22 was allowed.');
        } catch (CannotReachHost $e) {
            // R8: it names the ports that do work rather than a rule number.
            $this->assertStringContainsString('993', $e->getMessage());
            $this->assertStringContainsString('143', $e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  The watcher's position — the arithmetic, with no socket
    // ═════════════════════════════════════════════════════════════
    //
    //  Reached by reflection, and that is the honest arrangement rather
    //  than a shortcut. The socket half is covered by ImapTransportTest
    //  against a real server, and the reader cannot be driven end to end
    //  here because RemoteHostGuard refuses to dial a loopback address —
    //  correctly, and it is not going to be weakened so a test can run.
    //  What is left is two pieces of arithmetic that decide whether a
    //  quiet mailbox stays quiet, and both are worth pinning exactly.

    /** @param mixed ...$arguments */
    private function invoke(string $method, ...$arguments): mixed
    {
        $reflected = new \ReflectionMethod($this->reader, $method);

        return $reflected->invoke($this->reader, ...$arguments);
    }

    /**
     * THE `n:*` TRAP — the one that would re-announce the same message for ever.
     *
     * An IMAP range is normalised, so `100:50` means `50:100`, and `*` is the
     * highest UID present. On a mailbox whose newest message is UID 50, asking
     * for `UID SEARCH UID 51:*` is asking for `50:51` — and the server honestly
     * answers 50, a message that arrived before the mark and has not arrived at
     * all since.
     *
     * Left unfiltered, a mailbox that nobody writes to announces its newest
     * message every five minutes until somebody switches the rule off. The
     * mark being ABOVE every UID is the ordinary state of a quiet inbox, which
     * is why the case is set up that way here — a test that only ever ran with
     * new mail waiting would pass with the filter deleted.
     */
    public function test_a_search_that_answers_below_the_mark_yields_nothing(): void
    {
        $this->assertSame([], $this->invoke('arrivedAtOrAfter', [50], 51));

        // And the boundary is AT the mark, not above it: the frontier is "the
        // lowest UID not yet seen", so a message with exactly that UID is new.
        $this->assertSame([51], $this->invoke('arrivedAtOrAfter', [50, 51], 51));
    }

    /** What did arrive comes back oldest first, whatever order it came in. */
    public function test_arrivals_are_ordered_oldest_first(): void
    {
        $this->assertSame([10, 11, 12], $this->invoke('arrivedAtOrAfter', [12, 10, 11], 10));
    }

    /**
     * A mark from a DIFFERENT incarnation of the mailbox is refused.
     *
     * UIDVALIDITY changes when a mailbox is deleted and recreated, and the
     * server then issues UIDs from 1 again. A mark of `4242:4000` read against
     * a mailbox now claiming 9999 names messages that no longer exist: honoured,
     * it would either announce nothing for ever or announce the whole new
     * mailbox. Null is the answer, and the reader turns that into a fresh
     * position and silence.
     */
    public function test_a_mark_from_another_incarnation_of_the_mailbox_is_refused(): void
    {
        $this->assertSame(4000, $this->invoke('frontierFrom', '4242:4000', 4242));

        $this->assertNull($this->invoke('frontierFrom', '4242:4000', 9999));
        $this->assertNull($this->invoke('frontierFrom', 'not-a-mark', 4242));
        $this->assertNull($this->invoke('frontierFrom', '4242:0', 4242));
    }
}
