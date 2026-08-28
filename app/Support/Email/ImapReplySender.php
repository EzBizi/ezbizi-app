<?php

namespace App\Support\Email;

/**
 * Sending the reply — SMTP submission, over the same pinned socket.
 *
 * ── Why an SMTP client is in a file called Imap-something ────────
 *
 * Because "Imap" here names the PROVIDER, not the protocol on the wire.
 * {@see MailboxProvider::Imap} is the whole of "a mailbox on somebody's own mail
 * server", and such a mailbox is read over IMAP and replied to over SMTP — two
 * protocols, one provider, one set of settings, one password. Splitting the
 * reply into a class named for its protocol would have hidden that the two
 * halves share a credential and a threat model.
 *
 * The threat model is the point. {@see ImapMailboxReader} already documented
 * that the SMTP host is *"a second field the tenant types, and it is the same
 * threat as the IMAP one"* — so the reply goes through the SAME
 * {@see RemoteHostGuard}, opens the SAME {@see ImapSocket}, and is therefore
 * pinned the same way: the socket goes to the approved address, the certificate
 * is checked against the typed name, every read is bounded and cleartext is
 * refused. None of that is written twice.
 *
 * ── What it does not do ──────────────────────────────────────────
 *
 * It does not compose the message. {@see ReplyComposer} does, and it is
 * deliberately given no way to put the individual who typed the reply on the
 * From line — *"info replies as info"*. This class receives finished bytes and
 * puts them on a socket.
 */
final class ImapReplySender
{
    /**
     * The longest reply this will put on the wire.
     *
     * A reply is something a person typed into a box. A megabyte of it is
     * already far past anything anybody writes, and the cap exists so that a
     * bug elsewhere cannot turn one request into an unbounded upload to
     * somebody else's mail server.
     */
    private const MAX_MESSAGE_BYTES = 1_048_576;

    /**
     * @param  list<string>  $to  envelope recipients — one RCPT TO each. A reply
     *                              passes one; a composed message may pass several.
     * @param  string  $message  the finished RFC 5322 message from {@see ReplyComposer}
     *
     * @throws ImapFailure
     */
    public function send(
        ApprovedHost $approved,
        string $encryption,
        string $username,
        string $password,
        string $from,
        array $to,
        string $message,
    ): void {
        if (strlen($message) > self::MAX_MESSAGE_BYTES) {
            throw ImapFailure::unreachable(
                'That reply is too long to send. Shorten it, or send the detail as an attachment '
                .'from your own mail program.'
            );
        }

        $socket = ImapSocket::open($approved, $encryption);

        try {
            $this->expect($socket, 220, 'greeting us');

            $capabilities = $this->hello($socket, $approved->host);

            if (! $socket->isEncrypted()) {
                if (! $this->offersStartTls($capabilities)) {
                    throw ImapFailure::unreachable(
                        'That sending server does not offer an encrypted connection on this port, '
                        .'so EzBizi stopped rather than send the password as readable text. Try '
                        .'port 465, or ask whoever runs the server to turn on STARTTLS.'
                    );
                }

                $this->say($socket, 'STARTTLS');
                $this->expect($socket, 220, 'agreeing to encrypt');

                $socket->secure();

                // Said again, because everything the server claimed before this
                // point was claimed in the clear. Same rule as IMAP's
                // post-STARTTLS CAPABILITY, and the same downgrade if skipped.
                $this->hello($socket, $approved->host);
            }

            /*
             * A redundant lock, and deliberately kept as one.
             *
             * Nothing can reach here unencrypted today: the branch above either
             * refuses a server that will not offer STARTTLS or calls
             * {@see ImapSocket::secure}, which throws rather than returning
             * without encryption. A mutation run confirmed it — deleting this
             * line failed no test, because the refusal above always fires first.
             *
             * It stays because it is the assertion that survives an edit to that
             * branch. What must never happen is a password crossing the network
             * in the clear, and the cheapest way to keep that true through
             * changes nobody has thought of yet is to state it here as well.
             */
            $socket->assertEncrypted();

            $this->authenticate($socket, $username, $password);

            $this->say($socket, 'MAIL FROM:<'.$this->addressOnly($from).'>');
            $this->expect($socket, 250, 'accepting the sender');

            /*
             * One RCPT TO per recipient, and every one of them checked.
             *
             * The envelope is not the To: header — the header is display, this
             * is delivery. Sending the header with three addresses on it and
             * issuing one RCPT delivers to one person while showing the reader
             * three, which is the silent half-send that looks like success on
             * both ends.
             */
            foreach ($to as $recipient) {
                $this->say($socket, 'RCPT TO:<'.$this->addressOnly($recipient).'>');
                $this->expect($socket, 250, 'accepting the recipient');
            }

            $this->say($socket, 'DATA');
            $this->expect($socket, 354, 'asking for the message');

            $socket->write($this->dotStuffed($message)."\r\n.\r\n");
            $this->expect($socket, 250, 'accepting the message');

            $this->say($socket, 'QUIT');
        } finally {
            // The socket closes whether the send worked or threw. A failure
            // partway through DATA otherwise leaves a connection open at the
            // far end until their timeout, and a busy server counts those.
            $socket->close();
        }
    }

    /**
     * EHLO, falling back to HELO for a server too old to know it.
     *
     * @return string[] the extension lines, upper-cased
     *
     * @throws ImapFailure
     */
    private function hello(ImapSocket $socket, string $host): array
    {
        /*
         * The name we announce ourselves by is the SERVER's own name, not this
         * machine's. It is cosmetic to the protocol — no server verifies it —
         * and sending our internal hostname would tell every mail server a
         * tenant configures what our instances are called.
         */
        $this->say($socket, 'EHLO '.$host);

        [$code, $lines] = $this->response($socket);

        if ($code === 250) {
            return array_map(strtoupper(...), $lines);
        }

        $this->say($socket, 'HELO '.$host);
        $this->expect($socket, 250, 'accepting our greeting');

        return [];
    }

    /**
     * @param  string[]  $capabilities
     */
    private function offersStartTls(array $capabilities): bool
    {
        foreach ($capabilities as $line) {
            if (str_contains($line, 'STARTTLS')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sign in — AUTH PLAIN where offered, AUTH LOGIN otherwise.
     *
     * Both send the password base64-encoded, which is NOT encryption and is not
     * treated as any: {@see ImapSocket::assertEncrypted} has already run above,
     * so this only ever happens inside TLS. Base64 here is a transport
     * convenience for bytes SMTP could not otherwise carry, and nothing more.
     *
     * @throws ImapFailure
     */
    private function authenticate(ImapSocket $socket, string $username, string $password): void
    {
        $socket->assertEncrypted();

        /*
         * The same refusal {@see ImapConnection::quoted} makes, for the same
         * reason in a different protocol: SMTP commands are CRLF-terminated, so
         * a credential containing a line break would end AUTH and begin a
         * command of the attacker's choosing. Base64 would encode the break
         * harmlessly — but the username also appears in the AUTH LOGIN exchange
         * and a stray CR has no correct meaning in either, so it is refused
         * rather than smuggled.
         */
        foreach (['user name' => $username, 'password' => $password] as $what => $value) {
            if (preg_match('/[\r\n\x00]/', $value) === 1) {
                throw ImapFailure::unreachable(sprintf(
                    'The %s saved for this mailbox contains a line break, which cannot be sent to '
                    .'a mail server. Enter it again without one.',
                    $what,
                ));
            }
        }

        $this->say($socket, 'AUTH LOGIN');

        [$code] = $this->response($socket);

        if ($code !== 334) {
            throw ImapFailure::rejected('The sending server would not accept a user name and password.');
        }

        $this->say($socket, base64_encode($username));
        $this->expect($socket, 334, 'asking for the password');

        $this->say($socket, base64_encode($password));

        [$code] = $this->response($socket);

        if ($code !== 235) {
            throw ImapFailure::rejected('The sending server refused the user name or password.');
        }
    }

    /**
     * A line beginning with a dot would end the message early.
     *
     * RFC 5321's transparency rule: inside DATA, a line that is just `.` is the
     * terminator, so any body line starting with a dot gets a second one added
     * and the far end takes it off again. Without this, a reply whose paragraph
     * happens to begin with "..." is delivered truncated at that point — and the
     * sender sees a message that looks sent.
     */
    private function dotStuffed(string $message): string
    {
        $normalised = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $message);

        return preg_replace('/(^|\r\n)\./', '$1..', $normalised) ?? $normalised;
    }

    /** @throws ImapFailure */
    private function say(ImapSocket $socket, string $command): void
    {
        $socket->write($command."\r\n");
    }

    /**
     * One SMTP reply, which may be several lines.
     *
     * `250-EXTENSION` continues, `250 EXTENSION` ends. Reading only the first
     * line is the classic bug: the remaining lines stay in the buffer and every
     * later command reads somebody else's answer, one behind, for ever.
     *
     * @return array{0: int, 1: string[]}
     *
     * @throws ImapFailure
     */
    private function response(ImapSocket $socket): array
    {
        $lines = [];
        $code  = 0;

        // Bounded like everything else here: a server answering one command with
        // endless continuation lines is the same unbounded loop in SMTP clothes.
        for ($i = 0; $i < 100; $i++) {
            $line = rtrim($socket->line(), "\r\n");

            if (! preg_match('/^(\d{3})([ -])?(.*)$/', $line, $m)) {
                throw ImapFailure::misbehaved('The sending server answered with something that is not SMTP.');
            }

            $code    = (int) $m[1];
            $lines[] = $m[3];

            if (($m[2] ?? ' ') !== '-') {
                return [$code, $lines];
            }
        }

        throw ImapFailure::misbehaved('The sending server would not stop talking.');
    }

    /** @throws ImapFailure */
    private function expect(ImapSocket $socket, int $wanted, string $doing): void
    {
        [$code, $lines] = $this->response($socket);

        if ($code === $wanted) {
            return;
        }

        /*
         * 5xx is a refusal that will not change; 4xx is "not now". Told apart
         * because the remedy differs — one means the address or the account is
         * wrong, the other means try again — and a reply that failed for a
         * temporary reason must not send somebody to check their password.
         */
        throw $code >= 500
            ? ImapFailure::rejected('The sending server refused while '.$doing.'.')
            : ImapFailure::misbehaved('The sending server was not ready while '.$doing.'.');
    }

    /** `Jenny <jenny@example.com>` is a display name; SMTP wants the address. */
    private function addressOnly(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $m) === 1) {
            return trim($m[1]);
        }

        return trim($value);
    }
}
