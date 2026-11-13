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

    public function changeValues(string $boardId, string $itemId, array $values): void
    {
        $this->mutate('mutation($b:ID!,$i:ID!,$v:JSON!){ change_multiple_column_values(board_id:$b, item_id:$i, column_values:$v, create_labels_if_missing:true) { id } }',
            ['b' => $boardId, 'i' => $itemId, 'v' => json_encode((object) $values)]);
    }

    public function moveToGroup(string $itemId, string $groupId): void
    {
        $this->mutate('mutation($i:ID!,$g:String!){ move_item_to_group(item_id:$i, group_id:$g) { id } }', ['i' => $itemId, 'g' => $groupId]);
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
