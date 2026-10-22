<?php

namespace App\Support\Email;

use App\Models\Mailbox;

/**
 * Reply with copies, or to everybody on the message (design 137). Gmail and
 * Workspace inboxes; a caller asks `instanceof` and says so otherwise.
 */
interface RepliesWithCopies
{
    /**
     * @param  list<string>  $cc
     *
     * @throws CannotReadMailbox
     */
    public function replyWith(Mailbox $mailbox, string $messageId, string $body, array $cc, bool $all): void;
}
