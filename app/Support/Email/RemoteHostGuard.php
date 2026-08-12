<?php

namespace App\Support\Email;

/**
 * Our server is about to dial a host the TENANT typed. This is what stops that
 * being a way into our own network.
 *
 * ── The attack, stated plainly ───────────────────────────────────
 *
 * An IMAP mailbox asks somebody for a hostname and a port, and then this
 * application opens a socket to it. Everything the tenant gains from that, an
 * attacker gains too — with one difference: the socket is opened from INSIDE our
 * network, by a process holding our credentials, past whatever firewall stands
 * between the internet and the instance. That is server-side request forgery,
 * and a mail-account form is a textbook way in because the host field is
 * legitimate, required, and free text.
 *
 * The prize on AWS is `169.254.169.254`. It is the EC2 instance metadata
 * endpoint: it answers over plain HTTP, with no authentication, to anything that
 * can reach it, and what it hands back includes the instance's IAM role
 * credentials. A mailbox pointed at it is an attempt to have our own server
 * fetch our own keys and hand them to whoever is reading the error message. It
 * is refused BY NAME below, and it is refused again by the link-local rule that
 * covers it, because the day somebody finds the IPv6 spelling of it the first
 * rule alone would not have been enough.
 *
 * ── Four rules, and the third is the one people skip ─────────────
 *
 *   1. THE NAME MUST RESOLVE, and what it resolves to is what is judged.
 *      `evil.example.com` with an A record of 127.0.0.1 is loopback however
 *      innocent the string looks, so the string is never what is checked.
 *
 *   2. EVERY ADDRESS IT RESOLVES TO must pass, not the first one. A name with
 *      two A records — one public, one private — passes a first-match check
 *      about half the time, which is worse than failing, because it is
 *      intermittent and looks like a network fault.
 *
 *   3. RESOLVE, THEN CONNECT TO WHAT WAS RESOLVED. Checking a name and then
 *      handing the NAME to a socket library is DNS rebinding waiting to happen:
 *      the second lookup is a fresh lookup, the attacker's server answers it
 *      differently, and the address that was validated is not the address that
 *      is dialled. {@see approve()} therefore returns the IP it approved, and
 *      the caller is required to connect to that. This is the rule that is
 *      usually written down and then not followed, because it is the only one
 *      that changes the calling code.
 *
 *   4. KNOWN PORTS ONLY, AND HARD TIMEOUTS. A host field that can name any port
 *      is a port scanner with our source address on it, and timing the refusals
 *      tells the caller what is listening even when nothing is returned. The
 *      allowlist below is the ports a mail server actually answers on; the
 *      timeout is what stops a deliberately silent host holding a worker open.
 *
 * ── Why it is not `filter_var(..., FILTER_FLAG_NO_PRIV_RANGE)` ───
 *
 * Because that flag knows about RFC 1918 and not about the rest of the list:
 * carrier-grade NAT (100.64/10), the link-local block that holds the metadata
 * endpoint, IPv6 unique-local addresses and IPv4-mapped IPv6 all pass it. The
 * ranges are therefore written out below, each with what it is.
 *
 * ── The identical guard belongs on SMTP ──────────────────────────
 *
 * Replying goes out through a host the same tenant typed, so the reply path asks
 * this same class with its own port list. There is no second copy of the ranges.
 */
final class RemoteHostGuard
{
    /** IMAP: 143 plain/STARTTLS, 993 implicit TLS. */
    public const IMAP_PORTS = [143, 993];

    /** SMTP submission: 587 STARTTLS, 465 implicit TLS. 25 is deliberately absent — see below. */
    public const SMTP_PORTS = [587, 465];

    /**
     * How long a connection may take, and how long it may then be silent.
     *
     * Seconds, and small. A mailbox test is somebody sitting in front of a form;
     * a host that has not answered in five seconds is not going to, and the
     * failure they need is "we could not reach it", quickly.
     */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const READ_TIMEOUT_SECONDS = 15;

    /**
     * Address ranges nothing tenant-supplied may reach, each with what it is.
     *
     * Written as CIDR and matched numerically rather than by string prefix: a
     * prefix test on "10." also matches 100.64.0.1, and a prefix test on "127."
     * misses 0177.0.0.1, which some resolvers still accept.
     *
     * @var array<string, string> cidr => what lives there
     */
    private const FORBIDDEN_V4 = [
        '0.0.0.0/8'          => 'this host, in the "unspecified" sense',
        '10.0.0.0/8'         => 'a private network',
        '100.64.0.0/10'      => 'carrier-grade NAT, which is somebody else\'s private network',
        '127.0.0.0/8'        => 'this server itself',
        '169.254.0.0/16'     => 'link-local, which is where the cloud metadata service lives',
        '172.16.0.0/12'      => 'a private network',
        '192.0.0.0/24'       => 'IETF protocol assignments',
        '192.0.2.0/24'       => 'documentation, not a real host',
        '192.168.0.0/16'     => 'a private network',
        '198.18.0.0/15'      => 'network benchmarking',
        '198.51.100.0/24'    => 'documentation, not a real host',
        '203.0.113.0/24'     => 'documentation, not a real host',
        '224.0.0.0/4'        => 'multicast',
        '240.0.0.0/4'        => 'reserved',
    ];

    /** @var array<string, string> */
    private const FORBIDDEN_V6 = [
        '::/128'      => 'the unspecified address',
        '::1/128'     => 'this server itself',
        'fc00::/7'    => 'a private network',
        'fe80::/10'   => 'link-local, which is where the cloud metadata service lives',
        'ff00::/8'    => 'multicast',

        /*
         * NAT64 — refused, and its neighbour is deliberately NOT here.
         *
         * `::ffff:0:0/96` used to sit beside this line, described as "an IPv4
         * address wearing an IPv6 costume". A mutation run showed it was DEAD:
         * {@see assertReachable} unwraps a mapped address into its four IPv4
         * bytes before it picks a table, so nothing mapped ever reaches this
         * list, and the entry could be deleted with every test still green. A
         * rule that cannot fire is worse than no rule — it reads as protection.
         *
         * It also said the wrong thing. A blanket refusal here would reject
         * `::ffff:93.184.216.34`, which is an ordinary public mail server
         * written oddly; the unwrap judges it as the public IPv4 address it is,
         * which is both more precise and what the comment claimed.
         *
         * NAT64 is different and stays: `64:ff9b::x.y.z.w` is translated by a
         * gateway into an IPv4 address on the far side, so what we would
         * actually connect to is not knowable from here. Unknown is refused.
         */
        '64:ff9b::/96' => 'NAT64, which translates to an IPv4 address we cannot see from here',
    ];

    /**
     * The metadata endpoints, named so the refusal can say what was asked for.
     *
     * Redundant with the link-local rules above and kept anyway: this is the one
     * address whose presence in a host field is never a mistake, and a refusal
     * that says "the cloud metadata service" is the one an administrator reading
     * the audit log needs to see.
     */
    private const METADATA_ADDRESSES = [
        '169.254.169.254',      // EC2 / GCE / Azure IMDS
        'fd00:ec2::254',        // EC2 IMDS over IPv6
        '100.100.100.200',      // Alibaba Cloud
    ];

    /**
     * Approve a host and a port, or say why not.
     *
     * @param  int[]  $allowedPorts  one of the two lists above — the caller says
     *                               which protocol it is about, because a guard
     *                               that accepted both would let an IMAP form
     *                               open an SMTP connection.
     *
     * @throws CannotReachHost
     */
    public function approve(string $host, int $port, array $allowedPorts): ApprovedHost
    {
        $host = trim($host);

        if ($host === '') {
            throw new CannotReachHost('No mail server was given, so there is nothing to connect to.');
        }

        if (! in_array($port, $allowedPorts, strict: true)) {
            throw new CannotReachHost(sprintf(
                'Port %d is not one this can connect on. Mail servers answer on %s, and '
                .'allowing any port at all would turn this form into a way of scanning the '
                .'network EzBizi runs in. If your provider really uses another port, they '
                .'will also offer one of these.',
                $port,
                $this->readableList($allowedPorts),
            ));
        }

        /*
         * A bracketed literal is how a URL writes an IPv6 address, and somebody
         * pasting a connection string will paste the brackets with it. Stripped
         * before resolution rather than after, because `[::1]` resolves to
         * nothing and would be refused with the wrong sentence.
         */
        $host = trim($host, '[]');

        /*
         * Resolved ONCE, and the same list is both judged and returned.
         *
         * This assignment is rule 3. An earlier draft of this method called
         * `addressesFor()` twice — once to check and once to build the result —
         * which is DNS rebinding written by hand: the second lookup is a fresh
         * lookup, and an attacker's nameserver answering it differently would
         * have had the caller dialling an address nothing checked. One call, one
         * list, and the approved address comes out of the list that was judged.
         */
        $addresses = $this->addressesFor($host);

        foreach ($addresses as $address) {
            $this->assertReachable($host, $address);
        }

        // The first address, returned so the caller connects to IT and never to
        // the name — which is the half of rule 3 that changes the calling code.
        return new ApprovedHost($host, $addresses[0], $port, $addresses);
    }

    /**
     * Every address a name resolves to — and a refusal when it resolves to none.
     *
     * A literal IP is returned as itself rather than looked up: `gethostbyname`
     * on an IP address is a no-op on most systems and a DNS query on some, and
     * the difference is not worth depending on.
     *
     * @return string[]
     *
     * @throws CannotReachHost
     */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (! preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/i', $host)) {
            throw new CannotReachHost(sprintf(
                '"%s" is not a mail server name. It should look like imap.example.com.',
                $host,
            ));
        }

        $records = [];

        foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $type => $flag) {
            /*
             * `@` because a name that does not exist makes dns_get_record emit a
             * warning as well as returning false, and a warning here would be
             * rendered by whatever error handler is installed rather than by the
             * sentence below.
             */
            foreach (@dns_get_record($host, $flag) ?: [] as $record) {
                $address = $record[$type === 'A' ? 'ip' : 'ipv6'] ?? null;

                if ($address !== null) {
                    $records[] = $address;
                }
            }
        }

        if ($records === []) {
            throw new CannotReachHost(sprintf(
                'Nothing answers for "%s" — the name does not resolve to a mail server. Check '
                .'it for a typo, or ask whoever runs the mail service what to put here.',
                $host,
            ));
        }

        return array_values(array_unique($records));
    }

    /**
     * One resolved address, judged.
     *
     * @throws CannotReachHost
     */
    private function assertReachable(string $host, string $address): void
    {
        $normalised = strtolower($address);

        if (in_array($normalised, self::METADATA_ADDRESSES, strict: true)) {
            throw new CannotReachHost($this->refusal(
                $host,
                $address,
                'the cloud metadata service, which holds the keys to the server EzBizi runs on',
            ));
        }

        $packed = @inet_pton($address);

        if ($packed === false) {
            throw new CannotReachHost(sprintf(
                '"%s" resolves to something that is not an address EzBizi can connect to.',
                $host,
            ));
        }

        /*
         * An IPv4-mapped IPv6 address is an IPv4 address, and it is judged as
         * one. Without this, `::ffff:127.0.0.1` would be tested against the IPv6
         * table, where 127.0.0.1 does not appear, and would pass.
         */
        if (strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $table = strlen($packed) === 4 ? self::FORBIDDEN_V4 : self::FORBIDDEN_V6;

        foreach ($table as $cidr => $what) {
            if ($this->inRange($packed, $cidr)) {
                throw new CannotReachHost($this->refusal($host, $address, $what));
            }
        }
    }

    /** Is a packed address inside a CIDR block? Bitwise, so no string prefix can fool it. */
    private function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);

        $networkPacked = inet_pton($network);

        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits      = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $spareBits  = $bits % 8;

        if ($wholeBytes > 0 && substr($packed, 0, $wholeBytes) !== substr($networkPacked, 0, $wholeBytes)) {
            return false;
        }

        if ($spareBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $spareBits)) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
    }

    /**
     * One sentence, and it does not say which of the private ranges it was.
     *
     * It names the CATEGORY — "a private network", "this server itself" — which
     * is what an administrator who mistyped a hostname needs. What it must not
     * do is confirm that 10.0.4.12 in particular is reachable from here, because
     * that is the answer somebody probing would be collecting.
     */
    private function refusal(string $host, string $address, string $what): string
    {
        return sprintf(
            'EzBizi will not connect to "%s". It points at %s rather than at a mail server on '
            .'the internet, and a mail setting that could reach inside the network EzBizi runs '
            .'in would be a way of reading things that are nothing to do with your email. Use '
            .'the mail server name your provider gave you — something like imap.example.com.',
            $host,
            $what,
        );
    }

    /** @param int[] $ports */
    private function readableList(array $ports): string
    {
        $last = array_pop($ports);

        return $ports === [] ? (string) $last : implode(', ', $ports).' and '.$last;
    }
}
