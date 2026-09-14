<?php

namespace App\Support\Email;

use Symfony\Component\HttpFoundation\Response;

/**
 * WHY a shared inbox could not be opened — the causes that need different people
 * to do different things.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THE OWNER ASKED FOR "REVOKED OR EXPIRED", AND THIS IS THE
 *  HONEST ANSWER TO IT.
 *
 *  Those two are the same case here, and pretending otherwise would
 *  be inventing a distinction the mail service does not make. Google
 *  answers a refresh token that has been withdrawn, one that has
 *  aged out, and one invalidated by a password change with the SAME
 *  reply — `invalid_grant` — and nothing else in that reply separates
 *  them. So {@see SignInNoLongerValid} covers all three and its
 *  sentence NAMES ALL THREE and says EzBizi cannot tell which. A
 *  screen that guessed "revoked" would send somebody to a Google
 *  security page to un-revoke something that had merely expired.
 *
 *  What IS separable is separated, and each case below exists because
 *  it puts a different person on a different job: reconnect the
 *  mailbox, widen the permission, fix the mail server address, wait,
 *  or do nothing because the fault is EzBizi's.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── This is a CAUSE, not a driver message ───────────────────────
 *
 * The provider's own text — "invalid_grant", "Precondition check failed",
 * `Illuminate\Http\Client\ConnectionException` — is not a cause and must never be
 * what somebody reads. It is written in the vocabulary of whoever wrote the API,
 * it changes without notice, and it says nothing about what to do. Each case here
 * carries {@see sentence}, which is what gets stored on the mailbox and read off
 * the screen: plain words, the cause named, and the next action (R8).
 *
 * The provider's text still has one use — a live response can append it for
 * whoever is debugging a connection they just made — and {@see CannotReadMailbox}
 * is where that happens. It is deliberately NOT part of what is stored: a
 * `status_reason` full of somebody else's error strings is a status screen nobody
 * can read.
 *
 * ── What cannot be told apart yet, said out loud ─────────────────
 *
 * IMAP contributes nothing to this list beyond "not connected", and that is not
 * an oversight. {@see ImapMailboxReader} cannot speak the protocol on this
 * installation at all, so there is no authentication attempt to classify — a
 * wrong password and a mail server that has moved are, from here, identical and
 * both unreachable. Inventing `PasswordRejected` for it would be inventing a
 * distinction nothing can produce.
 */
enum MailboxFault: string
{
    /** Nobody has signed in or entered a password yet. */
    /**
     * How many consecutive refused polls before {@see ProviderRefused} gives up
     * and asks for a person. Jose's number, 2026-09-14.
     */
    public const REFUSALS_BEFORE_BROKEN = 10;

    case NotConnected = 'not_connected';

    /**
     * The stored sign-in is no longer accepted — withdrawn, expired, or
     * overtaken by a password change, and the mail service will not say which.
     */
    case SignInNoLongerValid = 'sign_in_no_longer_valid';

    /** The sign-in is still good; it no longer permits reading this mailbox. */
    case PermissionNarrowed = 'permission_narrowed';

    /** The mail service asked us to slow down. Nothing is broken. */
    case TooManyRequests = 'too_many_requests';

    /** Nothing answered at all — no route, no reply, or a reply too slow to wait for. */
    case HostNotAnswering = 'host_not_answering';

    /** The mail server named on this mailbox is one EzBizi will not dial (SSRF guard). */
    case HostNotAllowed = 'host_not_allowed';

    /** The mail service answered with a fault of its own. */
    case ProviderFaulty = 'provider_faulty';

    /** Refused, without saying enough to name the cause. */
    case ProviderRefused = 'provider_refused';

    /** The mail service rejected EZBIZI'S OWN registration, not the tenant's mailbox. */
    case PlatformRejected = 'platform_rejected';

    /** EzBizi has nothing behind this provider on this installation. */
    case NotBuiltHere = 'not_built_here';

    /**
     * What somebody reads, and what gets stored on the mailbox.
     *
     * R8 throughout: it names the cause in plain words and ends with the next
     * action, and it never names a class, a status code, an OAuth error string or
     * a permission key. Every one of them also says what is happening to the mail
     * meanwhile, because the first question anybody asks on seeing a broken inbox
     * is whether messages are being lost. None of them are.
     */
    public function sentence(string $address): string
    {
        return match ($this) {
            self::NotConnected => sprintf(
                'Nothing has been connected to %s yet, so there is nothing for EzBizi to open. '
                .'Somebody with permission to manage mailboxes needs to finish connecting it. '
                .'Mail sent to the address is arriving normally in the meantime.',
                $address,
            ),

            /*
             * The three-in-one sentence, and the reason this enum has a docblock.
             *
             * It names withdrawn, expired and password-changed together and says
             * EzBizi cannot tell them apart, because the mail service does not
             * tell them apart. Naming one of them would be a guess, and the two
             * wrong guesses each send somebody to a different screen to fix
             * something that is not wrong.
             *
             * The remedy is the same for all three, which is what makes saying
             * so tolerable rather than a cop-out: connect the address again.
             */
            self::SignInNoLongerValid => sprintf(
                'The mail service will no longer accept the sign-in EzBizi holds for %s. It '
                .'does not say which of three things happened — somebody withdrew EzBizi\'s '
                .'access, the sign-in expired on its own, or the account password was changed '
                .'— and they look identical from here. The fix is the same for all three: '
                .'somebody with permission to manage mailboxes needs to connect this address '
                .'again. Mail is still arriving at it, and nothing has been lost.',
                $address,
            ),

            self::PermissionNarrowed => sprintf(
                'EzBizi is still signed in to %s, but is no longer allowed to read its mail — '
                .'the permission was narrowed at the mail service rather than removed. '
                .'Somebody with permission to manage mailboxes needs to connect it again and '
                .'allow reading when asked. Mail is still arriving at the address.',
                $address,
            ),

            self::TooManyRequests => sprintf(
                'The mail service asked EzBizi to slow down, so %s is not being shown rather '
                .'than shown half-loaded. Nothing is wrong with the address or the sign-in and '
                .'there is nothing to reconnect. Wait a minute and open it again.',
                $address,
            ),

            self::HostNotAnswering => sprintf(
                'The mail service for %s did not answer at all. That is the service or the '
                .'network between here and it, rather than anything about the address or its '
                .'sign-in, and it usually clears on its own — try again shortly. If it is '
                .'still saying this tomorrow, it is worth telling whoever runs that mail '
                .'service. Mail is still arriving at the address.',
                $address,
            ),

            self::HostNotAllowed => sprintf(
                'The mail server recorded for %s points somewhere EzBizi will not connect to, '
                .'so nothing was dialled. Somebody with permission to manage mailboxes needs '
                .'to correct the mail server details for this address.',
                $address,
            ),

            self::ProviderFaulty => sprintf(
                'The mail service for %s answered with a fault of its own, so EzBizi could not '
                .'open the inbox. Nothing is wrong with the address, its sign-in or its '
                .'permissions, and there is nothing to reconnect — try again shortly. Mail is '
                .'still arriving at the address.',
                $address,
            ),

            self::ProviderRefused => sprintf(
                'The mail service would not open %s, and did not say enough about why for '
                .'EzBizi to name the cause. One more attempt is worth making; if it keeps '
                .'happening, somebody with permission to manage mailboxes should connect the '
                .'address again. Mail is still arriving at it in the meantime.',
                $address,
            ),

            /*
             * Both of these are OURS, and the sentence has to say so first.
             *
             * The failure mode being avoided is specific: an administrator reads
             * "could not open the mailbox", changes the password on the account,
             * reconnects, watches it fail again, and rings support — having
             * broken a working mailbox on every other device they own. So the
             * sentence opens by putting the fault at EzBizi's end and states
             * plainly that nothing at theirs is wrong.
             */
            self::PlatformRejected => sprintf(
                'The mail service would not accept EzBizi\'s own registration, so no mailbox '
                .'on this installation can be opened at the moment — including %s. This is at '
                .'EzBizi\'s end. Nothing is wrong with the address, its password or its '
                .'permissions, and reconnecting it would not help.',
                $address,
            ),

            self::NotBuiltHere => sprintf(
                'EzBizi cannot open %s on this installation yet. This is at EzBizi\'s end and '
                .'not something that can be fixed from here — nothing is wrong with the '
                .'address or its password.',
                $address,
            ),
        };
    }

    /**
     * Is the fix at OUR end rather than the tenant's?
     *
     * Carried as a flag beside the sentence so a client can decide whether to
     * offer a "Connect again" button without reading English — and, more
     * importantly, so {@see RecordingMailboxReader} knows not to write "broken"
     * onto a tenant's row for something they did not do and cannot undo.
     */
    public function isOurs(): bool
    {
        return match ($this) {
            self::PlatformRejected, self::NotBuiltHere => true,
            default => false,
        };
    }

    /**
     * Does discovering this mean the mailbox itself should read BROKEN?
     *
     * Three answers, and the two `false` groups are the ones worth arguing:
     *
     *   NOT CONNECTED is not broken. It has never worked, so "it worked and now
     *   does not" is untrue, and marking it broken would replace the accurate
     *   "finish connecting this" with a scare.
     *
     *   TOO MANY REQUESTS is not broken either, and this is the one that would
     *   have been got wrong. A rate limit means EzBizi asked too often; the
     *   mailbox is perfectly healthy. Flagging it would send an administrator to
     *   reconnect a working inbox — real work, done for nothing, ending in the
     *   same rate limit.
     *
     *   OURS is never the tenant's row's problem. If this installation has no
     *   Google registration, every mailbox on it would go broken the first time
     *   anybody opened the tab, and the screen would be a wall of red describing
     *   a single fault at EzBizi's end.
     *
     *   PROVIDER FAULTY is not broken either, and leaving it here cost three
     *   weeks of production mail. Added 2026-09-11 after two mailboxes sat
     *   broken since the day they were made, carrying this case's own sentence:
     *   "there is nothing to reconnect -- try again shortly."
     *
     *   The argument is the TOO MANY REQUESTS argument, and it is stronger here,
     *   because marking this one broken MAKES ITS OWN REASON TRUE.
     *   `WatchMailboxes` polls `STATUS_CONNECTED` only -- deliberately, so a
     *   dead inbox is not dialled every five minutes forever. So writing
     *   "broken" for a fault that clears on its own removes the mailbox from
     *   the one mechanism that would ever notice it had cleared. The fault
     *   becomes permanent by being recorded, and the row then tells an
     *   administrator to reconnect an inbox whose own status_reason says there
     *   is nothing to reconnect. Both mailboxes still answered intermittently
     *   the whole time -- `last_reached_at` was hours old, not weeks -- because
     *   a page load still dials, and a page load that SUCCEEDS heals the row
     *   until the next one fails.
     *
     *   What this does NOT solve is a provider faulty for a month: the mailbox
     *   now reads connected and fails per load, which is honest but quiet. The
     *   column that answers it is `last_reached_at`, and nothing reads it for
     *   escalation yet.
     *
     *   HOST NOT ANSWERING is not broken either, and it is the same defect as
     *   PROVIDER FAULTY found by applying the rule instead of waiting for the
     *   symptom. Added 2026-09-11, the same afternoon, after the deploy desk
     *   suggested reading every arm's sentence as a PROMISE and asking which
     *   ones this method can keep. This one promises the most: "it usually
     *   clears on its own -- try again shortly."
     *
     *   It cannot clear on its own while it is recorded as broken, because
     *   `WatchMailboxes` polls CONNECTED only. And this arm is raised by a
     *   timeout, a refused connection or a name that does not resolve, from
     *   all three readers -- so before this change ONE network blip anywhere
     *   permanently disconnected a mailbox until a person went and pressed
     *   Reconnect, for a fault that was over by the time they read about it.
     *
     *   Its "if it is still saying this tomorrow" clause could not be true
     *   either: nothing retried it, so it could not still be saying anything
     *   on fresh evidence. HOST NOT ALLOWED is the case for a mail server that
     *   is genuinely wrong, and that one does mark broken, correctly.
     *
     * ── THE TEST THAT FOUND IT, FOR WHOEVER ADDS THE NEXT ARM ────
     *
     * Read your sentence as a promise, then ask whether this method can keep
     * it. A sentence that says wait, retry, or clears on its own is a promise
     * that `broken` VETOES, because broken is the one state the retry does not
     * look at. Three arms had already been written that way before anybody
     * noticed -- the prose was right every time and the status silently
     * overruled it.
     *
     * So the question for a new arm is not "is this serious". It is: WHOSE JOB
     * IS IT. If the sentence names a person, `broken` is correct and is how
     * they find out. If the sentence says it will pass, `broken` makes that
     * false by recording it.
     *
     * Everything else did work a moment ago and does not now, which is exactly
     * what Mailbox::STATUS_BROKEN was written to mean.
     *
     * ── PROVIDER REFUSED is the one arm with a BUDGET ────────────
     *
     * Its sentence is the only one that promises a specific number of
     * attempts -- "One more attempt is worth making; if it keeps happening,
     * somebody ... should connect the address again." So it is neither of the
     * other two shapes: not "wait, it passes" and not "a person must act", but
     * both in order. Jose set the number on 2026-09-14: ten.
     *
     * `$consecutiveFailures` is `Mailbox::$watch_failures`, which already
     * counts CONSECUTIVE failed polls and is already cleared by the first poll
     * that succeeds ({@see Mailbox::standOn}). So a mailbox that refuses once a
     * day and works in between never reaches ten and nobody is told -- which is
     * correct, because a mailbox that answers most of the time is not one
     * somebody needs to go and reconnect. What that does NOT cover is a mailbox
     * that is quietly degraded rather than failing outright; that is a reading
     * off `last_reached_at` and it does not exist yet.
     *
     * The counter is raised by `MailboxWatcher::backOff()`, so the budget is
     * spent by the TIMER rather than by page loads. Ten of those is roughly a
     * day, because the wait doubles each time to a six-hour cap.
     */
    public function marksBroken(int $consecutiveFailures = 0): bool
    {
        return match ($this) {
            self::NotConnected,
            self::TooManyRequests,
            self::ProviderFaulty,
            self::HostNotAnswering => false,

            self::ProviderRefused => $consecutiveFailures >= self::REFUSALS_BEFORE_BROKEN,

            default => ! $this->isOurs(),
        };
    }

    /**
     * The HTTP status a live read failing this way should answer with.
     *
     * Three families, and they are not interchangeable to a client. 409 means the
     * mailbox's own state needs somebody's attention — a reconnect screen is the
     * right thing to offer. 503 means try again and offer nothing. 501 means it
     * is ours and there is nothing for the tenant to press at all.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::NotConnected,
            self::SignInNoLongerValid,
            self::PermissionNarrowed,
            self::ProviderRefused => Response::HTTP_CONFLICT,

            self::HostNotAnswering,
            self::TooManyRequests,
            self::ProviderFaulty => Response::HTTP_SERVICE_UNAVAILABLE,

            // The value the SSRF guard already answers with. A refused host is a
            // fact about a value somebody typed, never a denial of permission.
            self::HostNotAllowed => Response::HTTP_UNPROCESSABLE_ENTITY,

            self::PlatformRejected,
            self::NotBuiltHere => Response::HTTP_NOT_IMPLEMENTED,
        };
    }
}
