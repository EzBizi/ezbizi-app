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
final class WorkspaceMailboxReader implements MailboxReader
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

    public function open(Mailbox $mailbox, string $messageId): MessageDetail
    {
        $this->workspace->assertCanOpen($mailbox);

        return $this->gmail->open($mailbox, $messageId);
    }

    public function reply(Mailbox $mailbox, string $messageId, string $body): void
    {
        $this->workspace->assertCanOpen($mailbox);

        $this->gmail->reply($mailbox, $messageId, $body);
    }

    public function trash(Mailbox $mailbox, string $messageId): void
    {
        $this->workspace->assertCanOpen($mailbox);

        $this->gmail->trash($mailbox, $messageId);
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
}
