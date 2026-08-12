<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use InvalidArgumentException;

/**
 * Which reader opens which mailbox.
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
 * seam with a spare file. Left open deliberately, and narrow: `for()` is the
 * only method, and it decides one thing.
 */
class MailboxReaders
{
    public function for(Mailbox $mailbox): MailboxReader
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

        return match ($provider) {
            MailboxProvider::GmailOAuth => app(GmailMailboxReader::class),
            MailboxProvider::Imap       => app(ImapMailboxReader::class),
        };
    }
}
