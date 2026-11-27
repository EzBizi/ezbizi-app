<?php

namespace App\Support\Email;

/**
 * The socket itself — opened to the address the guard approved, encrypted
 * against the name the tenant typed, and structurally unable to read without
 * limit.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THIS CLASS EXISTS BECAUSE NO IMAP LIBRARY CAN BE TOLD THOSE TWO
 *  THINGS APART.
 *
 *  {@see ApprovedHost} says it in one line: *"The address to open the
 *  socket to. Not to be looked up again."* Every socket-driver IMAP
 *  client on Packagist builds its target as one string —
 *  `"{$transport}://{$host}:{$port}"` — and that single variable is
 *  BOTH what the socket connects to AND what OpenSSL verifies the
 *  certificate against. Hand it the name and the name is resolved a
 *  second time, which is the DNS rebinding {@see RemoteHostGuard}
 *  rule 3 exists to stop. Hand it the address and the certificate is
 *  checked against an IP, which no mail server's certificate names,
 *  so verification has to be turned off to make it work at all.
 *
 *  Both roads end with the guard defeated, so the socket is opened
 *  here instead:
 *
 *    - `stream_socket_client()` gets `tcp://` and the ADDRESS.
 *    - `stream_socket_enable_crypto()` runs afterwards over a context
 *      whose `peer_name` is the NAME, which is what OpenSSL matches
 *      the certificate against and what is sent as SNI.
 *
 *  Those are two different variables in two different calls, which is
 *  the only shape where "connect there, verify that" is expressible.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Every read is bounded, and that is not belt-and-braces ───────
 *
 * The mail server is a host somebody typed into a form. It is not trusted to be
 * a mail server at all, let alone a well-behaved one, and the two cheapest ways
 * for it to hurt us both look like ordinary protocol:
 *
 *   A LINE THAT NEVER ENDS. IMAP responses are CRLF-terminated, so a client
 *   reads until the newline. A server that sends bytes and no newline for ever
 *   makes that loop allocate for ever. {@see MAX_LINE_BYTES} is the answer.
 *
 *   A LITERAL THAT ANNOUNCES 2GB. `{2147483647}` tells the client exactly how
 *   many bytes follow, and the obvious implementation trusts it and allocates.
 *   The defence is to refuse on the ANNOUNCEMENT — see
 *   {@see ImapConnection::readLiteral} — so nothing is ever allocated for a
 *   size we would not accept. Both libraries evaluated for this work fail here:
 *   a probe against a server sending that literal exhausted 256MB in seven
 *   seconds inside webklex/php-imap's own accumulation loop.
 *
 * ── Two clocks, because one is not enough ────────────────────────
 *
 * `stream_set_timeout()` bounds a SINGLE read, and a server that sends one byte
 * every fourteen seconds resets it for ever while holding a PHP worker open.
 * That is the slow-loris shape, and it is why {@see MAX_CONVERSATION_SECONDS}
 * exists beside the per-read timeout: one clock says "you have gone quiet", the
 * other says "you have had long enough".
 *
 * ── Cleartext is refused at construction ─────────────────────────
 *
 * Not checked later and not warned about: {@see open} will not build a socket
 * that is never going to be encrypted, so there is no code path through this
 * class that sends a password in the clear. See {@see modeFor}.
 */
final class ImapSocket
{
    /**
     * The longest single response line, in bytes.
     *
     * Generous rather than tight, because ONE legitimate response is genuinely
     * enormous: `SEARCH` answers with every matching message number on a single
     * line, and a mailbox with a hundred thousand messages in it produces
     * several hundred kilobytes of digits. A cap tuned to the size of a normal
     * line would break the large mailboxes it matters most not to break.
     *
     * What it still stops is the unbounded case, which is the one that matters:
     * a server dribbling bytes with no newline hits this and is dropped.
     */
    public const MAX_LINE_BYTES = 1_048_576;

    /**
     * The longest literal this will accept the announcement of.
     *
     * Sized from the far end of the pipe rather than guessed:
     * {@see BodySanitiser::MAX_INPUT_BYTES} is 512 KiB and the sanitiser
     * truncates anything past it, so a body larger than that cannot reach a
     * screen however much of it we download. Base64 inflates by a third, so 1
     * MiB is 512 KiB of decoded body with room to spare — and everything above
     * it would be fetched, decoded, and then thrown away.
     *
     * {@see ImapConnection::bodyPart} also asks the server for no more than this
     * using IMAP's partial fetch, so on a well-behaved server the big body is
     * never sent at all rather than being sent and refused.
     */
    public const MAX_LITERAL_BYTES = 1_048_576;

    /**
     * Everything one conversation may read, added up.
     *
     * The per-line and per-literal caps bound one response each; this bounds a
     * server that answers every single command with a merely-large response and
     * never stops. Twenty megabytes is far more than any page of headers needs
     * and far less than a worker's memory limit.
     */
    public const MAX_CONVERSATION_BYTES = 20_971_520;

    /**
     * How long the whole conversation may take, however chatty it stays.
     *
     * Deliberately shorter than a web request timeout. Somebody is looking at a
     * spinner: a mailbox that has not produced a page of headers in half a
     * minute has failed, and saying so is better than holding the worker.
     */
    public const MAX_CONVERSATION_SECONDS = 30;

    /** @var resource */
    private $stream;

    private bool $encrypted = false;

    private int $bytesRead = 0;

    private float $deadline;

    /**
     * Bytes taken off the socket and not yet handed out.
     *
     * A read returns whatever has arrived, which rarely lines up with a line
     * ending or a literal boundary — so the remainder waits here for the next
     * {@see line} or {@see bytes}. See {@see fill} for why reading in arrival-
     * sized pieces rather than line-sized ones is what makes the deadline work.
     */
    private string $pending = '';

    /**
     * @param  resource  $stream
     */
    private function __construct($stream, private readonly ApprovedHost $approved, int $patience)
    {
        $this->stream   = $stream;
        $this->deadline = microtime(true) + $patience;
    }

    /**
     * Open, and encrypt now if this is an implicit-TLS port.
     *
     * @param  string  $encryption  what the tenant's settings say — see {@see modeFor},
     *                              which decides what actually happens and
     *                              refuses cleartext outright
     * @param  int|null  $patience  seconds the whole conversation may take
     *
     * ── Why `$patience` exists, and why it cannot be abused ──────
     *
     * Proving that a silent server is dropped means waiting for the drop, and at
     * the real {@see RemoteHostGuard::READ_TIMEOUT_SECONDS} that is fifteen
     * seconds of test suite per assertion. So the test suite shortens it.
     *
     * The shortening is CLAMPED, in {@see patience} below: the value is passed
     * through `min()` against the production limits, so this parameter can only
     * ever make the socket give up SOONER. There is no argument — from a test,
     * from a config file, or from a future caller who means well — that makes it
     * wait longer than the constants above allow. A seam that could loosen the
     * guarantee it exists to test would be worse than a slow test.
     *
     * @throws ImapFailure
     */
    public static function open(ApprovedHost $approved, string $encryption, ?int $patience = null): self
    {
        $mode = self::modeFor($approved->port, $encryption);

        /*
         * `tcp://` AND THE ADDRESS. This one line is the guard's third rule.
         *
         * Not `ssl://`, even on 993, and not the hostname — because both of
         * those hand the name to the stream layer and let it do the resolving
         * and the verifying together. Encryption is turned on separately below,
         * over a context that names the host explicitly, which is the only way
         * the two can differ.
         */
        $context = stream_context_create(['ssl' => self::tlsOptions($approved)]);

        /*
         * `@` because a refused or unroutable address makes stream_socket_client
         * emit a warning as well as returning false, and the warning would be
         * rendered by whatever error handler is installed instead of the
         * sentence below. The same reasoning as RemoteHostGuard's `@dns_get_record`.
         */
        $stream = @stream_socket_client(
            $approved->socketTarget(),
            $errno,
            $errstr,
            RemoteHostGuard::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            // A connection that did not open is a network or DNS blip until it
            // keeps happening: TIMEOUT, retried with backoff. As UNREACHABLE it
            // marked the inbox broken on the first one (audit 11-27).
            throw ImapFailure::notAnswering(sprintf(
                'Could not open a connection to %s on port %d: %s.',
                $approved->host,
                $approved->port,
                $errstr === '' ? 'no answer' : $errstr,
            ));
        }

        stream_set_timeout($stream, self::patience($patience, RemoteHostGuard::READ_TIMEOUT_SECONDS));

        $socket = new self($stream, $approved, self::patience($patience, self::MAX_CONVERSATION_SECONDS));

        if ($mode === 'implicit') {
            $socket->secure();
        }

        return $socket;
    }

    /**
     * The smaller of what was asked for and what is allowed. Never the larger.
     *
     * One line, and it is the whole reason `$patience` is safe to expose. See
     * the note on {@see open}.
     */
    private static function patience(?int $asked, int $limit): int
    {
        return $asked === null ? $limit : max(1, min($asked, $limit));
    }

    /**
     * Which of the two encrypted shapes this is — or a refusal.
     *
     * ── Decided by the PORT, not by the stored word ──────────────
     *
     * Because the stored word is ambiguous and the port is not. "TLS" in a mail
     * client's settings means implicit TLS to some providers and STARTTLS to
     * others; each of the four ports means exactly one thing, and
     * {@see RemoteHostGuard::IMAP_PORTS} and {@see RemoteHostGuard::SMTP_PORTS}
     * already limit the field to those four. Reading the port removes a whole
     * class of "it says TLS and it does not connect" support calls.
     *
     *   993 IMAP over TLS from the first byte      → implicit
     *   465 SMTP submission over TLS likewise      → implicit
     *   143 IMAP, encrypted by STARTTLS            → starttls
     *   587 SMTP submission, likewise              → starttls
     *
     * The stored word is still read, for the one thing it says unambiguously:
     * that somebody has asked for no encryption at all. That is refused here,
     * which is why no code path in this class can send a password in the clear.
     *
     * @throws ImapFailure
     */
    private static function modeFor(int $port, string $encryption): string
    {
        $asked = strtolower(trim($encryption));

        if (in_array($asked, ['none', 'plain', 'insecure', 'off', ''], strict: true)) {
            throw ImapFailure::unreachable(
                'This mailbox is set to connect without encryption, which EzBizi will not do. '
                .'The password and every message would cross the network as readable text. Ask '
                .'whoever runs the mail server for its secure settings — almost always port 993 '
                .'for reading and 587 for sending — and enter those instead.'
            );
        }

        return in_array($port, [993, 465], strict: true) ? 'implicit' : 'starttls';
    }

    /**
     * The TLS settings, and the whole point of the class is in `peer_name`.
     *
     * ── address vs name, pinned ──────────────────────────────────
     *
     * The socket above went to `$approved->address`. Left alone, OpenSSL would
     * then verify the certificate against that address — which no mail server's
     * certificate names, so it would fail, and the usual "fix" is to turn
     * verification off, which is how a guard becomes decoration.
     *
     * `peer_name` breaks the tie. It is what the certificate is checked against
     * and what is sent as SNI, and it is the name THE TENANT TYPED. So the
     * connection goes exactly where the guard looked, and the certificate still
     * has to belong to the server they asked for. A rebinding attack now has to
     * produce a valid certificate for the victim's own hostname, which is the
     * property TLS is for.
     *
     * `verify_peer` and `verify_peer_name` are both on and there is no setting
     * anywhere that turns them off. An option to skip verification is a support
     * answer waiting to happen — it fixes every certificate complaint instantly
     * and silently removes the only check that makes the address pin worth
     * anything.
     */
    private static function tlsOptions(ApprovedHost $approved): array
    {
        return [
            'peer_name'          => $approved->host,
            'verify_peer'        => true,
            'verify_peer_name'   => true,
            'allow_self_signed'  => false,
            'SNI_enabled'        => true,
            'disable_compression' => true,

            /*
             * TLS 1.2 at the oldest. 1.0 and 1.1 have been deprecated since 2021
             * and a mail host still requiring them is a mail host with worse
             * problems, but the refusal has to be explicit: PHP's default
             * `STREAM_CRYPTO_METHOD_TLS_CLIENT` still negotiates them.
             */
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
    }

    /**
     * Turn encryption on — for implicit TLS at once, for STARTTLS after the
     * command.
     *
     * One method for both, deliberately. The two differ only in WHEN they are
     * called, and giving them separate implementations is how one of them ends
     * up with `verify_peer` quietly missing.
     *
     * @throws ImapFailure
     */
    public function secure(): void
    {
        /*
         * `@` for the same reason as above, and it matters more here: a failed
         * handshake emits several warnings, one of which quotes the certificate's
         * common name. That is somebody else's server's details being rendered
         * through our error handler.
         */
        $enabled = @stream_socket_enable_crypto(
            $this->stream,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        );

        if ($enabled !== true) {
            throw ImapFailure::unreachable(sprintf(
                'EzBizi could not make an encrypted connection to "%s" that it can trust. Either '
                .'the server does not offer encryption on port %d, or its security certificate is '
                .'not valid for that name — a certificate for a different name, or one that has '
                .'expired. Check the server name and port with whoever runs the mail service; '
                .'EzBizi will not connect without a certificate it can check, because that is what '
                .'stops somebody else answering in their place.',
                $this->approved->host,
                $this->approved->port,
            ));
        }

        $this->encrypted = true;
    }

    /**
     * Refuse to go on unless the connection is encrypted.
     *
     * Called before the password is sent and before any message is asked for.
     * It is the second half of the cleartext refusal: {@see modeFor} stops a
     * mailbox that never intended to encrypt, and this stops a STARTTLS
     * negotiation that was started and did not finish.
     *
     * @throws ImapFailure
     */
    public function assertEncrypted(): void
    {
        if ($this->encrypted) {
            return;
        }

        throw ImapFailure::unreachable(sprintf(
            'The mail server at "%s" would not start an encrypted connection on port %d, so '
            .'EzBizi stopped rather than send the password as readable text. %s Try that, or ask '
            .'whoever runs the server to turn on STARTTLS.',
            $this->approved->host,
            $this->approved->port,
            // The advice names the port for the job in hand. Telling somebody
            // setting up sending to "try 993" would send them to the reading
            // port, where nothing they are configuring listens.
            in_array($this->approved->port, RemoteHostGuard::SMTP_PORTS, strict: true)
                ? 'Port 465 is the encrypted sending port on almost every mail service.'
                : 'Port 993 is the encrypted reading port on almost every mail service.',
        ));
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * One line, up to and including the newline, bounded both ways.
     *
     * @throws ImapFailure
     */
    public function line(): string
    {
        while (true) {
            $break = strpos($this->pending, "\n");

            if ($break !== false) {
                $line = substr($this->pending, 0, $break + 1);

                $this->pending = substr($this->pending, $break + 1);

                return $line;
            }

            /*
             * The unbounded-line defence. A server that keeps sending and never
             * sends a newline gets exactly this far.
             */
            if (strlen($this->pending) > self::MAX_LINE_BYTES) {
                throw ImapFailure::misbehaved(sprintf(
                    'The mail server sent more than %d bytes on one line without ending it, '
                    .'which no IMAP response does.',
                    self::MAX_LINE_BYTES,
                ));
            }

            $this->fill('waiting for a reply');
        }
    }

    /**
     * Exactly this many bytes.
     *
     * The caller has ALREADY judged `$count` against {@see MAX_LITERAL_BYTES} —
     * see {@see ImapConnection::readLiteral}. That ordering is the defence: this
     * method allocates, so nothing may reach it that we were not willing to
     * hold. The check is repeated here anyway, because "the caller checked" is
     * the sentence in front of most buffer overflows.
     *
     * @throws ImapFailure
     */
    public function bytes(int $count): string
    {
        if ($count < 0 || $count > self::MAX_LITERAL_BYTES) {
            throw ImapFailure::misbehaved(sprintf(
                'Refused to read %d bytes; the limit is %d.', $count, self::MAX_LITERAL_BYTES,
            ));
        }

        while (strlen($this->pending) < $count) {
            $this->fill('reading a message');
        }

        $out = substr($this->pending, 0, $count);

        $this->pending = substr($this->pending, $count);

        return $out;
    }

    /**
     * Take whatever has arrived, and check both clocks while doing it.
     *
     * ═══════════════════════════════════════════════════════════════
     *  `fread` AND NOT `fgets`, AND THAT IS THE WHOLE SLOW-LORIS FIX.
     *
     *  `fgets()` returns when it has a COMPLETE LINE. A server sending
     *  one byte every 200ms with no newline therefore keeps a single
     *  `fgets` call blocked indefinitely: the per-read timeout is an
     *  IDLE timeout and the socket is never idle, and the
     *  conversation deadline is checked between reads — of which
     *  there is only ever one. The worker is held for as long as the
     *  attacker cares to dribble.
     *
     *  This was not theoretical. The first version of this class used
     *  `fgets`, and {@see \Tests\Feature\ImapTransportTest::test_a_server_that_dribbles_bytes_for_ever_is_dropped}
     *  caught it holding the connection for twenty seconds against a
     *  three-second deadline.
     *
     *  `fread()` returns as soon as ANY bytes are available, so the
     *  loops above come back round, and the deadline below is
     *  consulted every few hundred bytes instead of once. Assembling
     *  lines out of the buffer is what that costs, and it is why this
     *  class buffers at all.
     * ═══════════════════════════════════════════════════════════════
     *
     * @throws ImapFailure
     */
    private function fill(string $doing): void
    {
        $this->assertTimeLeft($doing);

        $chunk = fread($this->stream, 65536);

        if ($chunk === false || $chunk === '') {
            $this->explainDeadStream($doing);
        }

        $this->pending .= $chunk;

        $this->spend(strlen($chunk));
    }

    /** @throws ImapFailure */
    public function write(string $data): void
    {
        $this->assertTimeLeft('sending a command');

        if (@fwrite($this->stream, $data) === false) {
            throw ImapFailure::unreachable('The connection to the mail server closed while sending a command.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }

    /**
     * A read came back empty. Say which of the three reasons it was.
     *
     * Worth telling apart because they send somebody to different places: a
     * timeout is "try again", a closed connection after TLS is usually the
     * server disliking something we sent, and a closed connection during the
     * handshake is a certificate or protocol mismatch.
     *
     * @throws ImapFailure  always — the return type is `never`
     */
    private function explainDeadStream(string $doing): never
    {
        $meta = stream_get_meta_data($this->stream);

        // Checked FIRST. A stream that timed out may also report EOF, and
        // reporting the close would send somebody hunting for a fault at the
        // far end when the truth is simply that it went quiet.
        if ($meta['timed_out'] ?? false) {
            throw ImapFailure::timedOut($doing);
        }

        throw ImapFailure::misbehaved('The mail server closed the connection while '.$doing.'.');
    }

    /** @throws ImapFailure */
    private function assertTimeLeft(string $doing): void
    {
        if (microtime(true) >= $this->deadline) {
            throw ImapFailure::timedOut($doing);
        }
    }

    /** @throws ImapFailure */
    private function spend(int $bytes): void
    {
        $this->bytesRead += $bytes;

        if ($this->bytesRead > self::MAX_CONVERSATION_BYTES) {
            throw ImapFailure::misbehaved(sprintf(
                'The mail server sent more than %d bytes in one conversation, which is far more '
                .'than reading a mailbox needs.',
                self::MAX_CONVERSATION_BYTES,
            ));
        }
    }
}
