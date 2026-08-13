<?php

namespace Tests\Feature;

use App\Support\Email\ImapFrontier;
use Tests\TestCase;

/**
 * The one number requirement 1 rests on.
 *
 * ── Why this file exists ─────────────────────────────────────────
 *
 * An adversary deleted the entire body of the reader's `frontier()` — replacing
 * it with `return 1;` — and all 1188 tests passed. Nothing anywhere proved the
 * reader used UIDNEXT, on one of the two transports, for the requirement the
 * whole watcher is built around.
 *
 * It was unreachable rather than overlooked: the reader is `final`, so is the
 * host guard, and the fake IMAP server binds a loopback address the guard is
 * correct to refuse. There was no seam. So the arithmetic moved into a pure
 * class and this is it, tested directly.
 *
 * The asymmetry worth keeping in mind while reading these: too HIGH loses
 * arrivals, one poll at a time. Too LOW announces mail that arrived years ago,
 * all at once, to every automation in the organization. They are not
 * equivalently bad and the code is not symmetric about them.
 */
class ImapFrontierTest extends TestCase
{
    /** The server said so, and it is the only party entitled to. */
    public function test_uidnext_is_believed_when_the_server_gives_it(): void
    {
        $this->assertSame(4393, ImapFrontier::from(uidnext: 4393, exists: 1));

        // And it wins even when a fetched UID disagrees — UIDNEXT is the
        // server's own statement about the future, a UID is an observation
        // about the past, and a mailbox can be appended to between the two.
        $this->assertSame(
            4393,
            ImapFrontier::from(uidnext: 4393, exists: 1, highestUid: 12),
            'A stale observation outranked the server\'s own answer.',
        );
    }

    /**
     * The one branch where 1 is right rather than a guess.
     *
     * UIDs start at 1, so in an empty mailbox the next message gets 1 and
     * "everything at or above the frontier is an arrival" is exactly true.
     */
    public function test_an_empty_mailbox_starts_at_one(): void
    {
        $this->assertSame(1, ImapFrontier::from(uidnext: 0, exists: 0));
    }

    /** UIDs are issued in order and never reused, so the last one plus one. */
    public function test_without_uidnext_it_is_one_past_the_highest_uid(): void
    {
        $this->assertSame(51, ImapFrontier::from(uidnext: 0, exists: 12, highestUid: 50));

        // Sequence numbers and UIDs are the same only in a mailbox nothing has
        // ever been deleted from. `exists` is twelve and the answer is 51, so
        // nothing here is confusing one for the other.
        $this->assertNotSame(13, ImapFrontier::from(uidnext: 0, exists: 12, highestUid: 50));
    }

    /**
     * THE ONE THAT WAS WRONG, and it returned the worst available answer.
     *
     * A non-empty mailbox whose newest message could not be read — an ordinary
     * race, the message expunged between the EXAMINE that counted it and the
     * FETCH that asked for its UID. The old code returned 1, so the next poll
     * would search from the first UID ever issued and announce the entire
     * mailbox as newly arrived.
     *
     * Null means the reader refuses the poll and asks again shortly. A missed
     * cycle is a delay; a replayed inbox is every rule firing on mail from
     * years ago, and it cannot be taken back.
     */
    public function test_a_mailbox_whose_newest_message_cannot_be_read_refuses_to_guess(): void
    {
        $this->assertNull(
            ImapFrontier::from(uidnext: 0, exists: 12, highestUid: null),
            'A frontier was invented for a mailbox nothing is known about. If that value '
            .'is 1, the next poll announces every message in the inbox as new.',
        );

        // The control, and it is the whole point of the assertion above: the
        // same call WITH an observation succeeds. So the null is about the
        // missing information, not about this combination being rejected.
        $this->assertSame(51, ImapFrontier::from(uidnext: 0, exists: 12, highestUid: 50));
    }
}
