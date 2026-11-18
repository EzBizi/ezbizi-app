<?php

namespace App\Support\Tasks\Monday;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Monday's GraphQL API, the writing half: the mutations the Task Board → Monday
 * sync sends (Jose, 2026-11-13: two-way while both are in use, writes
 * authorized). MondayClient stays read only; only MondayPush and the webhook
 * registration hold one of these.
 */
final class MondayWriter
{
    public function __construct(private readonly string $token) {}

    /** @return array<string, mixed> the `data` of the answer */
    public function mutate(string $mutation, array $variables = []): array
    {
        if (! preg_match('/^\s*mutation\b/i', $mutation)) {
            throw new RuntimeException('MondayWriter sends mutations only; reads go through MondayClient.');
        }
        $response = Http::withHeaders(['Authorization' => $this->token])
            ->acceptJson()->timeout(60)
            ->post(MondayClient::API, ['query' => $mutation, 'variables' => (object) $variables]);

        if (in_array($response->status(), [401, 403], true)) {
            throw new MondayRefused(MondayRefused::RECONNECT);
        }
        $errors = $response->json('errors') ?? $response->json('error_message');
        if ($response->failed() || $errors) {
            throw new RuntimeException('Monday refused the write: '.mb_substr(json_encode($errors ?: $response->status()), 0, 300));
        }

        return (array) $response->json('data');
    }

    /** @return ?string the item's updated_at after the write (saves a read, desk 11-18) */
    public function changeValues(string $boardId, string $itemId, array $values): ?string
    {
        return $this->mutate('mutation($b:ID!,$i:ID!,$v:JSON!){ change_multiple_column_values(board_id:$b, item_id:$i, column_values:$v, create_labels_if_missing:true) { id updated_at } }',
            ['b' => $boardId, 'i' => $itemId, 'v' => json_encode((object) $values)])['change_multiple_column_values']['updated_at'] ?? null;
    }

    /** @return ?string the item's updated_at after the move */
    public function moveToGroup(string $itemId, string $groupId): ?string
    {
        return $this->mutate('mutation($i:ID!,$g:String!){ move_item_to_group(item_id:$i, group_id:$g) { id updated_at } }', ['i' => $itemId, 'g' => $groupId])['move_item_to_group']['updated_at'] ?? null;
    }

    /**
     * A group placed right after $afterGroupId. Never without one: Monday puts an
     * unplaced group at the TOP of the board (desk 11-18, Monday's create_group docs).
     */
    public function createGroup(string $boardId, string $name, string $afterGroupId): string
    {
        $data = $this->mutate('mutation($b:ID!,$n:String!,$r:String!){ create_group(board_id:$b, group_name:$n, relative_to:$r, position_relative_method:after_at) { id } }',
            ['b' => $boardId, 'n' => $name, 'r' => $afterGroupId]);

        return (string) ($data['create_group']['id'] ?? throw new RuntimeException('Monday made no group.'));
    }

    /** A text column, for a text column made here (the job-id columns, Jose 11-18). */
    public function createTextColumn(string $boardId, string $title): string
    {
        $data = $this->mutate('mutation($b:ID!,$t:String!){ create_column(board_id:$b, title:$t, column_type:text) { id } }', ['b' => $boardId, 't' => $title]);

        return (string) ($data['create_column']['id'] ?? throw new RuntimeException('Monday made no column.'));
    }

    /** Monday matches columns by id/title when no mapping is given. */
    public function moveToBoard(string $itemId, string $boardId, string $groupId): void
    {
        $this->mutate('mutation($i:ID!,$b:ID!,$g:ID!){ move_item_to_board(item_id:$i, board_id:$b, group_id:$g) { id } }', ['i' => $itemId, 'b' => $boardId, 'g' => $groupId]);
    }

    public function archiveItem(string $itemId): void
    {
        $this->mutate('mutation($i:ID!){ archive_item(item_id:$i) { id } }', ['i' => $itemId]);
    }

    public function createItem(string $boardId, ?string $groupId, string $name, array $values): string
    {
        $data = $this->mutate('mutation($b:ID!,$g:String,$n:String!,$v:JSON){ create_item(board_id:$b, group_id:$g, item_name:$n, column_values:$v, create_labels_if_missing:true) { id } }',
            ['b' => $boardId, 'g' => $groupId, 'n' => $name, 'v' => json_encode((object) $values)]);

        return (string) ($data['create_item']['id'] ?? throw new RuntimeException('Monday made no item.'));
    }

    public function createSubitem(string $parentId, string $name): string
    {
        $data = $this->mutate('mutation($p:ID!,$n:String!){ create_subitem(parent_item_id:$p, item_name:$n) { id board { id } } }', ['p' => $parentId, 'n' => $name]);

        return (string) ($data['create_subitem']['id'] ?? throw new RuntimeException('Monday made no subitem.'));
    }

    public function createUpdate(string $itemId, string $body): string
    {
        $data = $this->mutate('mutation($i:ID!,$b:String!){ create_update(item_id:$i, body:$b) { id } }', ['i' => $itemId, 'b' => $body]);

        return (string) ($data['create_update']['id'] ?? throw new RuntimeException('Monday made no update.'));
    }

    public function createWebhook(string $boardId, string $url, string $event): string
    {
        $data = $this->mutate('mutation($b:ID!,$u:String!,$e:WebhookEventType!){ create_webhook(board_id:$b, url:$u, event:$e) { id } }',
            ['b' => $boardId, 'u' => $url, 'e' => $event]);

        return (string) ($data['create_webhook']['id'] ?? throw new RuntimeException('Monday made no webhook.'));
    }
}
