<?php

namespace App\Support\Mail;

/**
 * Where a sending identity is in the business of proving it owns its domain.
 *
 * ── Four states, not a boolean ───────────────────────────────────
 *
 * `verified` is the only one that can send, so a boolean would carry the whole
 * decision. It would carry none of the explanation, and the explanation is what
 * the tenant needs: "we have not looked yet", "we looked and your records are
 * not published", and "you published them and they check out" are three
 * different situations with three different next actions, and only the middle
 * one is anybody's fault.
 *
 *   unverified  Somebody typed an address. Nothing has been asked of DNS, and
 *               the records have not even been handed over yet.
 *   pending     The records have been generated and the tenant has been asked to
 *               publish them. Every check so far has come back short.
 *   verified    A DnsVerifier looked and found them. This is the only state that
 *               may send.
 *   failed      A check came back and said no, with a reason. Distinct from
 *               pending because pending is a normal part of setting up and
 *               failed means something is wrong — a typo in the record, or a
 *               domain that has since been moved to another DNS host.
 *
 * ── Deliberately no canSend() here ───────────────────────────────
 *
 * The task this was built for asks that "can this identity send" be ONE function
 * everything asks. It is {@see MailIdentity::canSend()}. A convenience copy on
 * this enum would be a second one, and the second one is where the two come to
 * disagree — this enum cannot see the platform identity, which is a link in the
 * same chain and answers the same question.
 */
enum SendingState: string
{
    case Unverified = 'unverified';
    case Pending    = 'pending';
    case Verified   = 'verified';
    case Failed     = 'failed';

    /** What the tenant's screen calls this state. */
    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Not started',
            self::Pending    => 'Waiting for DNS',
            self::Verified   => 'Verified',
            self::Failed     => 'Check failed',
        };
    }

    /**
     * Why mail will not go out under this identity, and what to do about it (R8).
     *
     * Null for `verified`, which is the state with nothing to refuse. Every other
     * sentence names an action somebody can actually take — a refusal that only
     * states a fact is a dead end.
     *
     * @param  string  $address  the address being refused, so the sentence is
     *                           about a thing the reader can see on screen rather
     *                           than about "this identity".
     */
    public function refusal(string $address): ?string
    {
        return match ($this) {
            self::Verified => null,

            self::Unverified => "Mail cannot be sent as {$address} yet, because nobody has "
                .'proved that this business owns that domain. Open the sending address and '
                .'start verification — it hands you the DNS records to publish, and mail '
                .'goes out under this address once they are in place.',

            self::Pending => "Mail cannot be sent as {$address} yet: the DNS records for it "
                .'have been handed over but have not been found published. Give your DNS '
                .'host time to spread the change — it can take a few hours — then check '
                .'again from the sending address screen.',

            self::Failed => "Mail cannot be sent as {$address}: the last check of its DNS "
                .'records did not pass. The sending address screen shows exactly what came '
                .'back and the records to publish; correct them with your DNS host and '
                .'check again.',
        };
    }
}
