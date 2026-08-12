<?php

namespace App\Support\Email;

/**
 * A host {@see RemoteHostGuard} has approved, and the address it approved.
 *
 * ── Why this is an object and not a bool ─────────────────────────
 *
 * Because a guard that answers yes/no cannot enforce its own third rule. The
 * caller would take the yes and then hand the HOSTNAME to a socket library,
 * which resolves it again — a second lookup, answered by whoever runs that
 * name's DNS, at a moment of their choosing. That is DNS rebinding, and it turns
 * a passed check into a connection to an address nothing ever checked.
 *
 * So the approval carries the ADDRESS. Connecting to `$address` and sending
 * `$host` as the name (SNI, and the certificate check) is the only shape that
 * is both safe and correct: the socket goes where the guard looked, and TLS
 * still verifies the certificate against the name the tenant typed rather than
 * against an IP no certificate names.
 */
final class ApprovedHost
{
    public function __construct(
        /** What the tenant typed — used for TLS and for anything a person reads. */
        public readonly string $host,

        /** The address to open the socket to. Not to be looked up again. */
        public readonly string $address,

        public readonly int $port,

        /**
         * Every address the name resolved to, all of which passed.
         *
         * Kept so that a caller which wants to fail over to the second address
         * can do so without another lookup — the alternative being a fresh
         * resolution at exactly the moment the first attempt failed, which is
         * the worst possible time to trust DNS.
         *
         * @var string[]
         */
        public readonly array $addresses,
    ) {}

    /** True when the socket is being opened to a literal IPv6 address. */
    public function isIpV6(): bool
    {
        return str_contains($this->address, ':');
    }

    /**
     * What a stream wrapper wants: `tcp://93.184.216.34:993`, or the bracketed
     * IPv6 spelling.
     *
     * The ADDRESS and never the host — see the note above. A caller that wants
     * the name for TLS verification asks for `$host` explicitly, which makes the
     * two uses impossible to confuse at the call site.
     */
    public function socketTarget(): string
    {
        return sprintf(
            'tcp://%s:%d',
            $this->isIpV6() ? '['.$this->address.']' : $this->address,
            $this->port,
        );
    }
}
