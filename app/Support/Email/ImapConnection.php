<?php

namespace App\Support\Email;

/**
 * IMAP, in the subset this application actually uses.
 *
 * ── Why this is written out rather than depended on ──────────────
 *
 * Two pure-PHP IMAP clients were evaluated against the one question that
 * decides it — *can it connect to an address I choose while verifying the
 * certificate against a name I choose?* — and against the bounded-read
 * requirement. Both were probed, not read about:
 *
 *   webklex/php-imap 6.2.0 — CAN be pinned. `ssl_options` survives its
 *   `array_replace`, so `peer_name` reaches the context and a probe confirmed
 *   the three cases: connect to 127.0.0.1 claiming the certificate's real name
 *   succeeds, claiming a different name is refused, claiming nothing is refused.
 *   It fails on reads. `nextLine()` is `fread($stream, 1)` in an unbounded loop,
 *   and `decodeLine()` answers `{2147483647}` with
 *   `while (strlen($token) < $chars) { $token .= $this->nextLine(); }`. A probe
 *   against a server sending that literal exhausted a 256 MB limit in 7.1
 *   seconds inside that loop.
 *
 *   directorytree/imapengine v1.25.5 — CANNOT be pinned at all. `peer_name`
 *   appears nowhere in the package; `getDefaultSocketOptions()` builds the
 *   context from scratch with only `verify_peer`/`verify_peer_name` booleans,
 *   and there is no merge point for caller-supplied stream options. Its
 *   tokenizer reads the announced literal length whole, with no cap, exactly as
 *   webklex does.
 *
 * Everything else on Packagist that speaks IMAP — php-imap/php-imap,
 * ddeboer/imap, tedivm/fetch — requires the `imap` extension, which PHP removed
 * from core in 8.4 and this box runs 8.4.24.
 *
 * So webklex would have had to be subclassed to override `nextLine()` AND
 * `decodeLine()` — which is the response parser, which is most of the file
 * below — while still carrying twenty transitive packages and a pin resting on
 * an `array_replace` ordering nothing documents. The subset is smaller than the
 * workaround, so it is written here.
 *
 * ── The subset, and why each command is in it ────────────────────
 *
 *   CAPABILITY  what the server will do, asked AGAIN after STARTTLS
 *   STARTTLS    encryption on port 143
 *   LOGIN       the only credential this provider has
 *   EXAMINE     open the mailbox READ-ONLY — see {@see examineInbox}
 *   STATUS      unread in INBOX without selecting it — see {@see inboxUnseen}
 *   UID SEARCH  the provider's own search, over the whole mailbox
 *   UID FETCH   headers, structure, and one body part
 *   LOGOUT      say goodbye rather than dropping the socket
 *
 * Nothing writes. There is no STORE, no APPEND, no EXPUNGE and no COPY, and
 * {@see examineInbox} opens read-only so a bug could not become one.
 */
final class ImapConnection
{
    /**
     * How many response lines one command may produce.
     *
     * A third bound, beside the byte and time budgets on {@see ImapSocket},
     * against the server that answers a single command with untagged lines for
     * ever. Each line is individually small and in time, so neither of the other
     * two would fire — which is exactly the gap this closes.
     */
    private const MAX_LINES_PER_COMMAND = 10_000;

    private int $tag = 0;

    /** @var string[] lower-cased, from the most recent CAPABILITY */
    private array $capabilities = [];

    /** The remainder of the line being parsed, CRLF already stripped. */
    private string $buffer = '';

    private int $offset = 0;

    private function __construct(private readonly ImapSocket $socket) {}

    /**
     * Connect, encrypt, and be ready for a password.
     *
     * @throws ImapFailure
     */
    public static function open(ApprovedHost $approved, string $encryption, ?int $patience = null): self
    {
        $connection = new self(ImapSocket::open($approved, $encryption, $patience));

        $greeting = $connection->socket->line();

        if (! preg_match('/^\*\s+(OK|PREAUTH)\b/i', $greeting)) {
            throw ImapFailure::misbehaved(
                'The server on that port did not greet us as an IMAP server does. It is probably '
                .'something other than a mail server, or the wrong port.'
            );
        }

        if (! $connection->socket->isEncrypted()) {
            $connection->startTls();
        }

        /*
         * Asked AFTER encryption, and the earlier answer is discarded.
         *
         * RFC 3501 is explicit about this and the reason is not bookkeeping: the
         * pre-TLS capability list arrived in the clear from whoever was on the
         * wire, so anything decided from it was decided by them. A stripped
         * `STARTTLS` from that list is the classic downgrade.
         */
        $connection->socket->assertEncrypted();
        $connection->loadCapabilities();

        return $connection;
    }

    /**
     * STARTTLS on port 143, or a refusal — never a quiet downgrade.
     *
     * @throws ImapFailure
     */
    private function startTls(): void
    {
        $this->loadCapabilities();

        if (! in_array('starttls', $this->capabilities, strict: true)) {
            throw ImapFailure::unreachable(
                'That mail server does not offer an encrypted connection on this port, so EzBizi '
                .'stopped rather than send the password as readable text. Try port 993, which is '
                .'the encrypted one on almost every mail service.'
            );
        }

        $this->command('STARTTLS');

        // Only now, and over the socket that has already been opened to the
        // approved address — the certificate is still checked against the name.
        $this->socket->secure();

        // Everything learned before this moment came from an unencrypted
        // connection and is thrown away.
        $this->capabilities = [];
    }

    /** @throws ImapFailure */
    private function loadCapabilities(): void
    {
        foreach ($this->command('CAPABILITY')['untagged'] as $tokens) {
            if (strcasecmp((string) ($tokens[1] ?? ''), 'CAPABILITY') !== 0) {
                continue;
            }

            foreach (array_slice($tokens, 2) as $capability) {
                if (is_string($capability)) {
                    $this->capabilities[] = strtolower($capability);
                }
            }
        }
    }

    /**
     * Sign in.
     *
     * @throws ImapFailure
     */
    public function login(string $username, string $password): void
    {
        // Belt and braces: nothing may be sent before this is true, and the
        // socket is asked rather than trusted.
        $this->socket->assertEncrypted();

        if (in_array('logindisabled', $this->capabilities, strict: true)) {
            throw ImapFailure::unreachable(
                'That mail server does not accept a password over this connection. It probably '
                .'requires signing in through the provider instead, which EzBizi cannot do for a '
                .'generic IMAP mailbox.'
            );
        }

        $result = $this->command(sprintf(
            'LOGIN %s %s',
            $this->quoted($username, 'user name'),
            $this->quoted($password, 'password'),
        ), tolerate: true);

        if ($result['status'] !== 'OK') {
            throw ImapFailure::rejected('The mail server refused the user name or password.');
        }
    }

    /**
     * Open the inbox READ-ONLY, and report its size and identity.
     *
     * ── EXAMINE and not SELECT, on purpose ───────────────────────
     *
     * EXAMINE is SELECT with writing switched off at the server. EzBizi has no
     * reason to modify a customer's mailbox — it keeps its own record of who has
     * read what, in `message_seen`, precisely so that a shared inbox can show
     * "seen by Jenny" rather than one global read flag — so the connection asks
     * for a mode in which a mistake could not change anything. It also leaves
     * `unreadAtProvider` meaning what it says: reading a message here does not
     * mark it read in the customer's own mail program.
     *
     * ── UIDNEXT is read here because it is the WATCHER'S MARK ────
     *
     * The server volunteers it on EXAMINE: the UID it will give the next message
     * to arrive. That is exactly "everything from here on is new", said by the
     * only party entitled to say it, and reading it costs nothing because the
     * command is being sent anyway. See {@see ImapMailboxReader::currentMark}.
     *
     * ZERO when the server did not say. It is a SHOULD in the protocol rather
     * than a MUST, and a server that omits it must not be papered over with a
     * guess — a wrong "next" is either a message announced twice or one never
     * announced at all. The reader derives it from the highest UID instead, and
     * zero is what tells it to.
     *
     * @return array{exists: int, uidvalidity: int, uidnext: int}
     *
     * @throws ImapFailure
     */
    public function examineInbox(): array
    {
        $result = $this->command('EXAMINE INBOX', tolerate: true);

        if ($result['status'] !== 'OK') {
            throw ImapFailure::misbehaved('The mail server would not open the INBOX folder.');
        }

        $exists      = 0;
        $uidvalidity = 0;
        $uidnext     = 0;

        foreach ($result['raw'] as $line) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $line, $m)) {
                $exists = (int) $m[1];
            }

            if (preg_match('/UIDVALIDITY\s+(\d+)/i', $line, $m)) {
                $uidvalidity = (int) $m[1];
            }

            if (preg_match('/UIDNEXT\s+(\d+)/i', $line, $m)) {
                $uidnext = (int) $m[1];
            }
        }

        return ['exists' => $exists, 'uidvalidity' => $uidvalidity, 'uidnext' => $uidnext];
    }

    /**
     * Unread in INBOX, without selecting the mailbox and without listing mail.
     *
     * `STATUS INBOX (UNSEEN)` is the provider's own unread figure. EXAMINE
     * reports EXISTS, which is everything, seen or not.
     *
     * @throws ImapFailure
     */
    public function inboxUnseen(): int
    {
        $result = $this->command('STATUS INBOX (UNSEEN)', tolerate: true);

        if ($result['status'] !== 'OK') {
            throw ImapFailure::misbehaved('The mail server would not say how many unread messages are in the INBOX.');
        }

        foreach ($result['raw'] as $line) {
            if (preg_match('/^\*\s+STATUS\b/i', $line) === 1
                && preg_match('/UNSEEN\s+(\d+)/i', $line, $match) === 1) {
                return (int) $match[1];
            }
        }

        throw ImapFailure::misbehaved('The mail server did not say how many unread messages are in the INBOX.');
    }

    /**
     * Every UID matching a search, oldest first as the server returns them.
     *
     * @return int[]
     *
     * @throws ImapFailure
     */
    public function searchUids(string $criteria): array
    {
        $result = $this->command('UID SEARCH '.$criteria, tolerate: true);

        if ($result['status'] !== 'OK') {
            /*
             * CHARSET UTF-8 is the commonest reason a perfectly good search is
             * refused — it is optional, and servers that lack it answer BAD. The
             * caller retries without it rather than telling somebody their search
             * was wrong when it was the server that was limited.
             */
            throw ImapFailure::misbehaved('The mail server refused that search.');
        }

        $uids = [];

        foreach ($result['untagged'] as $tokens) {
            if (strcasecmp((string) ($tokens[1] ?? ''), 'SEARCH') !== 0) {
                continue;
            }

            foreach (array_slice($tokens, 2) as $value) {
                if (is_string($value) && ctype_digit($value)) {
                    $uids[] = (int) $value;
                }
            }
        }

        sort($uids);

        return $uids;
    }

    /**
     * Headers and structure for a set of messages.
     *
     * @param  string  $set  an IMAP sequence set — `12:34` or `5,9,11`
     * @param  bool  $byUid  false for sequence numbers, true for UIDs
     * @return array<int, array<string, mixed>>  keyed by UID
     *
     * @throws ImapFailure
     */
    public function fetchHeaders(string $set, bool $byUid): array
    {
        $result = $this->command(sprintf(
            '%sFETCH %s (UID FLAGS ENVELOPE BODYSTRUCTURE)',
            $byUid ? 'UID ' : '',
            $set,
        ), tolerate: true);

        if ($result['status'] !== 'OK') {
            throw ImapFailure::misbehaved('The mail server would not return the message list.');
        }

        $messages = [];

        foreach ($result['untagged'] as $tokens) {
            if (strcasecmp((string) ($tokens[2] ?? ''), 'FETCH') !== 0 || ! is_array($tokens[3] ?? null)) {
                continue;
            }

            $pairs = $this->pairs($tokens[3]);
            $uid   = (int) ($pairs['uid'] ?? 0);

            if ($uid > 0) {
                $messages[$uid] = $pairs;
            }
        }

        return $messages;
    }

    /**
     * One part of one message's body, at most {@see ImapSocket::MAX_LITERAL_BYTES}.
     *
     * ── BODY.PEEK, and a partial fetch ───────────────────────────
     *
     * `.PEEK` is what stops reading a message here marking it read in the
     * customer's own mail program. Without it the plain `BODY[...]` form sets
     * `\Seen` as a side effect, and a colleague opening a message in EzBizi
     * would silently change what everybody else sees in Outlook.
     *
     * `<0.N>` is IMAP's partial fetch, and it is the difference between bounding
     * the RESPONSE and bounding the REQUEST. The socket would refuse an
     * oversized literal either way — but asking for the first megabyte means a
     * well-behaved server never sends the twenty-megabyte attachment in the
     * first place, so the bound costs nothing instead of costing the whole
     * download.
     *
     * @throws ImapFailure
     */
    public function bodyPart(int $uid, string $part): string
    {
        return $this->bodyParts((string) $uid, $part, ImapSocket::MAX_LITERAL_BYTES)[$uid] ?? '';
    }

    /**
     * The same part of SEVERAL messages, in one command.
     *
     * What the message list needs. Drawing a page of twenty rows with a preview
     * line each is twenty round trips done the obvious way, and one done this
     * way — messages that share a shape share a part number, so the whole page
     * usually collapses into one or two commands.
     *
     * @param  string  $uidSet  an IMAP UID set — `5,9,11` or `3:20`
     * @return array<int, string>  keyed by UID
     *
     * @throws ImapFailure
     */
    public function bodyParts(string $uidSet, string $part, int $bytes): array
    {
        $result = $this->command(sprintf(
            'UID FETCH %s (BODY.PEEK[%s]<0.%d>)',
            $uidSet,
            $part,
            min($bytes, ImapSocket::MAX_LITERAL_BYTES),
        ), tolerate: true);

        if ($result['status'] !== 'OK') {
            /*
             * Empty rather than thrown. A server that will not return one part
             * of one message — a structure it described inaccurately, a part
             * that has since been expunged — must not take the whole page down
             * with it. The row still has its subject, its sender and its date.
             */
            return [];
        }

        $bodies = [];

        foreach ($result['untagged'] as $tokens) {
            if (strcasecmp((string) ($tokens[2] ?? ''), 'FETCH') !== 0 || ! is_array($tokens[3] ?? null)) {
                continue;
            }

            $pairs = $this->pairs($tokens[3]);
            $uid   = (int) ($pairs['uid'] ?? 0);

            if ($uid === 0) {
                continue;
            }

            foreach ($pairs as $key => $value) {
                if (str_starts_with($key, 'body[') && is_string($value)) {
                    $bodies[$uid] = $value;

                    break;
                }
            }
        }

        return $bodies;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT', tolerate: true);
        } catch (ImapFailure) {
            // Saying goodbye is a courtesy to the server, not something the
            // caller is waiting on. A mailbox that was read successfully must not
            // report a failure because the farewell did not land.
        }

        $this->socket->close();
    }

    // ── the wire ─────────────────────────────────────────────────

    /**
     * Send one command and read everything it produces.
     *
     * @param  bool  $tolerate  return a NO/BAD as a status instead of throwing —
     *                          for the callers that have something better to say
     *                          about it than "the server refused"
     * @return array{status: string, untagged: array<int, array<int, mixed>>, raw: string[]}
     *
     * @throws ImapFailure
     */
    private function command(string $command, bool $tolerate = false): array
    {
        $tag = 'E'.(++$this->tag);

        $this->socket->write($tag.' '.$command."\r\n");

        $untagged = [];
        $raw      = [];
        $lines    = 0;

        while (true) {
            if (++$lines > self::MAX_LINES_PER_COMMAND) {
                throw ImapFailure::misbehaved(sprintf(
                    'The mail server sent more than %d lines in answer to one command.',
                    self::MAX_LINES_PER_COMMAND,
                ));
            }

            $line = $this->socket->line();
            $raw[] = $line;

            $tokens = $this->tokenise($line);

            // The tagged line is the completion, and it ends the command.
            if (($tokens[0] ?? null) === $tag) {
                $status = strtoupper((string) ($tokens[1] ?? 'BAD'));

                if ($status !== 'OK' && ! $tolerate) {
                    throw ImapFailure::misbehaved('The mail server refused the '.strtok($command, ' ').' command.');
                }

                return ['status' => $status, 'untagged' => $untagged, 'raw' => $raw];
            }

            /*
             * A `+` line is the server inviting us to send a literal. Nothing
             * below sends one — the credentials go as quoted strings — so being
             * asked for one means the conversation has desynchronised, and
             * carrying on would send the next command into whatever the server
             * thinks it is collecting.
             */
            if (($tokens[0] ?? null) === '+') {
                throw ImapFailure::misbehaved('The mail server asked for data EzBizi did not offer to send.');
            }

            $untagged[] = $tokens;
        }
    }

    /**
     * A response line, as nested tokens — pulling literals off the socket as it
     * meets them.
     *
     * @return array<int, mixed>
     *
     * @throws ImapFailure
     */
    private function tokenise(string $line): array
    {
        $this->buffer = rtrim($line, "\r\n");
        $this->offset = 0;

        $tokens = [];

        while (true) {
            $this->skipSpaces();

            if ($this->offset >= strlen($this->buffer)) {
                return $tokens;
            }

            $tokens[] = $this->readToken();
        }
    }

    /** @throws ImapFailure */
    private function readToken(): mixed
    {
        $char = $this->buffer[$this->offset];

        return match (true) {
            $char === '('  => $this->readList(),
            $char === '"'  => $this->readQuoted(),
            $char === '{'  => $this->readLiteral(),
            default        => $this->readAtom(),
        };
    }

    /**
     * @return array<int, mixed>
     *
     * @throws ImapFailure
     */
    private function readList(): array
    {
        $this->offset++; // past '('

        $items = [];

        while (true) {
            $this->skipSpaces();

            if ($this->offset >= strlen($this->buffer)) {
                /*
                 * A list that does not close on this line and was not continued
                 * by a literal. Refused rather than read on into the next line:
                 * "keep reading until it closes" is an unbounded loop wearing a
                 * parser's clothes, and a server can open a hundred thousand
                 * parentheses as cheaply as one.
                 */
                throw ImapFailure::misbehaved('The mail server sent a response that never closed its brackets.');
            }

            if ($this->buffer[$this->offset] === ')') {
                $this->offset++;

                return $items;
            }

            $items[] = $this->readToken();
        }
    }

    /** @throws ImapFailure */
    private function readQuoted(): string
    {
        $this->offset++; // past '"'

        $value = '';
        $end   = strlen($this->buffer);

        while ($this->offset < $end) {
            $char = $this->buffer[$this->offset++];

            if ($char === '\\' && $this->offset < $end) {
                $value .= $this->buffer[$this->offset++];

                continue;
            }

            if ($char === '"') {
                return $value;
            }

            $value .= $char;
        }

        throw ImapFailure::misbehaved('The mail server sent a quoted value that was never closed.');
    }

    /**
     * `{1234}` at the end of a line, then exactly that many bytes.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THE REFUSAL IS ON THE ANNOUNCEMENT, NOT ON THE DATA.
     *
     *  This is the one place a hostile server gets to name a number
     *  that becomes an allocation, and the whole defence is that the
     *  number is judged BEFORE anything is read. `{2147483647}` costs
     *  a comparison and a sentence; it never becomes a buffer, never
     *  becomes a loop, and never gets as far as the socket.
     *
     *  Both libraries evaluated for this work do the opposite —
     *  webklex accumulates lines until it has the announced length,
     *  imapengine asks its stream for exactly that many bytes — and
     *  both were measured failing on it. That measurement is why this
     *  file exists.
     * ═══════════════════════════════════════════════════════════════
     *
     * @throws ImapFailure
     */
    private function readLiteral(): string
    {
        $close = strpos($this->buffer, '}', $this->offset);

        if ($close === false) {
            throw ImapFailure::misbehaved('The mail server announced a message part without saying how long it was.');
        }

        // `+` is the non-synchronising form (RFC 7888); the digits are the same.
        $digits = rtrim(substr($this->buffer, $this->offset + 1, $close - $this->offset - 1), '+');

        if ($digits === '' || ! ctype_digit($digits)) {
            throw ImapFailure::misbehaved('The mail server announced a message part whose length was not a number.');
        }

        /*
         * Compared as a STRING first. `(int) "99999999999999999999"` saturates to
         * PHP_INT_MAX rather than overflowing, so the numeric comparison below
         * would still catch it — but a length with twenty digits in it is not a
         * length, and refusing it by its shape does not depend on that being
         * true of every future PHP.
         */
        if (strlen($digits) > 10 || (int) $digits > ImapSocket::MAX_LITERAL_BYTES) {
            throw ImapFailure::misbehaved(sprintf(
                'The mail server said the next part of this message is %s bytes. EzBizi will not '
                .'read more than %d bytes at once.',
                $digits,
                ImapSocket::MAX_LITERAL_BYTES,
            ));
        }

        if ($close !== strlen($this->buffer) - 1) {
            throw ImapFailure::misbehaved('The mail server put a message part in the middle of a line, which IMAP does not do.');
        }

        $data = $this->socket->bytes((int) $digits);

        // The response continues on the line after the literal's bytes.
        $this->buffer = rtrim($this->socket->line(), "\r\n");
        $this->offset = 0;

        return $data;
    }

    /** An unquoted word — or null, which is how IMAP spells "nothing". */
    private function readAtom(): ?string
    {
        $start = $this->offset;
        $end   = strlen($this->buffer);
        $depth = 0;

        while ($this->offset < $end) {
            $char = $this->buffer[$this->offset];

            /*
             * Square brackets are part of the atom, not delimiters. `BODY[HEADER.
             * FIELDS (MESSAGE-ID)]` is ONE item name with spaces and parentheses
             * inside it, and splitting on those would turn a FETCH response into
             * nonsense.
             */
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($char === ' ' || $char === '(' || $char === ')')) {
                break;
            }

            $this->offset++;
        }

        $atom = substr($this->buffer, $start, $this->offset - $start);

        return strcasecmp($atom, 'NIL') === 0 ? null : $atom;
    }

    private function skipSpaces(): void
    {
        while ($this->offset < strlen($this->buffer) && $this->buffer[$this->offset] === ' ') {
            $this->offset++;
        }
    }

    /**
     * A FETCH item list — `(UID 3 FLAGS (\Seen) ...)` — as a lower-cased map.
     *
     * @param  array<int, mixed>  $items
     * @return array<string, mixed>
     */
    private function pairs(array $items): array
    {
        $pairs = [];

        for ($i = 0; $i + 1 < count($items); $i += 2) {
            if (is_string($items[$i])) {
                $pairs[strtolower($items[$i])] = $items[$i + 1];
            }
        }

        return $pairs;
    }

    /**
     * A value, as an IMAP quoted string — and a refusal for the bytes that would
     * end the command early.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THIS IS THE HEADER-INJECTION GUARD, IN ITS IMAP SPELLING.
     *
     *  {@see ReplyComposer::headerValue} makes the same argument about
     *  mail headers. An IMAP command is one CRLF-terminated line, so a
     *  password containing
     *
     *      hunter2"\r\nE9 DELETE INBOX
     *
     *  would end the LOGIN command and start a second one that the
     *  server would execute with the credentials it had just accepted.
     *  The password field is typed by an administrator into a form and
     *  stored, so this is not a hypothetical byte sequence — it is a
     *  field somebody controls.
     *
     *  Refused rather than escaped. A backslash-escaped CR is not
     *  valid in an IMAP quoted string at all, so there is no correct
     *  way to send one, and quietly stripping it would sign somebody
     *  in with a password that is not theirs.
     * ═══════════════════════════════════════════════════════════════
     *
     * @throws ImapFailure
     */
    private function quoted(string $value, string $what): string
    {
        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw ImapFailure::unreachable(sprintf(
                'The %s saved for this mailbox contains a line break, which cannot be sent to a '
                .'mail server. Enter it again without one.',
                $what,
            ));
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
