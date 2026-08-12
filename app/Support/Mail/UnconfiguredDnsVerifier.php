<?php

namespace App\Support\Mail;

/**
 * The default DnsVerifier: it refuses, and it says why.
 *
 * ── The whole point is that this does not pass ───────────────────
 *
 * This class could have been four characters long — `return true` — and every
 * test in the suite would have gone green, tenants would have seen "Verified"
 * beside their address, and the first thing anybody would have learned is that
 * a customer had been sending as a domain they did not own. Refusing keeps the
 * feature honest while the provider account does not exist: an identity can be
 * created, its records can be generated and handed over, and it simply cannot
 * send until somebody wires a real verifier in.
 *
 * `unavailable`, not `rejected`. The tenant's records may well be perfect. What
 * is missing is at our end, and the sentence says so rather than sending them
 * to their DNS host to hunt for a fault that is not there.
 */
final class UnconfiguredDnsVerifier implements DnsVerifier
{
    public function check(string $domain, array $expected): VerificationOutcome
    {
        return VerificationOutcome::unavailable(
            'Nothing has read the DNS for '.$domain.' yet, because this installation of '
            .'EzBizi has no domain-verification service connected. The records above are '
            .'correct and worth publishing now — mail will start going out under this '
            .'address as soon as EzBizi can check them. Until then everything sends from '
            .'the EzBizi address instead, so nothing is stuck waiting.'
        );
    }

    public function describe(): string
    {
        return 'No domain-verification service is connected, so no domain can be proved.';
    }
}
