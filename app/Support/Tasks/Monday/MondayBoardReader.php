<?php

namespace App\Support\Tasks\Monday;

use Carbon\CarbonImmutable;

/**
 * Reads a Monday board into the export shape MondayImport takes
 * (docs/agents/monday-sync-design-2026-11-12.md, "Export shape"). The same
 * rules as the 2026-11-12 export of the five personal boards:
 *
 * - the board status is the column titled "Status", else monday's main
 *   `status` column; other status columns become text columns;
 * - done = the label is one of that column's done labels (monday
 *   `done_colors`), and done wins over another status column's label;
 *   completed_at = that value's changed_at, else the item's updated_at;
 * - a date column titled "Due Date" is the due date, others date columns;
 * - a person who left Monday is "<id>@deleted.user" (the people map may name them).
 */
final class MondayBoardReader
{
    private const ASSET = 'assets { id name file_extension file_size public_url }';

    private const CV = 'column_values { id type text value ... on StatusValue { index label } ... on TimelineValue { from to } ... on DateValue { date } ... on PeopleValue { persons_and_teams { id kind } } }';

    /** @var array<string, array{name: ?string, email: ?string}>|null */
    private ?array $users = null;

    /** @var array<string, array<string, array{title: string, type: string, done: list<string>}>> board id => column id => meta */
    private array $columns = [];

    public function __construct(private readonly MondayClient $api) {}

    /** @return list<array{monday_id: string, name: ?string, email: ?string}> */
    public function users(): array
    {
        $this->loadUsers();

        return array_map(fn ($id, $u) => ['monday_id' => (string) $id, 'name' => $u['name'], 'email' => $u['email']], array_keys($this->users), $this->users);
    }

    /** @return array{monday_id: string, name: string, groups: list<array{monday_id: string, name: string, colour: ?string}>} */
    public function board(string $boardId): array
    {
        $b = $this->api->query('query($b:[ID!]){ boards(ids:$b) { id name groups { id title color position } columns { id title type settings_str } } }', ['b' => [$boardId]])['boards'][0] ?? null;
        if ($b === null) {
            throw new MondayRefused("Monday shows no board {$boardId} to this connection.");
        }
        $this->remember((string) $b['id'], $b['columns']);
        $groups = $b['groups'];
        usort($groups, fn ($x, $y) => (float) $x['position'] <=> (float) $y['position']);

        return [
            'monday_id' => (string) $b['id'],
            'name' => (string) $b['name'],
            'groups' => array_map(fn ($g) => ['monday_id' => (string) $g['id'], 'name' => (string) $g['title'], 'colour' => $g['color'] ?? null], $groups),
        ];
    }

    /** The board an item is on, or null when this connection cannot see it. */
    public function boardOf(string $itemId): ?string
    {
        $item = $this->api->query('query($i:[ID!]){ items(ids:$i) { id board { id } } }', ['i' => [$itemId]])['items'][0] ?? null;

        return isset($item['board']['id']) ? (string) $item['board']['id'] : null;
    }

    /**
     * Ids of the items on the board that changed at or after $since (all of
     * them when null); a subitem's change counts for its parent.
     *
     * @return array{ids: list<string>, latest: ?string}
     */
    public function changedSince(string $boardId, ?CarbonImmutable $since): array
    {
        $fields = 'cursor items { id updated_at subitems { id updated_at } }';
        $page = $this->api->query('query($b:[ID!]){ boards(ids:$b) { items_page(limit:500) { '.$fields.' } } }', ['b' => [$boardId]])['boards'][0]['items_page'] ?? ['items' => [], 'cursor' => null];
        $items = $page['items'];
        while (! empty($page['cursor'])) {
            $page = $this->api->query('query($c:String!){ next_items_page(limit:500, cursor:$c) { '.$fields.' } }', ['c' => $page['cursor']])['next_items_page'];
            array_push($items, ...$page['items']);
        }

        $ids = [];
        $latest = null;
        foreach ($items as $item) {
            $at = array_filter([$item['updated_at'] ?? null, ...array_column($item['subitems'] ?? [], 'updated_at')]);
            $newest = $at === [] ? null : max(array_map(fn ($s) => CarbonImmutable::parse($s)->utc()->toIso8601String(), $at));
            if ($newest !== null && ($latest === null || $newest > $latest)) {
                $latest = $newest;
            }
            if ($since === null || $newest === null || CarbonImmutable::parse($newest)->greaterThanOrEqualTo($since)) {
                $ids[] = (string) $item['id'];
            }
        }

        return ['ids' => $ids, 'latest' => $latest];
    }

    /**
     * The items, whole: column values, subitems, updates with replies and
     * their files (urls live an hour: MondayImport fetches them in the same run).
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function items(string $boardId, array $ids): array
    {
        $this->loadUsers();
        if (! isset($this->columns[$boardId])) {
            $this->board($boardId);
        }
        $updates = 'updates(limit:100) { id text_body created_at creator { id email name } '.self::ASSET.' replies { id text_body created_at creator { id email name } '.self::ASSET.' } }';
        $item = 'id name created_at updated_at group { id title } creator { id email name } '.self::CV.' '.$updates
            .' subitems { id name created_at updated_at creator { id email name } board { id } '.self::CV.' '.$updates.' }';

        $out = [];
        foreach (array_chunk($ids, 25) as $chunk) {
            $rows = $this->api->query('query($i:[ID!]){ items(ids:$i, limit:25) { '.$item.' } }', ['i' => $chunk])['items'] ?? [];
            $subBoards = array_values(array_unique(array_filter(array_map(fn ($s) => $s['board']['id'] ?? null, array_merge(...array_map(fn ($r) => $r['subitems'] ?? [], $rows ?: [[]]))))));
            $this->subitemColumns($subBoards);
            foreach ($rows as $row) {
                $group = (string) ($row['group']['title'] ?? 'Open Tasks');
                $converted = $this->convert($boardId, $row, $this->columns[$boardId], $group);
                foreach ($row['subitems'] ?? [] as $sub) {
                    $meta = $this->columns[(string) ($sub['board']['id'] ?? '')] ?? [];
                    $converted['subitems'][] = $this->convert($boardId, $sub, $meta, $group);
                }
                $out[] = $converted;
            }
        }

        return $out;
    }

    private function loadUsers(): void
    {
        if ($this->users !== null) {
            return;
        }
        $this->users = [];
        foreach ($this->api->query('{ users(limit:500) { id name email } }')['users'] ?? [] as $u) {
            $this->users[(string) $u['id']] = ['name' => $u['name'] ?? null, 'email' => $u['email'] ?? null];
        }
    }

    /** @param  list<string>  $boardIds */
    private function subitemColumns(array $boardIds): void
    {
        $missing = array_values(array_filter($boardIds, fn ($id) => ! isset($this->columns[$id])));
        if ($missing === []) {
            return;
        }
        foreach ($this->api->query('query($b:[ID!]){ boards(ids:$b) { id columns { id title type settings_str } } }', ['b' => $missing])['boards'] ?? [] as $b) {
            $this->remember((string) $b['id'], $b['columns']);
        }
    }

    private function remember(string $boardId, array $columns): void
    {
        $this->columns[$boardId] = [];
        foreach ($columns as $c) {
            $settings = $c['type'] === 'status' ? (json_decode((string) ($c['settings_str'] ?? '{}'), true) ?: []) : [];
            $this->columns[$boardId][(string) $c['id']] = [
                'title' => (string) $c['title'],
                'type' => (string) $c['type'],
                'done' => array_map('strval', (array) ($settings['done_colors'] ?? [])),
            ];
        }
    }

    /** The status columns in the order they decide: "Status" by title, then the main `status`. */
    private static function statusOrder(array $meta): array
    {
        $titled = array_keys(array_filter($meta, fn ($m) => $m['type'] === 'status' && mb_strtolower(trim($m['title'])) === 'status'));

        return array_values(array_unique([...$titled, ...(isset($meta['status']) ? ['status'] : [])]));
    }

    private function convert(string $boardId, array $item, array $meta, string $group): array
    {
        $values = [];
        foreach ($item['column_values'] ?? [] as $c) {
            $values[(string) $c['id']] = $c;
        }
        $out = [
            'monday_id' => (string) $item['id'], 'name' => (string) $item['name'], 'group' => $group,
            'created_at' => $item['created_at'] ?? null, 'updated_at' => $item['updated_at'] ?? null,
            'creator_email' => $item['creator']['email'] ?? null, 'status' => null, 'done' => false,
            'completed_at' => null, 'priority' => null, 'people' => [], 'due_on' => null, 'timeline' => null,
            'columns' => [], 'subitems' => [], 'updates' => $this->updates($item['updates'] ?? []),
        ];

        $order = self::statusOrder($meta);
        $doneBy = null;
        foreach ($order as $id) {
            $c = $values[$id] ?? null;
            $label = trim((string) ($c['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out['status'] ??= $label;
            if ($doneBy === null && isset($c['index']) && in_array((string) $c['index'], $meta[$id]['done'], true)) {
                $doneBy = $c;
            }
        }

        foreach ($values as $id => $c) {
            $m = $meta[$id] ?? null;
            if ($m === null || ($order !== [] && $id === $order[0])) {
                continue;
            }
            $title = $m['title'];
            $text = trim((string) ($c['text'] ?? ''));
            switch ($m['type']) {
                case 'people':
                    foreach ($c['persons_and_teams'] ?? [] as $p) {
                        if (($p['kind'] ?? 'person') === 'person') {
                            $out['people'][] = $this->users[(string) $p['id']]['email'] ?? ((string) $p['id'].'@deleted.user');
                        }
                    }
                    break;
                case 'date':
                    $date = (string) ($c['date'] ?? '');
                    if ($date === '') {
                        break;
                    }
                    if (mb_strtolower($title) === 'due date' && $out['due_on'] === null) {
                        $out['due_on'] = $date;
                    } else {
                        $out['columns'][$title] = ['type' => 'date', 'value' => $date];
                    }
                    break;
                case 'timeline':
                    if (! empty($c['from']) && ! empty($c['to'])) {
                        $out['timeline'] = ['from' => substr((string) $c['from'], 0, 10), 'to' => substr((string) $c['to'], 0, 10)];
                    }
                    break;
                case 'status':
                    $label = trim((string) ($c['label'] ?? ''));
                    if ($label !== '' && $label !== $out['status']) {
                        $out['columns'][mb_strtolower($title) === 'status' ? 'Status 2' : $title] = $label;
                    }
                    break;
                case 'numbers':
                    if ($text !== '') {
                        $out['columns'][$title] = ['type' => 'number', 'value' => $text];
                    }
                    break;
                case 'link':
                    $v = json_decode((string) ($c['value'] ?? ''), true) ?: [];
                    if (! empty($v['url'])) {
                        $out['columns'][$title] = empty($v['text']) || $v['text'] === $v['url'] ? $v['url'] : $v['text'].' ('.$v['url'].')';
                    }
                    break;
                case 'subtasks':
                case 'name':
                    break;
                default:
                    if ($text !== '') {
                        $out['columns'][$title] = (string) $c['text'];
                    }
            }
        }

        if ($doneBy !== null && $out['status'] !== trim((string) $doneBy['label'])) {
            // Done in one status column, something else in the other: done wins.
            $out['columns']['Status 2'] = $out['status'];
            $out['status'] = trim((string) $doneBy['label']);
        }
        $inDoneGroup = in_array(mb_strtolower(trim($group)), MondayImport::DONE_GROUPS, true);
        if ($doneBy !== null || ($inDoneGroup && $out['status'] === null)) {
            $out['done'] = true;
            $changed = $doneBy !== null ? (json_decode((string) ($doneBy['value'] ?? ''), true)['changed_at'] ?? null) : null;
            $out['completed_at'] = $changed ?: ($item['updated_at'] ?? null);
        }

        return $out;
    }

    private function updates(array $updates): array
    {
        $file = fn (array $a) => ['monday_id' => (string) $a['id'], 'name' => (string) $a['name'], 'ext' => mb_strtolower((string) ($a['file_extension'] ?? '')),
            'size' => (int) ($a['file_size'] ?? 0), 'url' => $a['public_url'] ?? null];
        $entry = fn (array $u) => [
            'monday_id' => (string) $u['id'], 'author_email' => $u['creator']['email'] ?? null, 'author_name' => $u['creator']['name'] ?? null,
            'created_at' => $u['created_at'] ?? null, 'body' => trim((string) ($u['text_body'] ?? '')), 'files' => array_map($file, $u['assets'] ?? []),
        ];
        usort($updates, fn ($a, $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));

        return array_map(function (array $u) use ($entry) {
            $replies = $u['replies'] ?? [];
            usort($replies, fn ($a, $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));

            return $entry($u) + ['replies' => array_map($entry, $replies)];
        }, $updates);
    }
}
