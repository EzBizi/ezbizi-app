<?php

namespace App\Support\Email;

use App\Models\Mailbox;

/**
 * A reader whose provider has labels on conversations (Gmail): star,
 * archive, move. Not on MailboxReader because IMAP and Outlook have no such
 * thing; a caller asks `instanceof` and says so when it is not.
 */
interface LabelsThreads
{
    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     *
     * @throws CannotChangeMail
     */
    public function modifyThread(Mailbox $mailbox, string $messageId, array $add, array $remove): void;
}
