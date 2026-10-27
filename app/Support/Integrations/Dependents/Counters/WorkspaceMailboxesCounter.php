<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\Mailbox;
use App\Support\Email\MailboxProvider;
use App\Support\Integrations\Dependents\Feature;
use App\Support\Integrations\Dependents\IntegrationRef;
use Illuminate\Database\Eloquent\Builder;

/** Shared inboxes opened through the Workspace's service account. */
final class WorkspaceMailboxesCounter extends RowCounter
{
    protected function key(): string
    {
        return 'workspace_mailboxes';
    }

    protected function applies(IntegrationRef $ref): bool
    {
        return $ref->kind === 'workspace';
    }

    protected function query(IntegrationRef $ref): Builder
    {
        $query = Mailbox::query()->where('provider', MailboxProvider::GmailWorkspace->value);

        // An organization-level Workspace serves every inbox; a company or
        // franchise one serves the inboxes filed at that level.
        if ($ref->model->scope_type !== Mailbox::SCOPE_ORGANIZATION) {
            $query->where('scope_type', $ref->model->scope_type)->where('scope_id', $ref->model->scope_id);
        }

        return $query;
    }

    protected function label(int $count): string
    {
        return self::plural($count, 'shared inbox stops syncing', 'shared inboxes stop syncing');
    }

    public function features(IntegrationRef $ref): array
    {
        return $ref->kind === 'workspace'
            ? [Feature::breaks('Email: shared inboxes stop syncing and cannot send')]
            : [];
    }
}
