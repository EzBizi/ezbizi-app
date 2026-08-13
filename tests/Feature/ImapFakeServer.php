<?php

namespace Tests\Feature;

use RuntimeException;

/**
 * A real IMAP server, badly behaved on request — in its own process, on a real
 * socket, with a real certificate.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHY THIS IS NOT A MOCK.
 *
 *  The three things worth proving about {@see \App\Support\Email\ImapSocket}
 *  are all properties of the operating system's TLS stack, and a
 *  double cannot have them:
 *
 *    - that the socket went to the ADDRESS and not to the NAME
 *    - that OpenSSL checked the certificate against the NAME
 *    - that a silent peer is dropped rather than waited on
 *
 *  A mocked stream would assert that we PASSED the right strings,
 *  which is a test of the code's opinion of itself. This connects,
 *  handshakes, and would fail if the two variables were swapped —
 *  which is the mistake the whole design exists to make impossible.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── The address/name trick, which is the point ───────────────────
 *
 * The server binds 127.0.0.2 and its certificate names `localhost`.
 *
 * `localhost` resolves — to 127.0.0.1 — where NOTHING IS LISTENING. So a
 * transport that hands the hostname to the stream layer connects to 127.0.0.1
 * and is refused, and a transport that connects to the approved address reaches
 * the server on 127.0.0.2 and then still has to satisfy a certificate for
 * `localhost`. That is DNS rebinding modelled without needing to control DNS:
 * the name resolves to the wrong place on purpose.
 *
 * @see ImapTransportTest
 */
final class ImapFakeServer
{
    /** Where the server actually listens — and never where the name resolves. */
    public const ADDRESS = '127.0.0.2';

    /** What the certificate is issued for. Resolves to 127.0.0.1, which is empty. */
    public const HOSTNAME = 'localhost';

    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $directory;

    public readonly int $port;

    /**
     * @param  string  $mode  one of the behaviours in {@see script}
     * @param  string[]  $extraCapabilities  added to the pre-TLS CAPABILITY line
     */
    public function __construct(
        private readonly string $mode = 'polite',
        private readonly array $extraCapabilities = ['STARTTLS'],
        private readonly string $certificateName = self::HOSTNAME,
    ) {
        $this->directory = sys_get_temp_dir().'/ezbizi-imap-'.bin2hex(random_bytes(6));

        mkdir($this->directory, 0700, recursive: true);

        $this->port = $this->freePort();

        $this->writeCertificate();
        $this->launch();
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** The self-signed certificate, so the test can trust this one server only. */
    public function caFile(): string
    {
        return $this->directory.'/cert.pem';
    }

    /**
     * Everything the SMTP modes were sent, verbatim.
     *
     * Recorded so a test can assert about the BYTES that went out rather than
     * about the client's opinion of them — which is the only way to catch
     * dot-stuffing, where a wrong answer is a message the far end silently
     * truncates.
     */
    public function received(): string
    {
        $path = $this->directory.'/received.txt';

        /*
         * Waited for rather than read straight away. The server writes its log
         * once the connection ends, and the client returns the moment it has
         * sent QUIT — so reading immediately is a race that resolves as "the
         * server received nothing", which every assertStringNotContains would
         * then pass vacuously. Bounded, so a genuinely silent server fails the
         * test rather than hanging it.
         */
        $until = microtime(true) + 5;

        while (microtime(true) < $until && ! is_file($path)) {
            usleep(20_000);
        }

        return (string) @file_get_contents($path);
    }

    /**
     * A certificate for `$this->certificateName`, with a SAN — because OpenSSL
     * has matched on the subjectAltName rather than the common name for years,
     * and a CN-only certificate would test a code path no real mail server uses.
     */
    private function writeCertificate(): void
    {
        $config = $this->directory.'/openssl.cnf';

        file_put_contents($config, implode("\n", [
            '[req]', 'distinguished_name = dn', 'x509_extensions = ext', 'prompt = no',
            '[dn]', 'CN = '.$this->certificateName,
            '[ext]', 'subjectAltName = DNS:'.$this->certificateName,
            'basicConstraints = CA:FALSE', '',
        ]));

        $settings = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048];

        $key = openssl_pkey_new($settings);

        if ($key === false) {
            throw new RuntimeException('Could not generate a test key: '.openssl_error_string());
        }

        $csr         = openssl_csr_new(['commonName' => $this->certificateName], $key, $settings);
        $certificate = openssl_csr_sign($csr, null, $key, days: 1, options: $settings + ['x509_extensions' => 'ext']);

        openssl_x509_export($certificate, $publicPart);
        openssl_pkey_export($key, $privatePart, null, $settings);

        file_put_contents($this->caFile(), $publicPart);

        // The server wants both halves in one file; the client is given only the
        // public half, as a certificate authority of exactly one certificate.
        file_put_contents($this->directory.'/server.pem', $publicPart.$privatePart);
    }

    private function launch(): void
    {
        $script = $this->directory.'/server.php';

        file_put_contents($script, $this->script());

        $this->process = proc_open(
            [PHP_BINARY, $script, self::ADDRESS, (string) $this->port, $this->directory.'/server.pem', $this->mode, implode(',', $this->extraCapabilities)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
        );

        if (! is_resource($this->process)) {
            throw new RuntimeException('Could not start the fake IMAP server.');
        }

        // Wait for it to say it is listening rather than sleeping a guessed
        // interval — a fixed sleep is how a suite becomes intermittent on a
        // loaded machine.
        stream_set_blocking($this->pipes[1], false);

        $waitedUntil = microtime(true) + 10;

        while (microtime(true) < $waitedUntil) {
            if (str_contains((string) fgets($this->pipes[1]), 'listening')) {
                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException('The fake IMAP server never came up: '.stream_get_contents($this->pipes[2]));
    }

    private function freePort(): int
    {
        $probe = stream_socket_server('tcp://'.self::ADDRESS.':0', $errno, $error);

        if ($probe === false) {
            throw new RuntimeException('Could not bind '.self::ADDRESS.': '.$error);
        }

        $name = stream_socket_get_name($probe, false);

        fclose($probe);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    /**
     * The server, as a standalone script.
     *
     * Written out rather than imported so that it runs in a process with no
     * Laravel in it: the point is a socket that behaves badly, and anything
     * sharing this process could not both block on `accept()` and be connected
     * to from the test.
     */
    private function script(): string
    {
        return <<<'PHP'
<?php
[$self, $address, $port, $pem, $mode, $capabilities] = $argv;

$context = stream_context_create(['ssl' => [
    'local_cert' => $pem,
    'allow_self_signed' => true,
    'verify_peer' => false,
]]);

$server = stream_socket_server("tcp://$address:$port", $errno, $error,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if (! $server) {
    fwrite(STDERR, "bind failed: $error\n");
    exit(1);
}

fwrite(STDOUT, "listening\n");

/*
 * A canned message whose only body part is hostile HTML — a script tag and an
 * image pointed at the EC2 metadata endpoint. HTML-only on purpose: a message
 * offering a plain alternative would be answered with the plain part, and the
 * end-to-end test would then prove nothing about sanitising.
 */
$html = '<p>Hello <b>there</b>.</p>'
    . '<script>alert(document.cookie)</script>'
    . '<img src="http://169.254.169.254/latest/meta-data/iam/security-credentials/" alt="tracker">'
    . '<p>Regards</p>';

$structure = '("TEXT" "HTML" ("CHARSET" "utf-8") NIL NIL "7BIT" ' . strlen($html) . ' 4)';

$envelope = '("Mon, 3 Aug 2026 09:14:00 +0100" "Quarterly invoice" '
    . '(("Ada Lovelace" NIL "ada" "example.com")) '
    . '(("Ada Lovelace" NIL "ada" "example.com")) '
    . '(("Ada Lovelace" NIL "ada" "example.com")) '
    . '((NIL NIL "info" "ezbizi.test")) NIL NIL NIL "<msg-1@example.com>")';

while (true) {
    $client = @stream_socket_accept($server, 30);

    if (! $client) {
        continue;
    }

    // A server that accepts the socket and then says absolutely nothing. The
    // shape the task names: it must not hold the request open.
    if ($mode === 'stall') {
        // Long enough to outlast the production read timeout, short enough that
        // a regression fails instead of hanging the suite.
        sleep(25);
        fclose($client);
        continue;
    }

    /*
     * The slow loris. It greets normally and then answers the first command one
     * byte at a time, for ever, never sending a newline.
     *
     * This is the case a per-read timeout CANNOT catch: every single read
     * succeeds well inside it, so the clock is reset for ever while a worker is
     * held. Only the whole-conversation deadline stops it, which is why this
     * mode exists — a mutation run showed the stall test above passing with the
     * deadline deleted, because the per-read timeout was covering for it.
     *
     * It gives up after twenty seconds so that a regression fails rather than
     * hangs the suite.
     */
    if ($mode === 'dribble') {
        fwrite($client, "* OK fake IMAP ready\r\n");
        fgets($client);

        $until = microtime(true) + 20;

        while (microtime(true) < $until && @fwrite($client, '.') !== false) {
            usleep(200_000);
        }

        fclose($client);
        continue;
    }

    stream_set_timeout($client, 20);

    /*
     * SMTP submission, for the reply path. A separate conversation because it
     * is a separate protocol — but over the SAME socket class, so the address
     * pin, the certificate check, the timeouts and the cleartext refusal are
     * the ones already proven above rather than a second implementation.
     */
    if ($mode === 'smtp' || $mode === 'smtp-no-tls') {
        $offersTls = $mode === 'smtp';
        $secured = false;
        $log = '';

        fwrite($client, "220 fake ESMTP ready\r\n");

        while (($line = fgets($client)) !== false) {
            $log .= $line;
            $verb = strtoupper(substr(trim($line), 0, 4));

            if ($verb === 'EHLO') {
                $tls = ($offersTls && ! $secured) ? "250-STARTTLS\r\n" : '';
                fwrite($client, "250-fake greets you\r\n{$tls}250-AUTH LOGIN PLAIN\r\n250 OK\r\n");
                continue;
            }

            if ($verb === 'STAR') {
                fwrite($client, "220 go ahead\r\n");

                if (! @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                    break;
                }

                $secured = true;
                continue;
            }

            if ($verb === 'AUTH') {
                fwrite($client, "334 VXNlcm5hbWU6\r\n");
                $log .= (string) fgets($client);
                fwrite($client, "334 UGFzc3dvcmQ6\r\n");
                $log .= (string) fgets($client);
                fwrite($client, "235 authenticated\r\n");
                continue;
            }

            if ($verb === 'MAIL' || $verb === 'RCPT') {
                fwrite($client, "250 OK\r\n");
                continue;
            }

            if ($verb === 'DATA') {
                fwrite($client, "354 send it\r\n");

                // Read until the lone dot, recording every byte exactly as sent
                // so the test can check the transparency rule.
                while (($body = fgets($client)) !== false) {
                    if (rtrim($body, "\r\n") === '.') {
                        break;
                    }

                    $log .= $body;
                }

                fwrite($client, "250 queued\r\n");
                continue;
            }

            if ($verb === 'QUIT') {
                fwrite($client, "221 bye\r\n");
                break;
            }

            fwrite($client, "250 OK\r\n");
        }

        /*
         * Written aside and RENAMED, never written in place.
         *
         * `file_put_contents` creates the file and then fills it, so the
         * waiting test saw `is_file()` go true on an empty file and read it
         * before a byte had landed. That is why this suite went red roughly one
         * run in three, always on the FIRST assertion, always looking like the
         * server had received nothing.
         *
         * `rename()` within one directory is atomic on every filesystem this
         * runs on: the final name does not exist until the whole log does. The
         * reader's existence check then means what it has always claimed to
         * mean.
         */
        $done = dirname($pem).'/received.txt';

        file_put_contents($done.'.partial', $log);
        rename($done.'.partial', $done);
        fclose($client);
        continue;
    }

    fwrite($client, "* OK fake IMAP ready\r\n");

    $secured = false;

    while (($line = fgets($client)) !== false) {
        $tag = strtok(trim($line), ' ');
        $command = strtoupper((string) strtok(' '));

        if ($command === 'CAPABILITY') {
            $extra = $secured ? '' : ($capabilities === '' ? '' : ' ' . str_replace(',', ' ', $capabilities));
            fwrite($client, "* CAPABILITY IMAP4rev1$extra\r\n$tag OK done\r\n");
            continue;
        }

        if ($command === 'STARTTLS') {
            fwrite($client, "$tag OK begin TLS\r\n");

            if (! @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                // The client refused our certificate. That is a pass for the
                // test that wants it refused; just drop the connection.
                fclose($client);
                continue 2;
            }

            $secured = true;
            continue;
        }

        if ($command === 'LOGIN') {
            // 'reject' proves the credential path; every other mode signs in.
            if ($mode === 'reject') {
                fwrite($client, "$tag NO [AUTHENTICATIONFAILED] wrong\r\n");
                continue;
            }

            fwrite($client, "$tag OK signed in\r\n");
            continue;
        }

        if ($command === 'EXAMINE') {
            fwrite($client, "* 1 EXISTS\r\n* OK [UIDVALIDITY 4242] valid\r\n$tag OK [READ-ONLY] done\r\n");
            continue;
        }

        if ($command === 'UID' || $command === 'FETCH') {
            // A literal announcing two gigabytes. Nothing may allocate for it.
            if ($mode === 'huge-literal') {
                fwrite($client, "* 1 FETCH (UID 1 BODY[1] {2147483647}\r\n");
                $chunk = str_repeat('A', 8192) . "\r\n";
                while (@fwrite($client, $chunk) !== false) {
                    // Keep pushing, so a client that trusted the number would
                    // actually be fed until it fell over.
                }
                fclose($client);
                continue 2;
            }

            // A list that opens a bracket and never closes it, then goes quiet.
            // "Read on until it closes" would be an unbounded loop here.
            if ($mode === 'unclosed-bracket') {
                fwrite($client, "* 1 FETCH (UID 1 FLAGS (\\Seen\r\n$tag OK done\r\n");
                continue;
            }

            // A line that never ends: bytes for ever, and no newline.
            if ($mode === 'endless-line') {
                fwrite($client, '* 1 FETCH (UID 1 ');
                while (@fwrite($client, str_repeat('B', 8192)) !== false) {
                }
                fclose($client);
                continue 2;
            }

            if (str_contains($line, 'BODY.PEEK[1]')) {
                fwrite($client, "* 1 FETCH (UID 1 BODY[1]<0> {" . strlen($html) . "}\r\n$html)\r\n$tag OK done\r\n");
                continue;
            }

            if (str_contains($line, 'HEADER.FIELDS')) {
                $headers = "Message-ID: <msg-1@example.com>\r\nReferences: <older@example.com>\r\n\r\n";
                fwrite($client, "* 1 FETCH (UID 1 BODY[HEADER.FIELDS (MESSAGE-ID REFERENCES)] {" . strlen($headers) . "}\r\n$headers)\r\n$tag OK done\r\n");
                continue;
            }

            if (str_contains(strtoupper($line), 'SEARCH')) {
                fwrite($client, "* SEARCH 1\r\n$tag OK done\r\n");
                continue;
            }

            fwrite($client, "* 1 FETCH (UID 1 FLAGS () ENVELOPE $envelope BODYSTRUCTURE $structure)\r\n$tag OK done\r\n");
            continue;
        }

        if ($command === 'LOGOUT') {
            fwrite($client, "* BYE\r\n$tag OK done\r\n");
            break;
        }

        fwrite($client, "$tag OK done\r\n");
    }

    fclose($client);
}
PHP;
    }
}
