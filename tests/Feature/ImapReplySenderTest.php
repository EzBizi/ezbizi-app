<?php

namespace Tests\Feature;

use App\Support\Email\ApprovedHost;
use App\Support\Email\ImapFailure;
use App\Support\Email\ImapReplySender;
use Tests\TestCase;

/**
 * Sending the reply — SMTP submission, over the same pinned socket.
 *
 * ── Why this is a separate file from the reading tests ───────────
 *
 * Because the socket is shared and the CONVERSATION is not.
 * {@see ImapTransportTest} proves the things that live in
 * {@see \App\Support\Email\ImapSocket} — the address pin, the certificate
 * check, the two clocks, the cleartext refusal — and those are not reproved
 * here, because {@see ImapReplySender} opens the same class and would be a
 * second implementation if it did not.
 *
 * What IS only testable here is the SMTP exchange: that the greeting, EHLO,
 * STARTTLS, AUTH, envelope and DATA happen in the right order, that multi-line
 * replies are read whole, and — the one with teeth — that the body is
 * dot-stuffed. A wrong answer to that last one is not an error anybody sees: it
 * is a customer receiving a reply that stops halfway, while the person who sent
 * it watches it succeed.
 */
class ImapReplySenderTest extends TestCase
{
    private ?ImapFakeServer $server = null;

    private string|false $previousCertFile = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousCertFile = getenv('SSL_CERT_FILE');
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;

        if ($this->previousCertFile === false) {
            putenv('SSL_CERT_FILE');
        } else {
            putenv('SSL_CERT_FILE='.$this->previousCertFile);
        }

        parent::tearDown();
    }

    private function serve(string $mode = 'smtp'): ApprovedHost
    {
        $this->server = new ImapFakeServer($mode);

        putenv('SSL_CERT_FILE='.$this->server->caFile());

        return new ApprovedHost(
            host: ImapFakeServer::HOSTNAME,
            address: ImapFakeServer::ADDRESS,
            port: $this->server->port,
            addresses: [ImapFakeServer::ADDRESS],
        );
    }

    private function send(ApprovedHost $approved, string $message): void
    {
        (new ImapReplySender)->send(
            approved: $approved,
            encryption: 'starttls',
            username: 'info@ezbizi.test',
            password: 'a-secret',
            from: 'Info <info@ezbizi.test>',
            to: 'Ada Lovelace <ada@example.com>',
            message: $message,
        );
    }

    /**
     * The whole exchange completes, and the message arrives as sent.
     */
    public function test_a_reply_goes_out_over_an_encrypted_submission(): void
    {
        $approved = $this->serve();

        $this->send($approved, "From: Info <info@ezbizi.test>\r\nSubject: Re: Invoice\r\n\r\nThanks, that is all sorted.");

        $received = $this->server->received();

        // The envelope is the ADDRESS, never the display name — SMTP has no
        // idea what to do with `Info <...>` in a MAIL FROM.
        $this->assertStringContainsString('MAIL FROM:<info@ezbizi.test>', $received);
        $this->assertStringContainsString('RCPT TO:<ada@example.com>', $received);
        $this->assertStringContainsString('Thanks, that is all sorted.', $received);

        // AUTH happened, and the password went base64 — inside TLS, which
        // ImapSocket::assertEncrypted enforced before this point.
        $this->assertStringContainsString(base64_encode('a-secret'), $received);
    }

    /**
     * A line beginning with a dot is doubled, or the message is truncated.
     *
     * ═══════════════════════════════════════════════════════════════
     *  RFC 5321'S TRANSPARENCY RULE, AND WHY IT IS TESTED ON BYTES.
     *
     *  Inside DATA, a line consisting of a single `.` ends the
     *  message. So a body line that STARTS with a dot must be sent
     *  with a second one, which the receiving server removes.
     *
     *  Get it wrong and a reply whose paragraph begins "..." is
     *  delivered truncated at that point. Nothing errors. The sender
     *  sees a message that went, the customer receives half of one,
     *  and nobody finds out until it matters. So the assertion is
     *  about the bytes the server actually received, not about what
     *  the client believes it sent.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_a_body_line_starting_with_a_dot_is_not_treated_as_the_end(): void
    {
        $approved = $this->serve();

        $body = "Subject: Re: Quote\r\n\r\nHere is the position.\r\n"
            ."...and the rest of it\r\n"
            .".hidden line\r\n"
            ."Regards";

        $this->send($approved, $body);

        $received = $this->server->received();

        // Everything after the dotted line survived the journey, which it
        // cannot do if the dot ended the message.
        $this->assertStringContainsString('and the rest of it', $received);
        $this->assertStringContainsString('hidden line', $received);
        $this->assertStringContainsString('Regards', $received);

        // And it went out doubled, which is what the far end un-doubles.
        $this->assertStringContainsString("\r\n....and the rest of it\r\n", $received);
        $this->assertStringContainsString("\r\n..hidden line\r\n", $received);
    }

    /**
     * A submission server that will not encrypt gets no password.
     *
     * The same refusal as the reading side, and it names the SENDING port —
     * telling somebody configuring replies to "try 993" would send them to the
     * reading port, where nothing they are setting up listens.
     */
    public function test_a_submission_server_that_will_not_encrypt_is_refused(): void
    {
        $approved = $this->serve('smtp-no-tls');

        try {
            $this->send($approved, "Subject: x\r\n\r\nhello");

            $this->fail('A reply was sent over an unencrypted submission connection.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::UNREACHABLE, $e->kind);
            $this->assertStringContainsString('readable text', $e->getMessage());
            $this->assertStringContainsString('465', $e->getMessage());
        }

        $received = $this->server->received();

        /*
         * Checked non-empty FIRST, so the refusal below is not vacuous. A
         * `assertStringNotContainsString` against an empty log passes whatever
         * the code does — which is exactly how a security assertion comes to
         * prove nothing. The server DID hear from us; what it never heard is
         * the password.
         */
        $this->assertStringContainsString('EHLO', $received, 'The sender never reached the server, so this proves nothing.');

        // The thing being protected: the credential never went out.
        $this->assertStringNotContainsString(base64_encode('a-secret'), $received);
        $this->assertStringNotContainsString('a-secret', $received);
    }

    /**
     * A credential containing a line break cannot become a second SMTP command.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THIS TEST EXISTS BECAUSE A MUTATION SURVIVED.
     *
     *  The guard in {@see ImapReplySender::authenticate} could be
     *  deleted with the whole suite still green: the IMAP side had a
     *  test for its own version of this and the SMTP side had none.
     *  Two protocols, the same hole, one test.
     *
     *  An SMTP command is CRLF-terminated, so a stored password of
     *  `secret\r\nRCPT TO:<evil@example.com>` adds a recipient to the
     *  business's own reply — a silent BCC of customer correspondence
     *  to whoever set the mailbox up.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_a_credential_containing_a_line_break_is_refused(): void
    {
        $approved = $this->serve();

        try {
            (new ImapReplySender)->send(
                approved: $approved,
                encryption: 'starttls',
                username: 'info@ezbizi.test',
                password: "a-secret\r\nRCPT TO:<evil@example.com>",
                from: 'info@ezbizi.test',
                to: 'ada@example.com',
                message: "Subject: x\r\n\r\nhello",
            );

            $this->fail('A password containing CRLF was sent to the submission server.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::UNREACHABLE, $e->kind);
            $this->assertStringContainsString('line break', $e->getMessage());
        }

        $received = $this->server->received();

        $this->assertStringContainsString('EHLO', $received, 'The sender never reached the server, so this proves nothing.');
        $this->assertStringNotContainsString('evil@example.com', $received);
    }

    /**
     * An enormous reply is refused rather than uploaded.
     *
     * A reply is something a person typed into a box. The cap exists so a bug
     * elsewhere cannot turn one request into an unbounded upload to somebody
     * else's mail server.
     */
    public function test_an_enormous_reply_is_refused(): void
    {
        $approved = $this->serve();

        try {
            $this->send($approved, str_repeat('x', 2 * 1024 * 1024));

            $this->fail('A two-megabyte reply was put on the wire.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::UNREACHABLE, $e->kind);
            $this->assertStringContainsString('too long', $e->getMessage());
        }
    }
}
