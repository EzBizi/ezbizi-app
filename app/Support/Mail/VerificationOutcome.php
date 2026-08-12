<?php

namespace App\Support\Mail;

/**
 * What came back from looking at a domain's DNS.
 *
 * ── Three answers, and the third is the one that matters ─────────
 *
 * A boolean would collapse "the records are not there" into "we could not
 * look", and those must never be the same answer. The first is the tenant's to
 * fix and belongs in a `failed` state with a reason. The second is OURS, it
 * means the installation has no DNS provider wired up at all, and treating it
 * as a pass is the single worst outcome this feature has: a verification that
 * returns true because nothing checked.
 *
 * So `unavailable` exists, it does not verify, and it does not blame the tenant
 * either — it leaves the identity waiting and says, in a sentence, that the
 * hold-up is at our end.
 */
final class VerificationOutcome
{
    private function __construct(
        public readonly bool $verified,
        public readonly bool $checked,
        public readonly string $explanation,
    ) {}

    /** Somebody looked at DNS and the records are there. */
    public static function verified(string $explanation): self
    {
        return new self(verified: true, checked: true, explanation: $explanation);
    }

    /** Somebody looked at DNS and they are not. The tenant has something to fix. */
    public static function rejected(string $explanation): self
    {
        return new self(verified: false, checked: true, explanation: $explanation);
    }

    /**
     * Nobody looked, because this installation cannot.
     *
     * Never verified, and deliberately not `rejected` either: telling a tenant
     * their records are wrong when nothing read them sends them to their DNS
     * host to fix something that is not broken.
     */
    public static function unavailable(string $explanation): self
    {
        return new self(verified: false, checked: false, explanation: $explanation);
    }
}
