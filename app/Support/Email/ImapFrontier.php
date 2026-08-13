<?php

namespace App\Support\Email;

/**
 * Where an IMAP mailbox's next message will land — the one number requirement 1
 * rests on.
 *
 * ── Why this is its own class ────────────────────────────────────
 *
 * It was a private method on {@see ImapMailboxReader}, and an adversary deleted
 * its entire body — `return 1;` — and the whole suite stayed green. Nothing
 * anywhere proved the reader used UIDNEXT at all.
 *
 * That was not carelessness so much as unreachability. The reader is `final`,
 * so is {@see RemoteHostGuard}, and the fake IMAP server binds a loopback
 * address the guard is right to refuse. There was no seam to test through, so
 * the most consequential arithmetic in the module had no test and could not
 * easily be given one.
 *
 * So the decision moved here, where it is three integers in and one out, with
 * no socket and nothing to mock — the same shape as {@see GmailMessageMapper},
 * for the same reason.
 *
 * ── Why getting it wrong is expensive ────────────────────────────
 *
 * The frontier is the lowest UID not yet issued. Everything at or above it is
 * an arrival; everything below was already there when EzBizi started watching.
 * Answer too low and the next poll announces mail that arrived years ago —
 * every automation in the organization firing at once on a customer's whole
 * inbox. Answer too high and arrivals are silently missed.
 *
 * Of the two, too low is far worse, which is why the uncertain case refuses
 * rather than picking the smallest number.
 */
final class ImapFrontier
{
    /**
     * The frontier, or NULL when it genuinely cannot be told.
     *
     * Null rather than a fallback, and rather than an exception: this class has
     * no mailbox to name in a refusal and no business deciding what a caller
     * does about it. {@see ImapMailboxReader} turns null into
     * {@see CannotReadMailbox::providerFaulty}, which costs one skipped poll —
     * a delay, where a guess costs a replayed inbox.
     *
     * @param  int  $uidnext     UIDNEXT as the server reported it, or 0 when it
     *                           did not. It is a SHOULD in the protocol, not a
     *                           MUST, so 0 is an ordinary answer.
     * @param  int  $exists      How many messages the mailbox holds.
     * @param  int|null  $highestUid  The UID of the message at the highest
     *                           SEQUENCE number, when one had to be fetched.
     *                           Null when the fetch returned nothing.
     */
    public static function from(int $uidnext, int $exists, ?int $highestUid = null): ?int
    {
        // The server said so. Believe it — it is the only party entitled to.
        if ($uidnext > 0) {
            return $uidnext;
        }

        /*
         * Empty, and 1 is the RIGHT answer here rather than a guess: UIDs start
         * at 1, so in a mailbox with nothing in it the next message gets 1 and
         * "everything at or above 1 is an arrival" is exactly true.
         *
         * This is the one branch where 1 is safe, which is precisely why the
         * branch below it must not fall through to the same value.
         */
        if ($exists === 0) {
            return 1;
        }

        /*
         * Not empty, and nothing came back for the last message.
         *
         * Reachable by an ordinary race — the message expunged between the
         * EXAMINE that reported `exists` and the FETCH that asked for its UID.
         * The old code returned 1 here, which is the worst available answer:
         * the next poll would search from the first UID ever issued and
         * announce the entire mailbox as new.
         *
         * Nothing is known, so nothing is claimed.
         */
        if ($highestUid === null) {
            return null;
        }

        // UIDs are issued in arrival order and never reused, so the last
        // message holds the largest one and the next will be one past it.
        return $highestUid + 1;
    }
}
