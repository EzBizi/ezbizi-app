<?php

namespace App\Support\Email;

use App\Models\Mailbox;

/**
 * One picture out of an open message, as bytes.
 *
 * Remote http(s) goes through {@see RemotePictureFetcher} and the host guard.
 * `cid:` pictures are the ones attached to the message — Gmail has those;
 * other providers 404 until they do. A `data:` URI never reaches here: the
 * client already has the bytes on the block.
 */
final class EmailPictureLoader
{
    public function __construct(
        private readonly PictureTokens $tokens,
        private readonly RemotePictureFetcher $remote,
        private readonly GmailMailboxReader $gmail,
        private readonly GoogleWorkspace $workspace,
    ) {}

    public function load(Mailbox $mailbox, string $messageId, string $token): EmailPicture
    {
        $src = $this->tokens->reveal($token);

        if (str_starts_with(strtolower($src), 'cid:')) {
            return $this->cid($mailbox, $messageId, substr($src, 4));
        }

        return $this->remote->fetch($src);
    }

    private function cid(Mailbox $mailbox, string $messageId, string $contentId): EmailPicture
    {
        $provider = $mailbox->providerEnum();

        if ($provider === MailboxProvider::GmailWorkspace) {
            $this->workspace->assertCanOpen($mailbox);

            return $this->gmail->inlineImage($mailbox, $messageId, $contentId);
        }

        if ($provider === MailboxProvider::GmailOAuth) {
            return $this->gmail->inlineImage($mailbox, $messageId, $contentId);
        }

        throw new PictureUnavailable('That picture could not be loaded.');
    }
}
