<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Which reader opens which mailbox — and the one funnel every read goes through.
 *
 * One `match` on the provider, in one place, so that a controller never branches
 * on `provider === 'imap'`. The moment it does, every screen has to be edited
 * again when a third provider arrives — and the branch that gets forgotten is
 * always the one in the error path.
 *
 * Resolved through the container rather than constructed here, so a test can
 * swap either reader for a fake and so the readers may take dependencies of
 * their own — {@see GmailMailboxReader} needs the mapper,
 * {@see ImapMailboxReader} needs {@see RemoteHostGuard}.
 *
 * ── Everything handed out is WRAPPED ─────────────────────────────
 *
 * {@see for} never returns a bare reader. It returns a
 * {@see RecordingMailboxReader} with the real one inside, which is what records
 * `last_reached_at` when a call succeeds and writes the sentence on
 * `status_reason` when one fails.
 *
 * This class is where that belongs precisely because it is already the single
 * answer to "which reader opens this mailbox". A reader that recorded its own
 * health would be correct until the third provider was written by somebody
 * reading the Graph API documentation rather than this file; wrapped here, a new
 * provider is one arm of the match below and gets the bookkeeping by existing.
 */
/*
 * Not `final`, unlike the rest of this namespace.
 *
 * Every other class here is closed because there is no reason to extend it. This
 * one is the seam a test replaces to get a mailbox that ANSWERS: both real
 * readers refuse on this installation — no Google OAuth client, no IMAP library
 * — so without a substitutable resolver, nothing about grants, reach, seen-by or
 * replies could be exercised at all.
 *
 * The alternative was an interface with one implementation, which is the same
 * seam with a spare file. Left open deliberately, and narrowed since: the seam is
 * now {@see readerFor}, and {@see for} is `final` so that substituting a reader
 * cannot also — accidentally, by overriding the obvious method — substitute away
 * the health recording. A test double should decide what the mail service says,
 * never whether EzBizi writes down what it said.
 */
class MailboxReaders
{
    /**
     * The reader for this mailbox, with health recording around it.
     *
     * `final`: see the note above the class. Every caller in the application goes
     * through here, so this is the one place that can guarantee no read is
     * unrecorded, and that guarantee is only worth having if it cannot be
     * overridden by a subclass that meant to do something else.
     *
     * The wrapper's two collaborators are resolved rather than injected, for the
     * same reason the readers below are: this class is SUBCLASSED by test doubles
     * that construct themselves with `new`, and a constructor here would make
     * every one of them carry two arguments it has no opinion about.
     */
    final public function for(Mailbox $mailbox): MailboxReader
    {
        return new RecordingMailboxReader(
            $this->readerFor($mailbox),
            app(TenantContext::class),
            app(EventEmitter::class),
        );
    }

    /**
     * Which real reader speaks this mailbox's protocol.
     *
     * `protected` because this is the seam: a test that needs a mailbox which
     * ANSWERS overrides this one method and returns a fake, and still gets the
     * recording wrapper around it — so what the fake changes is the mail service,
     * which is the only thing a test has any business changing here.
     */
    protected function readerFor(Mailbox $mailbox): MailboxReader
    {
        $provider = $mailbox->providerEnum();

        if ($provider === null) {
            /*
             * A stored value this build does not recognise.
             *
             * Refused rather than defaulted to either reader. Falling through to
             * one of them would open a mailbox with the wrong protocol against
             * whatever credential is on the row, and the failure would be a
             * confusing authentication error rather than the true one — that
             * this row was written by a version of the software that knew about
             * a provider this one does not.
             */
            throw new InvalidArgumentException(sprintf(
                'Mailbox %s names provider "%s", which this build does not know how to open.',
                $mailbox->getKey(),
                (string) $mailbox->provider,
            ));
        }

        return $this->forProvider($provider);
    }

    /**
     * The reader for a provider, with no mailbox in hand.
     *
     * For the one question that is about the INSTALLATION rather than about an
     * address: `notReadyBecause()`. The screen where somebody adds their first
     * inbox has no mailbox to ask about, and before this existed it guessed
     * from `needs_host` instead - which is how IMAP came to offer a live
     * password form on a box whose PHP has no imap extension.
     *
     * Deliberately NOT wrapped in RecordingMailboxReader. That decorator exists
     * to stamp `last_reached_at` after a successful call to a provider, and
     * nothing reached here opens a socket.
     */
    public function forProvider(MailboxProvider $provider): MailboxReader
    {
        return match ($provider) {
            MailboxProvider::GmailOAuth => app(GmailMailboxReader::class),
            MailboxProvider::Imap       => app(ImapMailboxReader::class),
        };
    }
}
