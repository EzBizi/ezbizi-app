<?php

namespace Tests\Feature;

use App\Support\Email\ApprovedHost;
use App\Support\Email\BodySanitiser;
use App\Support\Email\ImapConnection;
use App\Support\Email\ImapFailure;
use App\Support\Email\ImapMessageMapper;
use App\Support\Email\ImapSocket;
use Tests\TestCase;

/**
 * The IMAP transport, against a real socket, real TLS and a server that
 * misbehaves on request.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHAT THE FIRST TEST HERE PROVES, AND WHY IT IS THE WHOLE POINT.
 *
 *  {@see ImapFakeServer} binds 127.0.0.2 and presents a certificate
 *  for `localhost`. `localhost` RESOLVES — to 127.0.0.1 — and nothing
 *  is listening there.
 *
 *  So there is exactly one way to pass: connect to the ADDRESS the
 *  guard approved and verify the certificate against the NAME the
 *  tenant typed. A transport that hands the name to the socket dials
 *  127.0.0.1 and is refused; a transport that verifies against the
 *  address is handed a certificate for `localhost` and rejects it.
 *  Both mistakes are RED, and they are the two ways every IMAP
 *  library on Packagist gets this wrong.
 *
 *  That is DNS rebinding modelled without controlling DNS: the name
 *  resolves, and it resolves to the wrong place on purpose.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Why ApprovedHost is built by hand here ───────────────────────
 *
 * These construct {@see ApprovedHost} directly rather than going through
 * {@see \App\Support\Email\RemoteHostGuard}, which would refuse 127.0.0.2 as
 * loopback and be right to. The division is deliberate and it is the same one
 * the guard's own docblock draws: the guard decides WHETHER a host may be
 * dialled — {@see EmailHostGuardTest} covers that — and this file decides that
 * what it approved is what actually gets dialled. Routing these through the
 * guard would make them untestable without a public address, which is how the
 * connect-side half of rule 3 comes to have no test at all.
 */
class ImapTransportTest extends TestCase
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

        /*
         * Put the trust store back. A test that left SSL_CERT_FILE pointing at
         * a one-certificate file would make every later test in the process
         * distrust the real internet, and the failures would appear somewhere
         * else entirely.
         */
        if ($this->previousCertFile === false) {
            putenv('SSL_CERT_FILE');
        } else {
            putenv('SSL_CERT_FILE='.$this->previousCertFile);
        }

        parent::tearDown();
    }

    /**
     * Start a server and trust its certificate — and nothing else's.
     */
    private function serve(string $mode = 'polite', array $capabilities = ['STARTTLS'], string $certificateName = ImapFakeServer::HOSTNAME, bool $trusted = true): ApprovedHost
    {
        $this->server = new ImapFakeServer($mode, $capabilities, $certificateName);

        /*
         * The test trusts this one certificate and nothing else, through
         * OpenSSL's own `SSL_CERT_FILE`. Deliberately NOT through a `cafile`
         * option on the socket: adding one would mean production code carrying a
         * way to nominate a trust store, which is one configuration key away
         * from being a way to nominate a trust store per mailbox.
         *
         * `$trusted: false` leaves the system store alone, so the server's
         * self-signed certificate is exactly what an attacker's would be — the
         * right name, signed by nobody.
         */
        if ($trusted) {
            putenv('SSL_CERT_FILE='.$this->server->caFile());
        }

        return new ApprovedHost(
            host: ImapFakeServer::HOSTNAME,
            address: ImapFakeServer::ADDRESS,
            port: $this->server->port,
            addresses: [ImapFakeServer::ADDRESS],
        );
    }

    // ── the address, and not the name ────────────────────────────

    /**
     * The premise this whole file rests on, asserted rather than assumed.
     *
     * If `localhost` ever stopped resolving to 127.0.0.1, or something started
     * listening there, the headline test below would pass for the wrong reason
     * and go on passing for ever. So the trap is checked before it is used.
     */
    public function test_the_name_resolves_somewhere_the_server_is_not(): void
    {
        $approved = $this->serve();

        $this->assertSame(
            '127.0.0.1',
            gethostbyname(ImapFakeServer::HOSTNAME),
            'This file assumes "localhost" resolves to 127.0.0.1 while the server binds 127.0.0.2.',
        );

        $stray = @stream_socket_client('tcp://127.0.0.1:'.$approved->port, $errno, $error, 2);

        if ($stray !== false) {
            fclose($stray);

            $this->fail('Something is listening on 127.0.0.1:'.$approved->port.', so the address pin cannot be tested.');
        }

        $this->assertNotSame(ImapFakeServer::ADDRESS, gethostbyname(ImapFakeServer::HOSTNAME));
    }

    /**
     * Connect to the approved ADDRESS, verify the typed NAME.
     *
     * Passing requires both. See the note at the top of the class.
     */
    public function test_the_socket_goes_to_the_approved_address_and_verifies_the_typed_name(): void
    {
        $approved = $this->serve();

        $imap = ImapConnection::open($approved, 'starttls', patience: 10);

        $imap->login('info@ezbizi.test', 'hunter2');

        $this->assertSame(['exists' => 1, 'uidvalidity' => 4242], $imap->examineInbox());

        $imap->logout();
    }

    /**
     * A certificate for somebody else's name is refused.
     *
     * The server is at the approved address and answers perfectly; the only
     * thing wrong is whose certificate it presents. Turning verification off —
     * the usual fix for a certificate complaint — makes this test pass while
     * removing the only check that makes the address pin worth having, which is
     * why there is no setting that does it.
     */
    public function test_a_certificate_for_another_name_is_refused(): void
    {
        $approved = $this->serve(certificateName: 'someone-else.invalid');

        try {
            ImapConnection::open($approved, 'starttls', patience: 10);

            $this->fail('A certificate naming someone-else.invalid was accepted for localhost.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::UNREACHABLE, $e->kind);
            $this->assertStringContainsString('certificate', $e->getMessage());
        }
    }

    /**
     * A certificate with the RIGHT name, signed by nobody, is still refused.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THIS TEST EXISTS BECAUSE A MUTATION SURVIVED.
     *
     *  Setting `verify_peer => false` — chain validation off, name
     *  checking left on — did not fail a single test. PHP treats the
     *  two as INDEPENDENT: the hostname match kept working, so
     *  {@see test_a_certificate_for_another_name_is_refused} went on
     *  passing with the chain unverified.
     *
     *  That gap is the whole attack. Anybody can mint a certificate
     *  naming `imap.example.com`; what they cannot do is get one
     *  signed by a certificate authority. Name-matching without chain
     *  validation checks only that the attacker filled in the form
     *  correctly.
     *
     *  So this connects to a server whose certificate names exactly
     *  what the tenant typed and is signed by nobody the machine
     *  trusts, and requires a refusal.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_a_certificate_signed_by_nobody_is_refused_even_with_the_right_name(): void
    {
        $approved = $this->serve(trusted: false);

        try {
            ImapConnection::open($approved, 'starttls', patience: 10);

            $this->fail('A self-signed certificate was accepted because its name happened to match.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::UNREACHABLE, $e->kind);
            $this->assertStringContainsString('certificate', $e->getMessage());
        }
    }

    // ── cleartext, refused both ways ─────────────────────────────

    /**
     * A mailbox set to connect without encryption never opens a socket at all.
     */
    public function test_a_mailbox_set_to_cleartext_is_refused_before_anything_is_dialled(): void
    {
        $approved = new ApprovedHost('localhost', '127.0.0.2', 143, ['127.0.0.2']);

        try {
            ImapSocket::open($approved, 'none');

            $this->fail('A cleartext mailbox was allowed to connect.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::UNREACHABLE, $e->kind);
            $this->assertStringContainsString('without encryption', $e->getMessage());

            // R8: the refusal says what to do, and names the port to try.
            $this->assertStringContainsString('993', $e->getMessage());
        }
    }

    /**
     * A server that will not offer STARTTLS is refused — never downgraded.
     *
     * The password is not sent. That is the thing being protected, and the
     * failure happens in {@see ImapConnection::open}, which is before
     * {@see ImapConnection::login} exists to be called.
     */
    public function test_a_server_that_will_not_encrypt_is_refused_rather_than_downgraded(): void
    {
        $approved = $this->serve(capabilities: []);

        try {
            ImapConnection::open($approved, 'starttls', patience: 10);

            $this->fail('A server offering no encryption was accepted.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::UNREACHABLE, $e->kind);
            $this->assertStringContainsString('readable text', $e->getMessage());
            $this->assertStringContainsString('993', $e->getMessage());
        }
    }

    // ── the two denial-of-service shapes ─────────────────────────

    /**
     * A server that accepts the socket and says nothing is dropped.
     *
     * `patience: 2` rather than the real fifteen seconds — see
     * {@see ImapSocket::open}, where that argument is clamped so it can only
     * ever shorten the wait. What is being proven is that a clock exists and
     * fires, not what number is on it.
     */
    public function test_a_server_that_says_nothing_is_dropped(): void
    {
        $approved = $this->serve('stall');

        $startedAt = microtime(true);

        try {
            ImapConnection::open($approved, 'starttls', patience: 2);

            $this->fail('A silent server held the connection and was not dropped.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::TIMEOUT, $e->kind);
        }

        $this->assertLessThan(
            10,
            microtime(true) - $startedAt,
            'The silent server was waited on for far longer than the timeout allows.',
        );
    }

    /**
     * A server that dribbles one byte at a time is dropped.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THIS TEST ALSO EXISTS BECAUSE A MUTATION SURVIVED.
     *
     *  Deleting the whole-conversation deadline left
     *  {@see test_a_server_that_says_nothing_is_dropped} green,
     *  because a server saying NOTHING is caught by the per-read
     *  timeout and the deadline never had to fire. Two clocks, one
     *  test, and the test could only see one of them.
     *
     *  This is the case that tells them apart. The server sends a byte
     *  every 200ms, which resets the per-read timeout for ever while
     *  holding a PHP worker — the slow-loris shape. Only the deadline
     *  can end it, so only the deadline can make this pass.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_a_server_that_dribbles_bytes_for_ever_is_dropped(): void
    {
        $approved = $this->serve('dribble');

        $startedAt = microtime(true);

        try {
            ImapConnection::open($approved, 'starttls', patience: 3);

            $this->fail('A server dribbling bytes held the connection and was not dropped.');
        } catch (ImapFailure $e) {
            $this->assertSame(
                ImapFailure::TIMEOUT,
                $e->kind,
                'The dribbling server was ended by something other than the conversation deadline.',
            );
        }

        $this->assertLessThan(
            12,
            microtime(true) - $startedAt,
            'The dribbling server was allowed to reset the read timeout indefinitely.',
        );
    }

    /**
     * The test seam cannot be used to wait LONGER than production allows.
     *
     * ── Why this is worth fifteen seconds of suite ───────────────
     *
     * Because `patience` is a parameter that exists for the tests, and a
     * test-only parameter that can weaken a production guarantee is worse than
     * no test. {@see ImapSocket::patience} clamps it with `min()`, so this asks
     * for ten minutes and requires the socket to give up inside the real
     * {@see \App\Support\Email\RemoteHostGuard::READ_TIMEOUT_SECONDS} anyway.
     *
     * The fake server hangs up at twenty-five seconds, so if the clamp is ever
     * loosened this fails on the WRONG KIND of failure — a closed connection
     * rather than a timeout — instead of hanging the suite for ten minutes.
     */
    public function test_asking_for_more_patience_than_production_allows_does_not_get_it(): void
    {
        $approved = $this->serve('stall');

        $startedAt = microtime(true);

        try {
            ImapConnection::open($approved, 'starttls', patience: 600);

            $this->fail('A ten-minute patience was granted to a silent server.');
        } catch (ImapFailure $e) {
            $this->assertSame(
                ImapFailure::TIMEOUT,
                $e->kind,
                'The socket outlasted its own read timeout, so the patience clamp is not clamping.',
            );
        }

        $this->assertLessThan(
            20,
            microtime(true) - $startedAt,
            'A caller asked for ten minutes and was given more than the production timeout.',
        );
    }

    /**
     * A literal announcing two gigabytes is refused, and nothing is allocated.
     *
     * ── The assertion is about MEMORY, not just the exception ────
     *
     * Because an implementation that read the two gigabytes and then complained
     * would also throw. The peak-usage comparison is what distinguishes refusing
     * the ANNOUNCEMENT from surviving the DATA — and it is the difference
     * measured on the two libraries this transport replaced, one of which
     * exhausted 256 MB in seven seconds on exactly this input.
     */
    public function test_a_literal_announcing_two_gigabytes_does_not_allocate(): void
    {
        $approved = $this->serve('huge-literal');

        $imap = ImapConnection::open($approved, 'starttls', patience: 10);

        $imap->login('info@ezbizi.test', 'hunter2');
        $imap->examineInbox();

        $peakBefore = memory_get_peak_usage(true);

        try {
            $imap->fetchHeaders('1:1', byUid: false);

            $this->fail('A two-gigabyte literal was accepted.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::MISBEHAVED, $e->kind);
            $this->assertStringContainsString('2147483647', $e->getMessage());

            /*
             * The ANNOUNCEMENT refusal specifically, and this exactness is the
             * result of a mutation that survived. Deleting the size check in
             * `readLiteral` left this test green, because
             * {@see ImapSocket::bytes} refuses the same number a moment later
             * and its message also contains it. That is real defence in depth
             * and it stays — but a test that cannot tell the first lock from the
             * second is not testing the first lock. This sentence is only
             * produced before anything is read.
             */
            $this->assertStringContainsString('the next part of this message is', $e->getMessage());
        }

        $this->assertLessThan(
            8 * 1024 * 1024,
            memory_get_peak_usage(true) - $peakBefore,
            'Refusing the literal allocated megabytes, so the size was not refused on announcement.',
        );
    }

    /**
     * A line with no end is dropped at the cap, not read for ever.
     */
    public function test_a_line_that_never_ends_is_refused(): void
    {
        $approved = $this->serve('endless-line');

        $imap = ImapConnection::open($approved, 'starttls', patience: 10);

        $imap->login('info@ezbizi.test', 'hunter2');
        $imap->examineInbox();

        try {
            $imap->fetchHeaders('1:1', byUid: false);

            $this->fail('An endless line was read to the end, which never comes.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::MISBEHAVED, $e->kind);
            $this->assertStringContainsString('without ending it', $e->getMessage());
        }
    }

    // ── what comes back ──────────────────────────────────────────

    /**
     * The body reaches the caller as BLOCKS, sanitised — end to end.
     *
     * The fake server's only body part is hostile HTML: a `<script>` and an
     * `<img>` pointed at the EC2 metadata endpoint. What comes back must contain
     * neither, and must SAY that a picture was refused rather than silently
     * losing it.
     */
    public function test_a_body_comes_back_sanitised(): void
    {
        $approved = $this->serve();

        $imap = ImapConnection::open($approved, 'starttls', patience: 10);

        $imap->login('info@ezbizi.test', 'hunter2');
        $imap->examineInbox();

        $row = $imap->fetchHeaders('1', byUid: true)[1];

        $mapper = new ImapMessageMapper(new BodySanitiser);
        $part   = $mapper->textPart($row['bodystructure']);

        $detail = $mapper->detail(
            $mapper->summary(1, $row),
            $row,
            $imap->bodyPart(1, $part['part']),
            $part,
        );

        $imap->logout();

        $this->assertSame('Quarterly invoice', $detail->summary->subject);
        $this->assertSame('ada@example.com', $detail->summary->fromAddress);

        $published = json_encode($detail->toArray());

        $this->assertStringNotContainsString('<script', $published);
        $this->assertStringNotContainsString('alert(document.cookie)', $published);
        $this->assertStringNotContainsString('169.254.169.254', $published);

        /*
         * Read out of the blocks rather than off the JSON, because the JSON is
         * where `<b>there</b>` correctly becomes three spans — plain, strong,
         * plain — and searching the encoded form for the sentence would fail on
         * the sanitiser doing its job. The words are what survived; asking a
         * block for its text is how the rest of the application reads them.
         */
        $this->assertSame('Hello there.', $detail->blocks[0]->text());
        $this->assertSame('Regards', $detail->blocks[2]->text());

        $this->assertTrue($detail->imagesBlocked, 'The metadata-endpoint image was not reported as blocked.');
        $this->assertSame('blocked_image', $detail->blocks[1]->kind->value);
    }

    /**
     * A password containing a line break cannot become a second IMAP command.
     *
     * ── The same hole ReplyComposer guards, in another protocol ──
     *
     * An IMAP command is one CRLF-terminated line. A stored password of
     * `hunter2"\r\nE9 DELETE INBOX` would end the LOGIN and start a command the
     * server executes with the credentials it just accepted — and the password
     * field is typed into a form and stored, so this is a value somebody
     * controls, not a theoretical byte sequence.
     *
     * It is REFUSED rather than escaped, because a bare CR has no valid
     * representation inside an IMAP quoted string: stripping it would sign
     * somebody in with a password that is not the one they saved.
     */
    public function test_a_credential_containing_a_line_break_is_refused(): void
    {
        $approved = $this->serve();

        $imap = ImapConnection::open($approved, 'starttls', patience: 10);

        try {
            $imap->login('info@ezbizi.test', "hunter2\"\r\nE9 DELETE INBOX");

            $this->fail('A password containing CRLF was sent to the mail server.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::UNREACHABLE, $e->kind);
            $this->assertStringContainsString('line break', $e->getMessage());
        }
    }

    /**
     * A response that opens a bracket and never closes it is refused.
     *
     * The tempting parser reads on into the next line until the brackets
     * balance, which is an unbounded loop with a friendly name — a server can
     * open a hundred thousand parentheses as cheaply as one.
     */
    public function test_a_response_that_never_closes_its_brackets_is_refused(): void
    {
        $approved = $this->serve('unclosed-bracket');

        $imap = ImapConnection::open($approved, 'starttls', patience: 5);

        $imap->login('info@ezbizi.test', 'hunter2');

        try {
            $imap->fetchHeaders('1:1', byUid: false);

            $this->fail('An unbalanced response was parsed as though it were valid.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::MISBEHAVED, $e->kind);
            $this->assertStringContainsString('never closed its brackets', $e->getMessage());
        }
    }

    /**
     * A refused password is told apart from a broken server.
     *
     * They are different sentences to a person and different HTTP statuses, and
     * folding them together is how somebody is sent to re-enter a password that
     * was always correct.
     */
    public function test_a_refused_password_is_reported_as_a_refusal(): void
    {
        $approved = $this->serve('reject');

        $imap = ImapConnection::open($approved, 'starttls', patience: 10);

        try {
            $imap->login('info@ezbizi.test', 'wrong');

            $this->fail('A rejected sign-in was treated as a success.');
        } catch (ImapFailure $e) {
            $this->assertSame(ImapFailure::REJECTED, $e->kind);
        }
    }
}
