<?php

namespace App\Support\Email;

use App\Models\Mailbox;

/**
 * A generic IMAP mailbox — the provider that exists because not everybody is on
 * Google, and the one that asks somebody to hand us a password.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THIS NOW CONNECTS. IT USED TO REFUSE.
 *
 *  What it said before was true when it was written: speaking IMAP
 *  needed PHP's `imap` extension, which was REMOVED FROM CORE in 8.4
 *  and this box runs 8.4.24, so every read threw
 *  {@see CannotReadMailbox::notBuiltHere} and {@see notReadyBecause}
 *  said so on the settings screen.
 *
 *  Installing the PECL build was never the answer, and the reason is
 *  the reason this whole transport is hand-written: `imap_open()`
 *  takes a HOSTNAME and resolves it internally. The name has already
 *  been resolved and judged by {@see RemoteHostGuard} at that point,
 *  so handing the name to the extension is a SECOND lookup — a fresh
 *  DNS answer, at a moment of the attacker's choosing, going wherever
 *  it now points. That is DNS rebinding, it defeats the guard
 *  entirely, and on this instance it points at 169.254.169.254. The
 *  extension is available from sury; it was checked, and it is not
 *  used.
 *
 *  So the socket is opened here instead — see {@see ImapSocket}, which
 *  connects to {@see ApprovedHost::$address} and verifies the
 *  certificate against {@see ApprovedHost::$host}. Two variables, two
 *  calls, and the guard's third rule survives contact with the wire.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── The guard runs BEFORE anything else, still ───────────────────
 *
 * {@see approveImap} is the old `assertUsable` with its ordering intact and one
 * thing changed: it RETURNS the approval rather than discarding it. That is the
 * half of rule 3 that changes the calling code — the connection below is opened
 * to the address this returns, and there is no path in this class that passes a
 * hostname to anything that could resolve it again.
 *
 * ── A connection per call, closed in a `finally` ─────────────────
 *
 * Not pooled. The module reads LIVE — {@see MailboxReader} says so — and a
 * pooled IMAP connection is a stateful thing holding a selected folder and a
 * signed-in session across requests that belong to different people. The cost of
 * opening one is a TLS handshake; the cost of getting a pool wrong is one
 * tenant's request answered from another's session.
 */
final class ImapMailboxReader implements MailboxReader
{
    /**
     * The most messages one page may ask for.
     *
     * A page size is a number that arrives from outside, so it is clamped rather
     * than trusted: `?limit=100000` is a request for a hundred thousand
     * ENVELOPEs in one response, which is a way of making our own server do the
     * work of exhausting itself.
     */
    private const MAX_PAGE = 100;

    public function __construct(
        private readonly RemoteHostGuard $guard,
        private readonly ImapMessageMapper $mapper,
        private readonly ReplyComposer $composer,
        private readonly ImapReplySender $sender,
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
     * The installation's answer — and it is now null on any normal PHP.
     *
     * ── What this used to say, and why it no longer says it ──────
     *
     * It used to report that the `imap` extension was missing, because that was
     * the transport. It is not the transport any more: {@see ImapSocket} is
     * built out of PHP's own stream functions, which are always compiled in.
     * Leaving the old sentence here would be the failure mode this method exists
     * to prevent, inverted — a provider that works telling everybody it does
     * not, and a connect form that never appears.
     *
     * ── What is still checked, and it is not a formality ─────────
     *
     * OpenSSL. Every connection this provider makes is encrypted and
     * {@see ImapSocket::modeFor} refuses to build one that is not, so a PHP
     * without `ext-openssl` genuinely cannot open an IMAP mailbox here — and it
     * would fail at the TLS handshake, with a sentence about certificates, which
     * would send somebody to argue with their mail provider about a fault at our
     * end. Said plainly here instead.
     */
    public function notReadyBecause(): ?string
    {
        if (extension_loaded('openssl')) {
            return null;
        }

        return 'EzBizi cannot open IMAP mailboxes on this installation because the PHP it is '
            .'running on was built without OpenSSL, and EzBizi will not connect to a mail server '
            .'without encryption. Nothing is wrong with this address or its password. The missing '
            .'piece is at EzBizi\'s end.';
    }

    // ── reading ──────────────────────────────────────────────────

    /**
     * A page of headers, newest first.
     *
     * ── The cursor is a sequence position, as designed ───────────
     *
     * {@see MessagePage} already said an IMAP cursor would be "an IMAP sequence
     * position", and it is — carried with the mailbox's UIDVALIDITY, which is
     * the server's own answer to "is this the same mailbox you were reading?".
     * A cursor from before a mailbox was deleted and recreated names positions
     * in a mailbox that no longer exists, and following it would quietly return
     * the wrong messages. It is refused instead, and the reader starts again
     * from the top.
     */
    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25): MessagePage
    {
        $limit = max(1, min($limit, self::MAX_PAGE));

        return $this->withConnection($mailbox, function (ImapConnection $imap) use ($cursor, $limit) {
            $box = $imap->examineInbox();

            if ($box['exists'] === 0) {
                // A genuinely empty inbox, and the only place this module is
                // allowed to say so. Everything that means "we could not look"
                // throws — see CannotReadMailbox.
                return new MessagePage([], null, 0);
            }

            $top = $cursor === null
                ? $box['exists']
                : min($this->positionFrom($cursor, $box['uidvalidity']), $box['exists']);

            if ($top < 1) {
                return new MessagePage([], null, $box['exists']);
            }

            $from = max(1, $top - $limit + 1);

            $fetched = $imap->fetchHeaders($from.':'.$top, byUid: false);

            return new MessagePage(
                messages: $this->summaries($imap, $fetched),
                nextCursor: $from > 1 ? $box['uidvalidity'].':'.($from - 1) : null,
                approximateTotal: $box['exists'],
            );
        });
    }

    /**
     * The server's own search, over the whole mailbox.
     *
     * ── Why the criteria are built and not passed through ────────
     *
     * Because a search string is typed by a person and an IMAP command is a
     * CRLF-terminated line: forwarding what somebody typed is the injection
     * {@see ImapConnection::quoted} exists to stop, one layer up. So the query
     * becomes the VALUE of a `TEXT` criterion and never any part of the command
     * around it.
     */
    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25): MessagePage
    {
        $limit = max(1, min($limit, self::MAX_PAGE));

        return $this->withConnection($mailbox, function (ImapConnection $imap) use ($query, $cursor, $limit) {
            $box = $imap->examineInbox();

            $term = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], trim($query)).'"';

            try {
                // CHARSET UTF-8 so that a search for a word with an accent in it
                // works. It is optional in the protocol, so a server that has
                // never heard of it answers BAD rather than searching badly.
                $uids = $imap->searchUids('CHARSET UTF-8 TEXT '.$term);
            } catch (ImapFailure) {
                $uids = $imap->searchUids('TEXT '.$term);
            }

            if ($uids === []) {
                return new MessagePage([], null, 0);
            }

            // Newest first, which for UIDs means highest first — a UID is
            // assigned in arrival order and never reused.
            rsort($uids);

            if ($cursor !== null) {
                $below = $this->positionFrom($cursor, $box['uidvalidity']);
                $uids  = array_values(array_filter($uids, fn (int $uid) => $uid <= $below));
            }

            $total = count($uids);
            $page  = array_slice($uids, 0, $limit);

            if ($page === []) {
                return new MessagePage([], null, $total);
            }

            $fetched = $imap->fetchHeaders(implode(',', $page), byUid: true);

            return new MessagePage(
                messages: $this->summaries($imap, $fetched),
                nextCursor: $total > count($page) ? $box['uidvalidity'].':'.(min($page) - 1) : null,
                approximateTotal: $total,
            );
        });
    }

    /**
     * One message, opened — body already through {@see BodySanitiser}.
     */
    public function open(Mailbox $mailbox, string $messageId): MessageDetail
    {
        $uid = $this->uidFrom($messageId, $mailbox);

        return $this->withConnection($mailbox, function (ImapConnection $imap) use ($uid, $mailbox) {
            $row = $imap->fetchHeaders((string) $uid, byUid: true)[$uid] ?? null;

            if ($row === null) {
                throw CannotReadMailbox::providerRefused(
                    $mailbox,
                    'That message is no longer in the mailbox. It may have been moved or deleted.',
                );
            }

            $part = $this->mapper->textPart($row['bodystructure'] ?? null);

            /*
             * ONE part, chosen from the structure the server already described —
             * not the whole message. See {@see ImapMessageMapper}: downloading
             * the lot to find two lines of text is what a MIME parser would have
             * forced, and it is how a 20 MB attachment becomes a 20 MB read for
             * somebody clicking a subject line.
             */
            $body = $part === null ? '' : $imap->bodyPart($uid, $part['part']);

            return $this->mapper->detail(
                $this->mapper->summary($uid, $row),
                $row,
                $body,
                $part,
            );
        });
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
     *
     * And what comes back from `approve()` is used: {@see ImapReplySender} opens
     * its socket to that address, so the reply path is pinned exactly as the read
     * path is rather than merely being checked by the same rules.
     */
    public function reply(Mailbox $mailbox, string $messageId, string $body): void
    {
        $uid = $this->uidFrom($messageId, $mailbox);

        $smtp = $mailbox->smtpHost();

        if ($smtp === null) {
            throw CannotReadMailbox::notConnected($mailbox->address, MailboxProvider::Imap);
        }

        // Judged BEFORE the mailbox is opened, so that a bad sending server is
        // refused with the sentence about that server rather than after a
        // pointless round trip to the reading one.
        $approved = $this->guard->approve($smtp['host'], $smtp['port'], RemoteHostGuard::SMTP_PORTS);

        [$summary, $headers] = $this->withConnection($mailbox, function (ImapConnection $imap) use ($uid, $mailbox) {
            $row = $imap->fetchHeaders((string) $uid, byUid: true)[$uid] ?? null;

            if ($row === null) {
                throw CannotReadMailbox::providerRefused(
                    $mailbox,
                    'That message is no longer in the mailbox, so there is nothing to reply to.',
                );
            }

            /*
             * Message-ID and References, so the answer lands under the question
             * in the customer's own mail program. Fetched as a HEADER.FIELDS
             * part — two lines rather than the whole message.
             */
            $raw = $imap->bodyParts((string) $uid, 'HEADER.FIELDS (MESSAGE-ID REFERENCES)', 4096)[$uid] ?? '';

            return [$this->mapper->summary($uid, $row), $this->threadingHeaders($raw)];
        });

        $settings = $this->settings($mailbox);

        $this->translate($mailbox, fn () => $this->sender->send(
            approved: $approved,
            encryption: $smtp['encryption'],
            username: $settings['smtp_username'] !== '' ? $settings['smtp_username'] : $mailbox->address,
            password: (string) $mailbox->credential(),
            from: $mailbox->address,
            to: $summary->fromAddress,
            message: $this->composer->compose($mailbox, $summary, $headers, $body),
        ));
    }

    // ── the preflight, which was already real ────────────────────

    /**
     * The guard, and the approval it produced.
     *
     * ── Approved at READ time, not at save time ──────────────────
     *
     * Checking once when the mailbox was saved is the mistake this ordering
     * avoids: DNS is not a property of the record, and a hostname that resolved
     * to a public address on Tuesday can resolve to 169.254.169.254 on Wednesday
     * without anybody editing anything. The guard has to run on the path that
     * connects, every time — so it runs here, and what it returns is what gets
     * dialled.
     *
     * @throws CannotReadMailbox
     * @throws CannotReachHost
     */
    private function approveImap(Mailbox $mailbox): ApprovedHost
    {
        if (! $mailbox->hasCredential()) {
            throw CannotReadMailbox::notConnected($mailbox->address, MailboxProvider::Imap);
        }

        $host = $mailbox->host();

        if ($host === null) {
            throw CannotReadMailbox::notConnected($mailbox->address, MailboxProvider::Imap);
        }

        return $this->guard->approve($host['host'], $host['port'], RemoteHostGuard::IMAP_PORTS);
    }

    /**
     * Open, sign in, do the work, and always hang up.
     *
     * @template T
     *
     * @param  callable(ImapConnection): T  $work
     * @return T
     */
    private function withConnection(Mailbox $mailbox, callable $work): mixed
    {
        $approved = $this->approveImap($mailbox);
        $host     = $mailbox->host();
        $settings = $this->settings($mailbox);

        return $this->translate($mailbox, function () use ($approved, $host, $settings, $mailbox, $work) {
            $imap = ImapConnection::open($approved, (string) $host['encryption']);

            try {
                $imap->login(
                    // The address is the fallback because for most mail services
                    // the user name IS the address, and making somebody type it
                    // twice is a field they can get wrong.
                    $settings['imap_username'] !== '' ? $settings['imap_username'] : $mailbox->address,
                    (string) $mailbox->credential(),
                );

                return $work($imap);
            } finally {
                $imap->logout();
            }
        });
    }

    /**
     * Turn a transport failure into the module's own vocabulary.
     *
     * ── One `match`, so the four cases cannot drift ──────────────
     *
     * {@see CannotReadMailbox} makes the argument at length: the cause decides
     * the HTTP status, whether the mailbox row is marked broken, and whether
     * somebody is offered a "Connect again" button. Doing that mapping at each
     * call site is how one failure comes to mean three different things in three
     * places, so it happens exactly here.
     *
     * `UNREACHABLE` is the one that is deliberately NOT a CannotReadMailbox.
     * A certificate that does not match, or a server that will only speak in the
     * clear, is a fact about the VALUE in the host field — which is what
     * {@see CannotReachHost} is for, and why it answers 422 rather than 503.
     * Reporting those as "the mail service is not answering, try again later"
     * would send somebody to wait for a fault that will never clear.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function translate(Mailbox $mailbox, callable $work): mixed
    {
        try {
            return $work();
        } catch (ImapFailure $e) {
            throw match ($e->kind) {
                ImapFailure::UNREACHABLE => new CannotReachHost($e->getMessage()),
                ImapFailure::TIMEOUT     => CannotReadMailbox::hostNotAnswering($mailbox, $e->getMessage()),
                ImapFailure::REJECTED    => CannotReadMailbox::credentialRejected($mailbox),
                default                  => CannotReadMailbox::providerFaulty($mailbox, $e->getMessage()),
            };
        }
    }

    /**
     * Headers for a page, with a preview line each.
     *
     * @param  array<int, array<string, mixed>>  $fetched
     * @return MessageSummary[]
     */
    private function summaries(ImapConnection $imap, array $fetched): array
    {
        $snippets = $this->snippets($imap, $fetched);

        $summaries = [];

        foreach ($fetched as $uid => $row) {
            $summaries[] = $this->mapper->summary($uid, $row, $snippets[$uid] ?? '');
        }

        // Newest first. The server answers a sequence range in ascending order,
        // and a mail list that put the oldest message at the top would be read
        // as broken by everybody who has ever used email.
        usort($summaries, fn (MessageSummary $a, MessageSummary $b) => (int) $b->id <=> (int) $a->id);

        return $summaries;
    }

    /**
     * A preview line per message, in as few commands as possible.
     *
     * ── Grouped by part number, and that is the whole trick ──────
     *
     * IMAP fetches one named part from many messages in a single command, but
     * only if they share the part's NAME. Most messages in a mailbox have the
     * same shape — a plain-text single part is "1", the plain half of a
     * text+HTML pair is "1.1" — so grouping by that number collapses a page of
     * twenty previews into one or two round trips instead of twenty.
     *
     * @param  array<int, array<string, mixed>>  $fetched
     * @return array<int, string>
     */
    private function snippets(ImapConnection $imap, array $fetched): array
    {
        $grouped = [];

        foreach ($fetched as $uid => $row) {
            $part = $this->mapper->textPart($row['bodystructure'] ?? null);

            if ($part !== null) {
                $grouped[$part['part']][$uid] = $part;
            }
        }

        $snippets = [];

        foreach ($grouped as $part => $messages) {
            $raw = $imap->bodyParts(
                implode(',', array_keys($messages)),
                (string) $part,
                ImapMessageMapper::SNIPPET_FETCH_BYTES,
            );

            foreach ($messages as $uid => $shape) {
                $snippets[$uid] = $this->mapper->snippet($raw[$uid] ?? '', $shape);
            }
        }

        return $snippets;
    }

    /**
     * `Message-ID:` and `References:` out of a raw header block.
     *
     * Lower-cased keys, because that is the shape {@see ReplyComposer::compose}
     * documents it wants — and it wants it lower-cased precisely so that a
     * server writing `MESSAGE-ID` and one writing `Message-Id` produce the same
     * threading rather than one of them silently producing none.
     *
     * @return array<string, string>
     */
    private function threadingHeaders(string $raw): array
    {
        $headers = [];

        // Unfolded first: a long References header is wrapped onto continuation
        // lines that begin with whitespace, and reading it line by line would
        // take the first reference and drop the rest of the conversation.
        $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $raw);

        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m) === 1) {
                $headers[strtolower($m[1])] = trim($m[2]);
            }
        }

        return $headers;
    }

    /**
     * The mail-server settings that {@see Mailbox::host} does not carry.
     *
     * `host()` deliberately returns host, port and encryption together so that
     * "a caller cannot use the host without the port". The user names are a
     * separate concern — they are not part of deciding where to connect — so
     * they are read here rather than widening that array and giving a future
     * caller a hostname with a credential attached to it.
     *
     * @return array{imap_username: string, smtp_username: string}
     */
    private function settings(Mailbox $mailbox): array
    {
        $stored = $mailbox->getAttribute('mail_server') ?? [];

        return [
            'imap_username' => trim((string) ($stored['imap_username'] ?? '')),
            'smtp_username' => trim((string) ($stored['smtp_username'] ?? '')),
        ];
    }

    /**
     * A message id from the client, back into the UID it is.
     *
     * Validated rather than cast, because this string arrives in a URL and is
     * about to become part of an IMAP command. `(int)` on "5 OR 1:*" is 5, which
     * is safe by luck; refusing anything that is not digits is safe by
     * construction.
     */
    private function uidFrom(string $messageId, Mailbox $mailbox): int
    {
        if (ctype_digit($messageId) && (int) $messageId > 0) {
            return (int) $messageId;
        }

        throw CannotReadMailbox::providerRefused($mailbox, 'That is not a message in this mailbox.');
    }

    /**
     * A cursor, checked against the mailbox it claims to belong to.
     *
     * Returns 0 — "there is nothing after this" — for a cursor that does not
     * parse or that names a different incarnation of the mailbox. Refused
     * quietly rather than thrown, because a stale cursor is a person clicking an
     * old "next page" link, not an error: the honest answer is an empty page,
     * and an exception would put a fault on screen for something nobody did
     * wrong.
     */
    private function positionFrom(string $cursor, int $uidvalidity): int
    {
        if (preg_match('/^(\d+):(\d+)$/', $cursor, $m) !== 1) {
            return 0;
        }

        return (int) $m[1] === $uidvalidity ? (int) $m[2] : 0;
    }
}
