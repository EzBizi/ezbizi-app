<?php

namespace App\Support\Mail;

/**
 * THE SEAM. The one place real DNS is read, and it is not implemented.
 *
 * ── Why there is a hole here on purpose ──────────────────────────
 *
 * Domain verification cannot be finished without an account somewhere that can
 * both look up DNS reliably and sign outgoing mail for a customer's domain.
 * There is no such account today. The two honest options were to leave the
 * feature out entirely, or to build everything around the check and leave the
 * check itself behind one named interface — this one — with a default that
 * refuses.
 *
 * The dishonest third option is the one this file exists to make impossible: a
 * stub that returns true, or a "TODO: real check" beside code that behaves as
 * though the domain were proved. An identity that reports `verified` because
 * nothing looked is worse than no feature at all, because everything downstream
 * — including {@see MailIdentity::canSend()} — then correctly lets it send.
 *
 * ── How the real one gets wired in ───────────────────────────────
 *
 * `config('mail.verification.dns_verifier')` names a class implementing this
 * interface. It is deployment config (§2.1): which provider an installation
 * verifies through is a property of the installation and not of any tenant. Set
 * MAIL_DNS_VERIFIER in the environment, add the class, change nothing else.
 * {@see DomainVerification::verifier()} is where that lookup happens, and it
 * refuses a class that does not implement this rather than falling back to
 * something that passes.
 *
 * Unset, the default is {@see UnconfiguredDnsVerifier}, which answers
 * `unavailable` to everything.
 */
interface DnsVerifier
{
    /**
     * Are these records published for this domain?
     *
     * @param  string       $domain    "everdoors.com" — never an address.
     * @param  DnsRecord[]  $expected  every record generated for this identity.
     *                                 An implementation must require ALL of them:
     *                                 the ownership TXT alone proves control of
     *                                 the zone and says nothing about whether
     *                                 mail signed for that domain will be
     *                                 accepted, which is the thing being claimed.
     */
    public function check(string $domain, array $expected): VerificationOutcome;

    /**
     * A sentence naming what this verifier is, for the staff health screen.
     *
     * On the interface because "which verifier is this installation using" must
     * be answerable without an `instanceof` ladder somewhere else — the day
     * there are two implementations, the ladder is what gets forgotten.
     */
    public function describe(): string;
}
