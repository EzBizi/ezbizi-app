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
        return $this->loadSrc($mailbox, $messageId, $this->tokens->reveal($token));
    }

    public function loadSrc(Mailbox $mailbox, string $messageId, string $src): EmailPicture
    {
        if (str_starts_with(strtolower($src), 'cid:')) {
            return $this->cid($mailbox, $messageId, substr($src, 4));
        }

        return $this->remote->fetch($src);
    }

    /**
     * Several at once: remote pictures fetched in parallel, `cid:` ones one by
     * one (they come from the mail service, and none of the production mail
     * measured on 2026-10-06 used them).
     *
     * @param  array<array-key, string>  $srcs
     * @return array<array-key, EmailPicture|null>  same keys; null = could not be loaded
     */
    public function loadMany(Mailbox $mailbox, string $messageId, array $srcs): array
    {
        $out = [];
        $remote = [];

        foreach ($srcs as $key => $src) {
            if (! str_starts_with(strtolower($src), 'cid:')) {
                $remote[$key] = $src;

                continue;
            }

            try {
                $out[$key] = $this->cid($mailbox, $messageId, substr($src, 4));
            } catch (PictureUnavailable|CannotReachHost|CannotReadMailbox|GoogleWorkspaceRefused) {
                $out[$key] = null;
            }
        }

        return array_replace(array_map(fn () => null, $srcs), $out, $this->remote->fetchMany($remote));
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
