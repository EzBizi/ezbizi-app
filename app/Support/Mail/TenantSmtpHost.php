<?php

namespace App\Support\Mail;

/**
 * The trust boundary on a bring-your-own-SMTP connection.
 *
 * A tenant types a hostname and our server dials it. That is a Server-Side
 * Request Forgery surface pointed at our own network, and it was named as a
 * reason NOT to build this feature twice over — once in MailTransport's docblock
 * and once in the sending_identities migration. The feature arrived anyway
 * (Jose, 2026-09-14: "yes build it for god sake"), so the objection becomes this
 * file rather than disappearing along with the decision that overruled it.
 *
 * ── What it is actually protecting ───────────────────────────────
 *
 * Not "internal websites" in the abstract. The specific prize is
 * 169.254.169.254 — the cloud instance metadata endpoint, which on an unhardened
 * instance hands out the machine's IAM credentials to anything that can make a
 * request from it. An SMTP dial is a poor way to read a response, but
 * connect-or-refuse is already an oracle: a tenant who can tell "connection
 * refused" from "timed out" can map our private network one port at a time, for
 * free, from a settings form.
 *
 * ── Resolving is the check; the literal is the easy half ─────────
 *
 * Blocking `127.0.0.1` stops nobody, because `localtest.me` — and any domain the
 * tenant controls — resolves to it just as well. So every address the host
 * currently resolves to is checked, not the string that was typed. A host that
 * resolves to nothing is refused too: there is no send to be had from it, and
 * "no answer" must not read as "no objection".
 *
 * ponytail: TOCTOU residue, and it is real. We resolve here and Symfony's
 * transport resolves again when it dials, so a record with a one-second TTL can
 * answer publicly for us and privately for the connection. Closing that needs a
 * resolve-once-then-connect-by-IP transport — which means owning the socket and
 * losing TLS hostname verification — or an egress firewall. The firewall is the
 * right fix and it belongs on the box, not in PHP. This stops the accidental
 * case and every attacker not specifically doing DNS rebinding; the rest is
 * network policy and the deploy desk should be told so.
 */
final class TenantSmtpHost
{
    /**
     * Ranges that must never be dialled on a tenant's instruction.
     *
     * FILTER_FLAG_NO_PRIV_RANGE and NO_RES_RANGE cover much of this, but they
     * are a filter over a STRING — they say nothing about what a hostname
     * resolves to — and they do not treat 169.254.0.0/16 as reserved in every
     * PHP build. Written out because the metadata endpoint is the one address
     * where being approximately right is worth nothing.
     */
    private const FORBIDDEN_V4 = [
        ['0.0.0.0', 8],        // "this host"; a well-known localhost bypass
        ['10.0.0.0', 8],       // RFC1918
        ['100.64.0.0', 10],    // carrier NAT — shared, not ours to reach
        ['127.0.0.0', 8],      // loopback
        ['169.254.0.0', 16],   // link-local: cloud instance metadata lives here
        ['172.16.0.0', 12],    // RFC1918
        ['192.0.0.0', 24],     // IETF protocol assignments
        ['192.168.0.0', 16],   // RFC1918
        ['198.18.0.0', 15],    // benchmarking
        ['224.0.0.0', 4],      // multicast
        ['240.0.0.0', 4],      // reserved, includes 255.255.255.255
    ];

    /**
     * @throws CannotSendMail when this host must not be dialled
     */
    public static function guard(string $host): void
    {
        $host = trim($host);

        if ($host === '') {
            throw new CannotSendMail(
                'This business has no mail server address saved, so there is nothing to send through.'
            );
        }

        /*
         * A URL, a port, or credentials smuggled into the host field. The
         * connection stores its port separately, so anything with structure here
         * is either a mistake or an attempt to reach something else.
         */
        if (preg_match('~[/@:\s]~', $host) === 1) {
            throw new CannotSendMail(
                "'{$host}' is not a mail server address. Give the server name on its own, such "
                .'as smtp.example.com, and set the port in its own field.'
            );
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            throw new CannotSendMail(
                "No mail server could be found at '{$host}'. Check the address with whoever "
                .'provides this business its email.'
            );
        }

        foreach ($addresses as $address) {
            if (self::isForbidden($address)) {
                /*
                 * Deliberately does not say which address it resolved to, nor
                 * that the reason is that it is internal. The refusal is already
                 * a weak oracle; naming the resolved IP would make it a strong
                 * one, and this sentence is shown to whoever typed the host.
                 */
                throw new CannotSendMail(
                    "'{$host}' is not a mail server EzBizi can send through. It has to be one "
                    .'reachable on the public internet, such as the server whoever provides '
                    .'this business its email would give you.'
                );
            }
        }
    }

    /** @return string[] every address this host currently answers with */
    private static function resolve(string $host): array
    {
        /*
         * A literal is its own answer. gethostbynamel() returns false for one,
         * which would otherwise read as "does not resolve" and refuse a valid
         * public IP with the wrong sentence.
         */
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $found = [];

        if (($v4 = gethostbynamel($host)) !== false) {
            $found = $v4;
        }

        /*
         * IPv6 separately: gethostbynamel is v4-only, so a host answering only
         * on AAAA would look unresolvable and be refused for the wrong reason.
         */
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $found[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($found));
    }

    private static function isForbidden(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::FORBIDDEN_V4 as [$subnet, $bits]) {
                if (self::inV4Subnet($address, $subnet, $bits)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            // Neither v4 nor v6. Nothing legitimate resolved to this, so
            // refusing is the only safe reading.
            return true;
        }

        $packed = inet_pton($address);

        if ($packed === false) {
            return true;
        }

        if ($packed === inet_pton('::1') || $packed === inet_pton('::')) {
            return true;
        }

        $first = ord($packed[0]);

        // fc00::/7 unique-local.
        if (($first & 0xFE) === 0xFC) {
            return true;
        }

        // fe80::/10 link-local — v6 instance metadata lives here too.
        if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) {
            return true;
        }

        /*
         * ::ffff:0:0/96 — an IPv4 address wearing a v6 coat. Without this,
         * ::ffff:169.254.169.254 walks straight past the v4 table above, because
         * it is not a valid FILTER_FLAG_IPV4 string and so never reaches it.
         */
        $v4MappedPrefix = inet_pton('::ffff:0.0.0.0');

        if ($v4MappedPrefix !== false && str_starts_with($packed, substr($v4MappedPrefix, 0, 12))) {
            $unwrapped = inet_ntop(substr($packed, 12));

            return $unwrapped === false || self::isForbidden($unwrapped);
        }

        return false;
    }

    private static function inV4Subnet(string $address, string $subnet, int $bits): bool
    {
        $a = ip2long($address);
        $s = ip2long($subnet);

        if ($a === false || $s === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($a & $mask) === ($s & $mask);
    }
}
