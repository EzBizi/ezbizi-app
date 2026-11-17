<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Monday's GraphQL API, answered from $this->monday: users, boards (meta and
 * raw items as Monday returns them). Any mutation fails the test: EzBizi
 * does not write to Monday.
 */
trait FakesMonday
{
    /** @var array{users: list<array>, boards: array<string, array>, me?: array} */
    protected array $monday = ['users' => [], 'boards' => []];

    /** @var list<string> every query sent */
    protected array $mondayQueries = [];

    protected function fakeMonday(array $extra = []): void
    {
        Http::fake($extra + [
            'api.monday.com/*' => function (Request $request) {
                $query = (string) ($request->data()['query'] ?? '');
                $vars = (array) ($request->data()['variables'] ?? []);
                $this->mondayQueries[] = $query;
                $this->assertDoesNotMatchRegularExpression('/^\s*mutation\b/i', $query, 'EzBizi wrote to Monday');
                if (! empty($this->monday['refuse'])) {
                    return Http::response(['errors' => [['message' => 'Not Authenticated']]], 401);
                }

                return Http::response(['data' => $this->mondayAnswer($query, $vars)]);
            },
        ]);
    }

    private function mondayAnswer(string $query, array $vars): array
    {
        $boards = $this->monday['boards'];
        $allItems = fn () => array_merge(...array_values(array_map(fn ($b) => $b['items'], $boards)) ?: [[]]);

        return match (true) {
            str_contains($query, '{ me ') => ['me' => $this->monday['me'] ?? ['id' => '1', 'name' => 'Jose Demasi', 'email' => 'jose@a-1chimney.com', 'account' => ['id' => 'acc1', 'name' => 'A-1 Group', 'slug' => 'a-1group']]],
            str_contains($query, 'users(') => ['users' => $this->monday['users']],
            str_contains($query, 'workspaces(') => ['workspaces' => [['id' => '1']]],
            str_contains($query, 'folders(') => ['folders' => $this->monday['folders'] ?? []],
            str_contains($query, 'next_items_page') => ['next_items_page' => ['cursor' => null, 'items' => []]],
            str_contains($query, 'items_page') => ['boards' => [['items_page' => ['cursor' => null, 'items' => array_values(array_map(
                fn ($i) => ['id' => $i['id'], 'updated_at' => $i['updated_at'], 'subitems' => array_map(fn ($s) => ['id' => $s['id'], 'updated_at' => $s['updated_at']], $i['subitems'] ?? [])],
                // Monday's __last_updated__ filter, by the day (greater_than_or_equals ["EXACT", "Y-m-d"]).
                array_filter($boards[(string) $vars['b'][0]]['items'] ?? [], fn ($i) => ! isset($vars['d'][1]) || substr((string) $i['updated_at'], 0, 10) >= $vars['d'][1]),
            ))]]]],
            str_contains($query, 'items(ids:$i) { id board') => ['items' => array_values(array_filter(array_map(
                fn ($i) => in_array((string) $i['id'], array_map('strval', $vars['i']), true) ? ['id' => $i['id'], 'board' => ['id' => $i['_board']]] : null,
                $allItems(),
            )))],
            str_contains($query, 'items(ids:$i, limit:25)') => ['items' => array_values(array_filter(
                $allItems(),
                fn ($i) => in_array((string) $i['id'], array_map('strval', $vars['i']), true),
            ))],
            str_contains($query, '{ id name groups') => ['boards' => array_map(fn ($id) => [
                'id' => $id, 'name' => $boards[$id]['name'], 'groups' => $boards[$id]['groups'], 'columns' => $boards[$id]['columns'],
                'views' => $boards[$id]['views'] ?? [], 'board_folder_id' => $boards[$id]['folder'] ?? null,
                'owners' => $boards[$id]['owners'] ?? [], 'subscribers' => $boards[$id]['subscribers'] ?? [],
            ], array_values(array_filter(array_map('strval', $vars['b']), fn ($id) => isset($boards[$id]))))],
            str_contains($query, '{ id columns') => ['boards' => array_map(fn ($id) => ['id' => $id, 'columns' => $this->monday['subitem_columns'] ?? []], array_map('strval', $vars['b']))],
            default => [],
        };
    }

    /** A Monday board with Person, Status (Done is done) and Due Date. */
    protected function mondayBoard(string $id, string $name, array $items): array
    {
        return [
            'name' => $name,
            'groups' => [['id' => 'topics', 'title' => 'Open Tasks', 'color' => '#579bfc', 'position' => '1'], ['id' => 'done', 'title' => 'Completed Tasks', 'color' => '#00c875', 'position' => '2']],
            'columns' => [
                ['id' => 'name', 'title' => 'Name', 'type' => 'name', 'settings_str' => '{}'],
                ['id' => 'person', 'title' => 'Person', 'type' => 'people', 'settings_str' => '{}'],
                ['id' => 'status', 'title' => 'Status', 'type' => 'status', 'settings_str' => json_encode(['labels' => ['0' => 'Working on it', '1' => 'Done', '2' => 'Stuck'], 'done_colors' => [1]])],
                ['id' => 'date4', 'title' => 'Due Date', 'type' => 'date', 'settings_str' => '{}'],
            ],
            'items' => array_map(fn ($i) => $i + ['_board' => $id], $items),
        ];
    }

    /** A raw Monday item: status by label, people by monday user id. */
    protected function mondayItem(string $id, string $name, string $updatedAt, array $o = []): array
    {
        $labels = ['Working on it' => 0, 'Done' => 1, 'Stuck' => 2];
        $status = $o['status'] ?? 'Working on it';

        return [
            'id' => $id, 'name' => $name, 'created_at' => $o['created_at'] ?? '2026-11-01T09:00:00Z', 'updated_at' => $updatedAt,
            'group' => ['id' => 'topics', 'title' => $o['group'] ?? 'Open Tasks'],
            'creator' => ['id' => '1', 'email' => 'jose@a-1chimney.com', 'name' => 'Jose Demasi'],
            'column_values' => [
                ['id' => 'person', 'type' => 'people', 'text' => '', 'value' => null, 'persons_and_teams' => array_map(fn ($p) => ['id' => $p, 'kind' => 'person'], $o['people'] ?? ['1'])],
                ['id' => 'status', 'type' => 'status', 'text' => $status, 'label' => $status, 'index' => $labels[$status] ?? null,
                    'value' => json_encode(['index' => $labels[$status] ?? null, 'changed_at' => $o['changed_at'] ?? $updatedAt])],
                ['id' => 'date4', 'type' => 'date', 'text' => $o['due'] ?? '', 'date' => $o['due'] ?? null, 'value' => null],
            ],
            'updates' => $o['updates'] ?? [],
            'subitems' => $o['subitems'] ?? [],
        ];
    }

    protected function mondayUpdate(string $id, string $body, string $at, array $replies = []): array
    {
        return ['id' => $id, 'text_body' => $body, 'created_at' => $at, 'creator' => ['id' => '1', 'email' => 'jose@a-1chimney.com', 'name' => 'Jose Demasi'],
            'assets' => [], 'replies' => $replies];
    }
}
