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
 *   verified    Something looked and found them. This is the only state that
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
            /*
             * ALL THREE SAY THE SAME THING NOW, and none of them is reachable.
             *
             * They used to hand somebody off to a settings card to publish DNS
             * records. Jose deleted that card on 2026-09-14 ("GET RID OF IT")
             * and the ruling behind it retired the flow, so the old sentences
             * named a remedy that no longer exists — the exact defect two of
             * this file's neighbours were corrected for that same day:
             * confident, specific, and pointing at nothing.
             *
             * Unreachable because nothing in app/ constructs a SendingIdentity
             * any more: SendingIdentitiesController was the only writer and it
             * went with the card. Kept rather than deleted because the `state`
             * column still exists, so a row written before the deletion still
             * casts through here, and a match arm that threw would turn a stale
             * row into a 500 on the health endpoint.
             */
            self::Unverified, self::Pending, self::Failed => "Mail cannot be sent as {$address} "
                .'by EzBizi. Reports, inspection reports and automations that email somebody '
                .'need this business to have its own mail server connected.',
        };
    }
}
