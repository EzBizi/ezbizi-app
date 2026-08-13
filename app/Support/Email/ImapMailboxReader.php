<?php

namespace App\Support\Email;

use App\Models\Mailbox;

/**
 * A generic IMAP mailbox — the provider that exists because not everybody is on
 * Google, and the one that asks somebody to hand us a password.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHAT IS BUILT, AND WHAT REFUSES.
 *
 *  BUILT AND REAL: the preflight. Whether a credential exists,
 *  whether the host and port are ones this server may dial at all,
 *  and — the part that matters — {@see RemoteHostGuard}, which
 *  resolves the tenant's hostname, judges every address it resolves
 *  to, and hands back the ADDRESS so the connection cannot be made to
 *  a name that has since changed its mind. That guard runs on both
 *  the IMAP side and the SMTP side of a reply, and it is exercised by
 *  tests: it is not waiting on anything.
 *
 *  REFUSES, HONESTLY: the protocol itself. Speaking IMAP needs either
 *  PHP's `imap` extension — which was REMOVED FROM CORE in PHP 8.4,
 *  the version this runs on, and now lives in PECL — or a pure-PHP
 *  IMAP client, which is a dependency nobody has chosen. Neither is
 *  present, so every read below throws
 *  {@see CannotReadMailbox::notBuiltHere}, which says in a sentence
 *  that the missing piece is at EzBizi's end and that nothing is
 *  wrong with the address or the password.
 *
 *  IT DOES NOT RETURN AN EMPTY INBOX, and that is the whole reason
 *  this class is written out rather than left as a stub returning
 *  `[]`. See CannotReadMailbox: a blank list that means "we cannot
 *  look" is indistinguishable on screen from one that means "nobody
 *  wrote to you", and only one of the two is a reason to ring
 *  somebody.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── The guard runs BEFORE the refusal, deliberately ──────────────
 *
 * {@see assertUsable} checks the host before it reports that the protocol is
 * missing, so a mailbox pointed at `169.254.169.254` is refused with the SSRF
 * sentence rather than with "IMAP is not built here". That ordering is what
 * makes the guard testable today and what makes the audit trail right: the
 * interesting fact about that attempt is where it was pointed, not which
 * extension is absent.
 */
final class ImapMailboxReader implements MailboxReader
{
    public function __construct(
        private readonly RemoteHostGuard $guard,
    ) {}

    // ── is there anything behind this at all ─────────────────────

    public function isConfigured(Mailbox $mailbox): bool
    {
        return $this->unavailableBecause($mailbox) === null;
    }

    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        if (($notReady = $this->notReadyBecause()) !== null) {
            return $notReady;
        }

        if (! $mailbox->hasCredential() || $mailbox->host() === null) {
            return 'The mail server details and the password for this address have not been '
                .'entered yet, so EzBizi cannot open it.';
        }

        return null;
    }

    /**
     * Can this PHP speak IMAP at all?
     *
     * `imap_open` rather than `extension_loaded('imap')`, because the PECL build
     * and any polyfill both answer the function check and only one of them
     * answers the extension name. What matters is whether the call exists.
     */
    /**
     * The installation's answer, said once and asked from two places.
     *
     * The sentence used to end "If this address is on Google, connecting it
     * with Google works today and is the better option anyway." It does not
     * work today: services.google_mail is unset on this installation and the
     * sign-in flow that would fill it is not built, so that line sent somebody
     * from one dead end straight to another. Removed rather than softened -- a
     * suggestion that cannot be acted on is worse than no suggestion.
     */
    public function notReadyBecause(): ?string
    {
        if ($this->protocolIsAvailable()) {
            return null;
        }

        return 'EzBizi cannot speak to IMAP mail servers on this installation yet - the '
            .'PHP extension it needs is not present. Nothing is wrong with this address '
            .'or its password, and entering one here would not connect anything. The '
            .'missing piece is at EzBizi\'s end.';
    }

    private function protocolIsAvailable(): bool
    {
        return function_exists('imap_open');
    }

    // ── reading ──────────────────────────────────────────────────

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25): MessagePage
    {
        $this->assertUsable($mailbox);

        throw $this->notBuilt();
    }

    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25): MessagePage
    {
        $this->assertUsable($mailbox);

        throw $this->notBuilt();
    }

    public function open(Mailbox $mailbox, string $messageId): MessageDetail
    {
        $this->assertUsable($mailbox);

        throw $this->notBuilt();
    }

    /**
     * A reply, sent through the tenant's own SMTP server.
     *
     * ── The identical guard, on the other port list ──────────────
     *
     * The SMTP host is a second field the tenant types, and it is the same
     * threat as the IMAP one: our server dialling somewhere they chose. It is
     * therefore approved by the SAME class with the same address ranges, and
     * only the port list differs — {@see RemoteHostGuard::SMTP_PORTS} is 587 and
     * 465, the submission ports.
     *
     * Port 25 is deliberately absent from that list. It is the server-to-server
     * port, it is where an open relay lives, and a mail SUBMISSION from an
     * authenticated client never uses it. Allowing it would let a mailbox be
     * pointed at any mail server in the world on the one port that talks to
     * anything.
     */
    public function reply(Mailbox $mailbox, string $messageId, string $body): void
    {
        $this->assertUsable($mailbox);

        // The reply host is judged even though the send below cannot happen, so
        // that a bad SMTP host is refused with the sentence about the host
        // rather than the sentence about the extension.
        $smtp = $mailbox->smtpHost();

        if ($smtp !== null) {
            $this->guard->approve($smtp['host'], $smtp['port'], RemoteHostGuard::SMTP_PORTS);
        }

        throw $this->notBuilt();
    }

    // ── the preflight, which is entirely real ────────────────────

    /**
     * @throws CannotReadMailbox
     * @throws CannotReachHost
     */
    private function assertUsable(Mailbox $mailbox): void
    {
        if (! $mailbox->hasCredential()) {
            throw CannotReadMailbox::notConnected($mailbox->address, MailboxProvider::Imap);
        }

        $host = $mailbox->host();

        if ($host === null) {
            throw CannotReadMailbox::notConnected($mailbox->address, MailboxProvider::Imap);
        }

        /*
         * Approved here, at read time, and not only when the mailbox was saved.
         *
         * Checking once on save is the mistake this ordering avoids: DNS is not
         * a property of the record, and a hostname that resolved to a public
         * address on Tuesday can resolve to 169.254.169.254 on Wednesday
         * without anybody editing anything. The guard has to run on the path
         * that connects, every time.
         */
        $this->guard->approve($host['host'], $host['port'], RemoteHostGuard::IMAP_PORTS);
    }

    private function notBuilt(): CannotReadMailbox
    {
        return CannotReadMailbox::notBuiltHere(
            MailboxProvider::Imap,
            'Speaking IMAP needs a mail library that is not installed here — PHP removed its '
            .'built-in one in the version EzBizi runs on. Everything else about this mailbox '
            .'is set up and will work the moment that is added.',
        );
    }
}
