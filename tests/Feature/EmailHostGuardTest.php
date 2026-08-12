<?php

namespace Tests\Feature;

use App\Support\Email\CannotReachHost;
use App\Support\Email\RemoteHostGuard;
use Tests\TestCase;

/**
 * The SSRF guard, against the addresses a tenant-supplied host field would be
 * pointed at by somebody who was not filling in a form.
 *
 * ── Why literal IPs rather than names that resolve to them ───────
 *
 * Most cases below hand the guard an IP address directly. That is not avoiding
 * the hard part: `addressesFor()` returns a literal IP unchanged and then judges
 * it through exactly the same {@see RemoteHostGuard::assertReachable} that every
 * resolved address goes through, so these exercise the real judgement. Tests
 * that depended on a NAME resolving to 127.0.0.1 would depend on somebody's
 * resolver and on the build machine having a network at all, which is how a
 * security test becomes one that is skipped.
 *
 * The resolve-then-connect property is asserted separately and structurally —
 * see the ApprovedHost test at the bottom, which is about the SHAPE the guard
 * returns rather than about what any particular name resolves to.
 */
class EmailHostGuardTest extends TestCase
{
    private RemoteHostGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new RemoteHostGuard;
    }

    private function refusalFor(string $host, int $port = 993): string
    {
        try {
            $this->guard->approve($host, $port, RemoteHostGuard::IMAP_PORTS);
        } catch (CannotReachHost $e) {
            return $e->getMessage();
        }

        $this->fail("The guard approved {$host}:{$port}, which it must not.");
    }

    /**
     * The one that matters most, by name.
     *
     * `169.254.169.254` answers over plain HTTP with no authentication and hands
     * back the instance's IAM role credentials. A mailbox pointed at it is an
     * attempt to make our server fetch our own keys.
     */
    public function test_the_ec2_metadata_endpoint_is_refused(): void
    {
        $message = $this->refusalFor('169.254.169.254');

        $this->assertStringContainsString('metadata', $message);

        // R8: the sentence tells them what to do instead, and does not name a
        // class, a range or a protocol constant.
        $this->assertStringContainsString('imap.example.com', $message);
    }

    public function test_the_metadata_endpoint_over_ipv6_is_refused(): void
    {
        $this->assertStringContainsString('metadata', $this->refusalFor('fd00:ec2::254'));

        // And the bracketed spelling somebody would paste out of a connection
        // string, which must not become a different code path.
        $this->assertStringContainsString('metadata', $this->refusalFor('[fd00:ec2::254]'));
    }

    /**
     * The ranges, each with the reason it is on the list.
     *
     * Kept as one table rather than one test each: they are one rule with many
     * instances, and a table makes an omission visible in a way fifteen
     * near-identical methods do not.
     */
    public function test_every_private_reserved_and_link_local_range_is_refused(): void
    {
        $refused = [
            '127.0.0.1'       => 'this server itself',
            '127.1.2.3'       => 'the rest of the loopback block, not just .0.1',
            '0.0.0.0'         => 'the unspecified address',
            '10.0.0.1'        => 'a private network',
            '172.16.5.4'      => 'a private network',
            '172.31.255.254'  => 'the far end of the 172.16/12 block',
            '192.168.1.1'     => 'a private network',
            '100.64.0.1'      => 'carrier-grade NAT',
            '169.254.1.1'     => 'link-local generally, not only the metadata address',
            '224.0.0.1'       => 'multicast',
            '240.0.0.1'       => 'reserved',
            '::1'             => 'loopback over IPv6',
            'fc00::1'         => 'an IPv6 private network',
            'fe80::1'         => 'IPv6 link-local',
            'ff02::1'         => 'IPv6 multicast',
        ];

        foreach ($refused as $address => $what) {
            $message = $this->refusalFor($address);

            $this->assertStringContainsString(
                'will not connect',
                $message,
                "approved {$address}, which is {$what}",
            );
        }
    }

    /**
     * An IPv4 address wearing an IPv6 costume.
     *
     * `::ffff:127.0.0.1` is loopback. Judged against the IPv6 table it passes,
     * because 127.0.0.1 does not appear there — which is why the guard unwraps
     * the mapping before it decides.
     */
    public function test_an_ipv4_mapped_ipv6_address_is_judged_as_the_ipv4_address_it_is(): void
    {
        foreach (['::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:10.0.0.1'] as $address) {
            $this->assertStringContainsString(
                'will not connect',
                $this->refusalFor($address),
                "approved {$address}",
            );
        }

        /*
         * The other half, and the reason "judged as the IPv4 address it is" is
         * not the same claim as "refused for being mapped".
         *
         * A blanket refusal of `::ffff:*` would pass every line above and would
         * turn away an ordinary public mail server written in an unusual but
         * perfectly legal notation. This is the control that tells the two
         * apart — and it is what makes the unwrapping step load bearing rather
         * than incidental.
         */
        $approved = $this->guard->approve('::ffff:93.184.216.34', 993, RemoteHostGuard::IMAP_PORTS);

        $this->assertSame('::ffff:93.184.216.34', $approved->address);
    }

    /**
     * The port list, and why 25 is not on it.
     *
     * A host field that can name any port is a port scanner with our source
     * address on it. Port 25 is absent from the SMTP list specifically: it is
     * the server-to-server port where an open relay lives, and an authenticated
     * submission never uses it.
     */
    public function test_only_known_mail_ports_are_allowed(): void
    {
        foreach ([22, 80, 3306, 6379, 8080, 11211, 1, 65535] as $port) {
            $message = $this->refusalFor('93.184.216.34', $port);

            $this->assertStringContainsString('not one this can connect on', $message);
            $this->assertStringContainsString('143', $message);
        }

        // 25 is refused on the SMTP side, where somebody would most expect it.
        try {
            $this->guard->approve('93.184.216.34', 25, RemoteHostGuard::SMTP_PORTS);
            $this->fail('port 25 was approved for SMTP');
        } catch (CannotReachHost $e) {
            $this->assertStringContainsString('587', $e->getMessage());
        }
    }

    /**
     * A guard that refused everything would pass every test above.
     *
     * This is the control for the whole file: a public address on a mail port is
     * approved, and the approval carries what the caller needs.
     */
    public function test_a_public_address_on_a_mail_port_is_approved(): void
    {
        $approved = $this->guard->approve('93.184.216.34', 993, RemoteHostGuard::IMAP_PORTS);

        $this->assertSame('93.184.216.34', $approved->host);
        $this->assertSame('93.184.216.34', $approved->address);
        $this->assertSame(993, $approved->port);
        $this->assertSame('tcp://93.184.216.34:993', $approved->socketTarget());

        $smtp = $this->guard->approve('93.184.216.34', 587, RemoteHostGuard::SMTP_PORTS);

        $this->assertSame(587, $smtp->port);
    }

    /**
     * Resolve-then-connect, asserted as a property of the return value.
     *
     * The guard hands back an ADDRESS and a socket target built from it. That is
     * what makes DNS rebinding impossible for a caller that uses the object as
     * intended: there is no second lookup, because the caller never needs the
     * name to open the socket.
     *
     * A public IPv6 literal, so the bracketed socket spelling is covered too — a
     * caller concatenating `tcp://` and a bare IPv6 address would produce a
     * target whose port is parsed as part of the address.
     */
    public function test_the_approval_carries_the_address_to_dial_rather_than_the_name(): void
    {
        $approved = $this->guard->approve('[2606:2800:220:1:248:1893:25c8:1946]', 993, RemoteHostGuard::IMAP_PORTS);

        $this->assertTrue($approved->isIpV6());
        $this->assertSame('tcp://[2606:2800:220:1:248:1893:25c8:1946]:993', $approved->socketTarget());

        // Every address the name resolved to is carried, and all of them passed
        // — so a caller failing over to the second does not have to look
        // anything up at the worst possible moment.
        $this->assertSame([$approved->address], $approved->addresses);
    }

    public function test_a_name_that_does_not_resolve_is_refused_with_a_sentence(): void
    {
        $message = $this->refusalFor('no-such-host.invalid');

        $this->assertStringContainsString('does not resolve', $message);
        $this->assertStringContainsString('typo', $message);
    }

    public function test_something_that_is_not_a_host_name_at_all_is_refused(): void
    {
        foreach (['', '   ', 'not a host', 'http://imap.example.com', 'imap.example.com/path'] as $host) {
            try {
                $this->guard->approve($host, 993, RemoteHostGuard::IMAP_PORTS);
                $this->fail("approved '{$host}'");
            } catch (CannotReachHost $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    /**
     * The refusal must not become a probe.
     *
     * Naming the exact range would confirm to whoever is trying that
     * 10.0.4.12 in particular is reachable from our network. The sentence names
     * the CATEGORY, which is what somebody who mistyped their mail server needs.
     */
    public function test_the_refusal_names_a_category_and_not_the_range_it_matched(): void
    {
        $message = $this->refusalFor('10.11.12.13');

        $this->assertStringContainsString('a private network', $message);
        $this->assertStringNotContainsString('10.0.0.0/8', $message);
        $this->assertStringNotContainsString('CIDR', $message);
    }

    /** A refused host is a 422 about the value, never a 403 about the person. */
    public function test_the_refusal_is_about_the_value_and_not_about_permission(): void
    {
        try {
            $this->guard->approve('127.0.0.1', 993, RemoteHostGuard::IMAP_PORTS);
            $this->fail('loopback was approved');
        } catch (CannotReachHost $e) {
            $this->assertSame(422, $e->status());
        }
    }
}
