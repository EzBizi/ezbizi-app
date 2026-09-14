<?php

namespace Tests\Feature;

use App\Support\Mail\CannotSendMail;
use App\Support\Mail\TenantSmtpHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SSRF guard on a tenant-supplied mail server.
 *
 * A plain TestCase, not a Laravel one: nothing here touches the database or the
 * container, and the tenancy bootstrap would only make it slower.
 *
 * ── Why the allowed cases matter as much as the refused ones ─────
 *
 * A guard that refuses everything passes every refusal test ever written. The
 * three public hosts below are the control: if somebody tightens the ranges
 * until a real mail server cannot be reached, these go red, and without them the
 * feature could be silently dead while the security tests stayed green.
 */
class TenantSmtpHostTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function forbiddenHosts(): array
    {
        return [
            // THE one that matters. On an unhardened cloud instance this hands
            // out the machine's IAM credentials to anything that can reach it.
            'cloud instance metadata' => ['169.254.169.254'],

            // Same address wearing a v6 coat. Without the ::ffff:0:0/96 unwrap
            // in isForbidden() this walks straight past the IPv4 table, because
            // it is not a valid FILTER_FLAG_IPV4 string and never reaches it.
            'metadata as a v4-mapped v6' => ['::ffff:169.254.169.254'],

            'loopback' => ['127.0.0.1'],
            'loopback, not dotted-quad' => ['127.1'],
            'v6 loopback' => ['::1'],
            'v6 unspecified' => ['::'],
            'this host' => ['0.0.0.0'],
            'RFC1918 ten' => ['10.1.2.3'],
            'RFC1918 one-seven-two' => ['172.16.9.9'],
            'RFC1918 one-nine-two' => ['192.168.0.5'],
            'carrier NAT' => ['100.64.0.1'],
            'v6 unique-local' => ['fd00::1'],
            'v6 link-local' => ['fe80::1'],
            'broadcast' => ['255.255.255.255'],

            // Structure in the host field is a mistake or an attempt to reach
            // something else; the port has its own field.
            'a URL' => ['http://evil.test'],
            'a host with a port' => ['smtp.example.test:587'],
            'credentials in the host' => ['user@smtp.example.test'],
            'nothing at all' => [''],
            'whitespace only' => ['   '],

            // Resolves to nothing. "No answer" must not read as "no objection".
            'a host that does not resolve' => ['no-such-host.invalid'],
        ];
    }

    #[DataProvider('forbiddenHosts')]
    public function test_a_host_that_points_back_at_us_is_refused(string $host): void
    {
        $this->expectException(CannotSendMail::class);

        TenantSmtpHost::guard($host);
    }

    #[DataProvider('forbiddenHosts')]
    public function test_the_refusal_never_names_the_address_it_resolved_to(string $host): void
    {
        /*
         * The refusal is already a weak oracle — a tenant can tell "refused"
         * from "accepted" and learn something about our network from it. Naming
         * the resolved address, or saying the word "internal" or "private",
         * would turn it into a strong one: paste a hostname, read back where it
         * points. The sentence has to be actionable for the honest tenant
         * without being a scanner for anybody else.
         */
        try {
            TenantSmtpHost::guard($host);
            $this->fail("'{$host}' was allowed, so there is no refusal to inspect.");
        } catch (CannotSendMail $e) {
            $message = mb_strtolower($e->getMessage());

            foreach (['internal', 'private', 'localhost', 'loopback', 'metadata', 'resolved to'] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $message,
                    "The refusal for '{$host}' says '{$leak}', which tells whoever typed it what "
                    .'they just found. It should say what IS allowed, not what they hit.'
                );
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reachableHosts(): array
    {
        return [
            'a real mail server' => ['smtp.gmail.com'],
            'a public resolver v4' => ['8.8.8.8'],
            'another public v4' => ['1.1.1.1'],
        ];
    }

    #[DataProvider('reachableHosts')]
    public function test_a_real_public_mail_server_is_allowed(string $host): void
    {
        /*
         * THE CONTROL. Every other test in this file passes if guard() throws
         * unconditionally, which is the most likely way for this to break: a
         * later tightening of the ranges that nothing contradicts. These use
         * public DNS, so they need the network — if they fail in an isolated
         * environment, that is what it is, and the alternative is a guard whose
         * suite cannot tell "safe" from "dead".
         */
        TenantSmtpHost::guard($host);

        $this->assertTrue(true, 'guard() returned rather than throwing.');
    }
}
