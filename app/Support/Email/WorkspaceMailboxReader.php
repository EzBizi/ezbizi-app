<?php

namespace App\Support\Email;

use App\Models\Mailbox;

/**
 * A shared inbox opened through a connected Google Workspace, not a per-inbox sign-in.
 *
 * The Gmail REST calls are the same ones {@see GmailMailboxReader} already
 * makes. What changes is the token: domain-wide delegation impersonates the
 * address instead of spending a refresh token stored on the mailbox. This class
 * is the catalogue's answer for `gmail_workspace` (`notReadyBecause` is the
 * service account, not the OAuth client) and the preflight that refuses an
 * address whose domain is not the one this organization connected.
 *
 * Mail is still live. The mailbox row holds no credential.
 */
final class WorkspaceMailboxReader implements LabelsThreads, MailboxReader, RepliesWithCopies
{
    public function __construct(
        private readonly GoogleWorkspace $workspace,
        private readonly GmailMailboxReader $gmail,
    ) {}

    public function notReadyBecause(): ?string
    {
        return $this->workspace->notReadyBecause();
    }

    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        return $this->workspace->unavailableBecause($mailbox);
    }

    public function isConfigured(Mailbox $mailbox): bool
    {
        return $this->unavailableBecause($mailbox) === null;
    }

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        $this->workspace->assertCanOpen($mailbox);

        return $this->gmail->list($mailbox, $cursor, $limit, $label);
    }

    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        $this->workspace->assertCanOpen($mailbox);

        return $this->gmail->search($mailbox, $query, $cursor, $limit, $label);
    }

    public function open(Mailbox $mailbox, string $messageId, ?string $threadId = null): MessageDetail
    {
        $this->workspace->assertCanOpen($mailbox);

        return $this->gmail->open($mailbox, $messageId, $threadId);
    }

    public function reply(Mailbox $mailbox, string $messageId, string $body): void
    {
        $this->workspace->assertCanOpen($mailbox);

        $this->gmail->reply($mailbox, $messageId, $body);
    }

    public function canMarkReadAtSource(Mailbox $mailbox): bool
    {
        return $this->gmail->canMarkReadAtSource($mailbox);
    }

    public function markReadAtSource(Mailbox $mailbox, string $messageId): void
    {
        $this->workspace->assertCanOpen($mailbox);

        $this->gmail->markReadAtSource($mailbox, $messageId);
    }

    public function replyWith(Mailbox $mailbox, string $messageId, string $body, array $cc, bool $all): void
    {
        $this->workspace->assertCanOpen($mailbox);

        $this->gmail->replyWith($mailbox, $messageId, $body, $cc, $all);
    }

    public function modifyThread(Mailbox $mailbox, string $messageId, array $add, array $remove): void
    {
        $this->workspace->assertCanOpen($mailbox);

        $this->gmail->modifyThread($mailbox, $messageId, $add, $remove);
    }

    public function markUnreadAtSource(Mailbox $mailbox, string $messageId): void
    {
        $this->workspace->assertCanOpen($mailbox);

        $this->gmail->markUnreadAtSource($mailbox, $messageId);
    }

    public function availableFolders(Mailbox $mailbox): array
    {
        $this->workspace->assertCanOpen($mailbox);

        return $this->gmail->availableFolders($mailbox);
    }

    public function send(Mailbox $mailbox, array $to, string $subject, string $body, array $attachments = []): void
    {
        $this->workspace->assertCanOpen($mailbox);

        $this->gmail->send($mailbox, $to, $subject, $body, $attachments);
    }

    public function trash(Mailbox $mailbox, string $messageId): void
    {
        $this->workspace->assertCanOpen($mailbox);

        $this->gmail->trash($mailbox, $messageId);
    }

    public function downloadAttachment(Mailbox $mailbox, string $messageId, string $attachmentId): EmailPicture
    {
        $this->workspace->assertCanOpen($mailbox);

        return $this->gmail->downloadAttachment($mailbox, $messageId, $attachmentId);
    }

    public function currentMark(Mailbox $mailbox): string
    {
        $this->workspace->assertCanOpen($mailbox);

        return $this->gmail->currentMark($mailbox);
    }

    public function arrivalsSince(Mailbox $mailbox, string $mark, int $limit): Arrivals
    {
        $this->workspace->assertCanOpen($mailbox);

        return $this->gmail->arrivalsSince($mailbox, $mark, $limit);
    }

    public function unreadCount(Mailbox $mailbox): int
    {
        $this->workspace->assertCanOpen($mailbox);

        return $this->gmail->unreadCount($mailbox);
    }
}
