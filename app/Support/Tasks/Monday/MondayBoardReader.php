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

    /**
     * Every value type the import carries (measured on the live API 2026-11-13:
     * formula, mirror, connect-boards and dependency answer only through
     * display_value; a file column through its assets).
     */
    private const CV = 'column_values { id type text value ... on StatusValue { index label } ... on TimelineValue { from to } ... on DateValue { date time } ... on PeopleValue { persons_and_teams { id kind } }'
        .' ... on DropdownValue { values { id label } } ... on TagsValue { tags { id name } } ... on CheckboxValue { checked }'
        .' ... on MirrorValue { display_value } ... on BoardRelationValue { display_value linked_item_ids } ... on DependencyValue { display_value linked_item_ids }'
        .' ... on LocationValue { address lat lng }'
        .' ... on FormulaValue { display_value } ... on EmailValue { email } ... on PhoneValue { phone } ... on LinkValue { url url_text }'
        .' ... on FileValue { files { ... on FileAssetValue { asset { id name file_extension file_size public_url } } ... on FileLinkValue { name url } } } }';

    /** Monday's folder colour names, as the hex its sidebar paints. */
    public const FOLDER_COLOURS = [
        'DONE_GREEN' => '#00c875', 'WORKING_ORANGE' => '#fdab3d', 'STUCK_RED' => '#df2f4a', 'DARK_ORANGE' => '#ff6d3b',
        'AQUAMARINE' => '#4eccc6', 'DARK_RED' => '#bb3354', 'SUNSET' => '#ff7575', 'BRIGHT_GREEN' => '#9cd326',
        'DARK_PURPLE' => '#784bd1', 'PURPLE' => '#9d50dd', 'BRIGHT_BLUE' => '#579bfc', 'DARK_BLUE' => '#0086c0',
        'CHILI_BLUE' => '#66ccff', 'BERRY' => '#7e3b8a', 'LIPSTICK' => '#ff5ac4', 'BUBBLE' => '#faa1f1', 'AMERICAN_GRAY' => '#808080',
        'PECAN' => '#563e3e', 'SOFT_BLACK' => '#333333', 'RIVER' => '#68a1bd', 'STEEL' => '#a9bee8', 'LIGHT_PINK' => '#ff158a',
        'SATURATED_YELLOW' => '#ffcb00', 'EGG_YOLK' => '#ffcb00', 'GRASS_GREEN' => '#037f4c', 'BRIGHT_YELLOW' => '#cab641',
    ];

    /**
     * Monday column type => Task Board column type (null: not a column here —
     * the name, the board status, the assignee column, subitems, files, and
     * the read-only creation log / last updated / item id, which the task's
     * own created/updated fields already show). Read-only Monday values
     * (formula, mirror, connect boards, dependency, location…) arrive as the
     * words Monday shows until the Task Board grows that type.
     */
    public const TYPES = [
        'status' => 'status', 'color' => 'status', 'dropdown' => 'tags', 'tags' => 'tags',
        'people' => 'people', 'multiple-person' => 'people', 'date' => 'date', 'timeline' => 'timeline', 'timerange' => 'timeline',
        'numbers' => 'number', 'numeric' => 'number', 'rating' => 'rating', 'checkbox' => 'checkbox', 'boolean' => 'checkbox',
        'link' => 'url', 'email' => 'email', 'phone' => 'phone', 'long_text' => 'long_text', 'long-text' => 'long_text', 'text' => 'text',
        // Monday's remaining types (UI-UX, 4d849c594 / 0.0.0572).
        'location' => 'location', 'file' => 'files', 'formula' => 'formula', 'board_relation' => 'connect', 'dependency' => 'dependency',
        'creation_log' => 'creation_log', 'last_updated' => 'last_updated',
        'name' => null, 'subtasks' => null, 'item_id' => null, 'pulse-id' => null, 'button' => null,
    ];

    /** @var array<string, string> board id => the people column whose people are the task's assignees */
    private array $assignee = [];

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
        $b = $this->api->query('query($b:[ID!]){ boards(ids:$b, state:all) { id name groups { id title color position } columns { id title type settings_str } views { id name type } owners { id } subscribers { id } board_folder_id } }', ['b' => [$boardId]])['boards'][0] ?? null;
        if ($b === null) {
            throw new MondayRefused("Monday shows no board {$boardId} to this connection.");
        }
        $id = (string) $b['id'];
        $this->remember($id, $b['columns']);
        $groups = $b['groups'];
        usort($groups, fn ($x, $y) => (float) $x['position'] <=> (float) $y['position']);
        $order = self::statusOrder($this->columns[$id]);

        return [
            'monday_id' => $id,
            'name' => (string) $b['name'],
            'groups' => array_map(fn ($g) => ['monday_id' => (string) $g['id'], 'name' => (string) $g['title'], 'colour' => $g['color'] ?? null], $groups),
            // The board status's labels, in Monday's order, with colour and done.
            'statuses' => $order === [] ? [] : self::labels($this->columns[$id][$order[0]]),
            'columns' => $this->columnsOf($id),
            'views' => self::views($b['views'] ?? []),
            // Who the board is shared with on Monday: owners, then subscribers (a custom board's owners and members).
            'members' => $this->membersOf($b),
            // Monday's workspace folder, root first, each with its colour (the Task Board list's folders).
            'folder' => $this->folderPath(isset($b['board_folder_id']) ? (string) $b['board_folder_id'] : null),
        ];
    }

    /** @return list<array{email: string, role: string}> */
    /** @var array<string, array{name: string, colour: ?string, parent: ?string}>|null monday folder id => folder */
    private ?array $folders = null;

    /**
     * A Monday folder and its parents, root first, as [{name, colour}]
     * (colour as hex, FOLDER_COLOURS). Empty for a board at a workspace's root.
     *
     * @return list<array{name: string, colour: ?string}>
     */
    public function folderPath(?string $folderId): array
    {
        if ($folderId === null || $folderId === '') {
            return [];
        }
        if ($this->folders === null) {
            $this->folders = [];
            $workspaces = array_column($this->api->query('{ workspaces(limit:100) { id } }')['workspaces'] ?? [], 'id');
            $rows = $workspaces === [] ? [] : ($this->api->query('query($w:[ID]){ folders(workspace_ids:$w, limit:100) { id name color parent { id } } }', ['w' => $workspaces])['folders'] ?? []);
            foreach ($rows as $f) {
                $this->folders[(string) $f['id']] = ['name' => (string) $f['name'], 'colour' => self::FOLDER_COLOURS[(string) ($f['color'] ?? '')] ?? null,
                    'parent' => isset($f['parent']['id']) ? (string) $f['parent']['id'] : null];
            }
        }
        $path = [];
        for ($id = $folderId; $id !== null && isset($this->folders[$id]) && count($path) < 10; $id = $this->folders[$id]['parent']) {
            array_unshift($path, ['name' => $this->folders[$id]['name'], 'colour' => $this->folders[$id]['colour']]);
        }

        return $path;
    }

    private function membersOf(array $b): array
    {
        $this->loadUsers();
        $out = [];
        foreach ([['owners', 'owner'], ['subscribers', 'member']] as [$key, $role]) {
            foreach ($b[$key] ?? [] as $u) {
                $email = $this->users[(string) $u['id']]['email'] ?? null;
                if ($email !== null && ! isset($out[$email])) {
                    $out[$email] = ['email' => $email, 'role' => $role];
                }
            }
        }

        return array_values($out);
    }

    /**
     * The board's other columns, in Monday's order, as Task Board columns:
     * {monday_id, title, type, settings} — a status or dropdown column with its
     * labels and colours as options.
     *
     * @return list<array{monday_id: string, title: string, type: string, settings: array<string, mixed>}>
     */
    public function columnsOf(string $boardId, array $skipTitles = []): array
    {
        $meta = $this->columns[$boardId] ?? [];
        $order = self::statusOrder($meta);
        $out = [];
        $titles = [];
        foreach ($meta as $id => $m) {
            $type = array_key_exists($m['type'], self::TYPES) ? self::TYPES[$m['type']] : 'text';
            if ($type === null || ($order !== [] && $id === $order[0]) || $id === ($this->assignee[$boardId] ?? null)) {
                continue;
            }
            if ($type === 'date' && mb_strtolower(trim($m['title'])) === 'due date' && ! isset($titles['due date'])) {
                $titles['due date'] = true; // the task's due date, not a column

                continue;
            }
            $title = self::title($m, $order);
            $settings = [];
            if ($type === 'status' || $type === 'tags') {
                $settings['options'] = array_map(fn ($l) => ['id' => $l['id'], 'label' => $l['label'], 'colour' => $l['colour']], self::labels($m));
            }
            if ($m['type'] === 'numbers' || $m['type'] === 'numeric') {
                $symbol = (string) ($m['settings']['unit']['symbol'] ?? '');
                if ($symbol === '$') {
                    $type = 'currency';
                } elseif ($symbol !== '') {
                    $settings['unit'] = $symbol === 'custom' ? (string) ($m['settings']['unit']['custom_unit'] ?? '') : $symbol;
                }
            }
            if ($m['type'] === 'formula') {
                // Monday's syntax, each {column id} as [the column's name here] (UI-UX's formula, 4d849c594).
                $formula = (string) ($m['settings']['formula'] ?? '');
                $settings['monday_formula'] = $formula;
                $settings['expression'] = mb_substr((string) preg_replace_callback('/\{([a-z0-9_]+)\}/i',
                    fn ($x) => isset($meta[$x[1]]) ? '['.self::title($meta[$x[1]], $order).']' : $x[0], $formula), 0, 2000);
            }
            if ($m['type'] === 'rating') {
                $settings['max'] = max(1, min(10, (int) ($m['settings']['limit'] ?? $m['settings']['max'] ?? 5)));
            }
            if ($type === 'text' && ! in_array($m['type'], ['text'], true)) {
                $settings['monday_type'] = $m['type'];
            }
            $out[] = ['monday_id' => (string) $id, 'title' => $title, 'type' => $type, 'settings' => $settings];
        }

        return $out;
    }

    /**
     * A status/dropdown column's labels, in the order Monday shows them, with
     * a stable id (monday-<index>), colour and done.
     *
     * @return list<array{id: string, label: string, colour: ?string, done: bool}>
     */
    public static function labels(array $m): array
    {
        $s = $m['settings'] ?? [];
        $labels = $s['labels'] ?? [];
        $rows = [];
        if (is_array($labels) && array_is_list($labels) && is_array($labels[0] ?? null)) { // dropdown: [{id, name}]
            foreach ($labels as $l) {
                if (isset($l['id']) && trim((string) ($l['name'] ?? '')) !== '') {
                    $rows[] = ['index' => (string) $l['id'], 'label' => trim((string) $l['name']), 'position' => count($rows)];
                }
            }
        } else { // status: {index: label}
            $positions = (array) ($s['labels_positions_v2'] ?? []);
            foreach ((array) $labels as $index => $label) {
                if (trim((string) $label) !== '' && ! in_array((string) $index, array_map('strval', (array) ($s['deactivated_labels'] ?? [])), true)) {
                    $rows[] = ['index' => (string) $index, 'label' => trim((string) $label), 'position' => (int) ($positions[$index] ?? 1000 + (int) $index)];
                }
            }
        }
        usort($rows, fn ($a, $b) => $a['position'] <=> $b['position']);
        $done = array_map('strval', (array) ($s['done_colors'] ?? []));

        return array_map(fn ($r) => [
            'id' => 'monday-'.$r['index'], 'label' => mb_substr($r['label'], 0, 100),
            'colour' => isset($s['labels_colors'][$r['index']]['color']) ? (string) $s['labels_colors'][$r['index']]['color'] : null,
            'done' => in_array($r['index'], $done, true),
        ], $rows);
    }

    /**
     * The board's named views, as saved views with Monday's layout. Monday's
     * API gives a view's name and kind only, never its filters (all 54 read
     * 2026-11-13 had settings_str {}); a view Monday's API calls nothing (an
     * app view: kanban, calendar, gantt, dashboard) is known by its name.
     *
     * @return list<array{monday_id: string, name: string, layout: string}>
     */
    public static function views(array $views): array
    {
        $out = [];
        foreach ($views as $v) {
            $name = trim((string) ($v['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $layout = match ((string) ($v['type'] ?? '')) {
                'TableBoardView', 'SppBoardView', 'FeatureBoardView' => 'table',
                'KanbanBoardView' => 'kanban',
                'CalendarBoardView' => 'calendar',
                default => match (true) {
                    (bool) preg_match('/kanban/i', $name) => 'kanban',
                    (bool) preg_match('/calendar/i', $name) => 'calendar',
                    (bool) preg_match('/timeline|gantt/i', $name) => 'timeline',
                    (bool) preg_match('/dashboard|chart/i', $name) => 'dashboard',
                    default => 'table',
                },
            };
            $out[] = ['monday_id' => (string) ($v['id'] ?? $name), 'name' => mb_substr($name, 0, 100), 'layout' => $layout];
        }

        return $out;
    }

    /** The title a non-status column lands under: a second "Status" is "Status 2", as the export has always named it. */
    private static function title(array $m, array $order): string
    {
        return ($m['type'] === 'status' || $m['type'] === 'color') && mb_strtolower(trim($m['title'])) === 'status' && $order !== []
            ? 'Status 2' : (string) $m['title'];
    }

    /**
     * What writing to a board needs (MondayPush): its columns as remembered
     * here, which people column holds the assignees, which status column is
     * the task's status, the Due Date column, and its groups by name.
     *
     * @return array{columns: array<string, array>, assignee: ?string, status: ?string, due: ?string, groups: array<string, string>}
     */
    public function writeMeta(string $boardId): array
    {
        $b = $this->api->query('query($b:[ID!]){ boards(ids:$b, state:all) { id columns { id title type settings_str } groups { id title } } }', ['b' => [$boardId]])['boards'][0] ?? null;
        if ($b === null) {
            throw new MondayRefused("Monday shows no board {$boardId} to this connection.");
        }
        $this->remember($boardId, $b['columns']);
        $meta = $this->columns[$boardId];
        $due = array_key_first(array_filter($meta, fn ($m) => $m['type'] === 'date' && mb_strtolower(trim($m['title'])) === 'due date'));
        $groups = [];
        foreach ($b['groups'] as $g) {
            $groups[mb_strtolower(trim((string) $g['title']))] ??= (string) $g['id'];
        }

        return ['columns' => $meta, 'assignee' => $this->assignee[$boardId] ?? null, 'status' => self::statusOrder($meta)[0] ?? null,
            'due' => $due === null ? null : (string) $due, 'groups' => $groups];
    }

    /** @return array{updated_at: ?string, board: string, group: ?string, state: ?string}|null an item's clock, board, group and state (active, archived, deleted) */
    public function itemState(string $itemId): ?array
    {
        $item = $this->api->query('query($i:[ID!]){ items(ids:$i) { id state updated_at board { id } group { id } } }', ['i' => [$itemId]])['items'][0] ?? null;

        return isset($item['board']['id'])
            ? ['updated_at' => $item['updated_at'] ?? null, 'board' => (string) $item['board']['id'], 'group' => isset($item['group']['id']) ? (string) $item['group']['id'] : null, 'state' => $item['state'] ?? null]
            : null;
    }

    /** The column a Task Board column of that name came from ("Status 2" is the second "Status"). */
    public static function columnFor(array $meta, string $name): ?string
    {
        $order = self::statusOrder($meta['columns']);
        foreach ($meta['columns'] as $id => $m) {
            if ($order !== [] && $id === $order[0]) {
                continue;
            }
            if (mb_strtolower(self::title($m, $order)) === mb_strtolower(trim($name))) {
                return (string) $id;
            }
        }

        return null;
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
     * $filtered asks Monday for the items updated since the day before $since
     * only (its __last_updated__ filter works by the day; measured on prod
     * 2026-11-16: NW's 24,700 items → 6 rows for "today") instead of listing
     * the whole board — the 10-minute pass. A change only to a subitem may not
     * move its parent's date; the nightly scan ($filtered false) and the
     * webhooks catch those.
     *
     * @return array{ids: list<string>, latest: ?string}
     */
    /** @return array<string, string> every live top-level item of a board => its group's title (the parity check) */
    public function itemGroups(string $boardId): array
    {
        $fields = 'cursor items { id group { title } }';
        $page = $this->api->query('query($b:[ID!]){ boards(ids:$b) { items_page(limit:500) { '.$fields.' } } }', ['b' => [$boardId]])['boards'][0]['items_page'] ?? ['items' => [], 'cursor' => null];
        $items = $page['items'];
        while (! empty($page['cursor'])) {
            $page = $this->api->query('query($c:String!){ next_items_page(limit:500, cursor:$c) { '.$fields.' } }', ['c' => $page['cursor']])['next_items_page'];
            array_push($items, ...$page['items']);
        }

        return array_column(array_map(fn ($i) => [(string) $i['id'], (string) ($i['group']['title'] ?? '')], $items), 1, 0);
    }

    public function changedSince(string $boardId, ?CarbonImmutable $since, bool $filtered = false): array
    {
        $fields = 'cursor items { id updated_at subitems { id updated_at } }';
        $page = $filtered && $since !== null
            ? $this->api->query('query($b:[ID!],$d:CompareValue!){ boards(ids:$b, state:all) { items_page(limit:500, query_params:{rules:[{column_id:"__last_updated__", compare_value:$d, operator:greater_than_or_equals, compare_attribute:"UPDATED_AT"}]}) { '.$fields.' } } }',
                ['b' => [$boardId], 'd' => ['EXACT', $since->utc()->subDay()->toDateString()]])['boards'][0]['items_page'] ?? ['items' => [], 'cursor' => null]
            : $this->api->query('query($b:[ID!]){ boards(ids:$b, state:all) { items_page(limit:500) { '.$fields.' } } }', ['b' => [$boardId]])['boards'][0]['items_page'] ?? ['items' => [], 'cursor' => null];
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
            // After the cursor, not at it: the cursor is the newest change already taken (every item at that
            // second was in the same read), so a quiet pass reads nothing.
            if ($since === null || $newest === null || CarbonImmutable::parse($newest)->greaterThan($since)) {
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
                    $subBoard = (string) ($sub['board']['id'] ?? '');
                    $converted['subitems'][] = $this->convert($subBoard, $sub, $this->columns[$subBoard] ?? [], $group);
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
        foreach ($this->api->query('query($b:[ID!]){ boards(ids:$b, state:all) { id columns { id title type settings_str } } }', ['b' => $missing])['boards'] ?? [] as $b) {
            $this->remember((string) $b['id'], $b['columns']);
        }
    }

    private function remember(string $boardId, array $columns): void
    {
        $this->columns[$boardId] = [];
        foreach ($columns as $c) {
            $settings = json_decode((string) ($c['settings_str'] ?? '{}'), true) ?: [];
            $this->columns[$boardId][(string) $c['id']] = [
                'title' => (string) $c['title'],
                'type' => (string) $c['type'],
                'settings' => $settings,
                'done' => array_map('strval', (array) ($settings['done_colors'] ?? [])),
            ];
        }
        // The assignees are one people column: the one called Person/Owner/…, else the first.
        $people = array_keys(array_filter($this->columns[$boardId], fn ($m) => in_array($m['type'], ['people', 'multiple-person'], true)));
        $named = array_values(array_filter($people, fn ($id) => in_array(mb_strtolower(trim($this->columns[$boardId][$id]['title'])), ['person', 'owner', 'people', 'assignee', 'assigned', 'assigned to'], true)));
        $chosen = $named[0] ?? $people[0] ?? null;
        if ($chosen !== null) {
            $this->assignee[$boardId] = (string) $chosen;
        } else {
            unset($this->assignee[$boardId]);
        }
    }

    /** The status columns in the order they decide: "Status" by title, then the main `status`. */
    private static function statusOrder(array $meta): array
    {
        $named = fn (string $title) => array_keys(array_filter($meta, fn ($m) => in_array($m['type'], ['status', 'color'], true) && mb_strtolower(trim($m['title'])) === $title));
        $titled = $named('status');
        if ($titled === []) {
            // The Project Management boards' first status column (id `status`) is Area, whose
            // index 1 (Cincinnati, Wisconsin) Monday lists as "done": the task's status there is
            // Job Status, and Area is a column like any other (prod import, 2026-11-13).
            $job = $named('job status');
            if ($job !== []) {
                return [$job[0]];
            }
        }

        return array_values(array_unique([...$titled, ...(isset($meta['status']) ? ['status'] : [])]));
    }

    /** @param  string  $boardId  the board whose columns $meta are (a subitem's own board for a subitem) */
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
        $assignee = $this->assignee[$boardId] ?? null;
        $emails = fn (array $c) => array_values(array_map(
            fn ($p) => $this->users[(string) $p['id']]['email'] ?? ((string) $p['id'].'@deleted.user'),
            array_filter($c['persons_and_teams'] ?? [], fn ($p) => ($p['kind'] ?? 'person') === 'person'),
        ));
        $files = [];
        $dueTaken = false;

        foreach ($values as $id => $c) {
            $m = $meta[$id] ?? null;
            if ($m === null || ($order !== [] && $id === $order[0])) {
                continue;
            }
            $title = self::title($m, $order);
            $text = trim((string) ($c['text'] ?? ''));
            $type = array_key_exists($m['type'], self::TYPES) ? self::TYPES[$m['type']] : 'text';
            $typed = fn (string $t, mixed $v, array $settings = []) => ['type' => $t, 'value' => $v] + ($settings ? ['settings' => $settings] : []);
            $options = fn () => ['options' => array_map(fn ($l) => ['id' => $l['id'], 'label' => $l['label'], 'colour' => $l['colour']], self::labels($m))];

            if ($m['type'] === 'file') {
                $names = [];
                foreach ($c['files'] ?? [] as $f) {
                    if (isset($f['asset']['id'])) {
                        $files[] = ['monday_id' => (string) $f['asset']['id'], 'name' => (string) $f['asset']['name'],
                            'ext' => mb_strtolower((string) ($f['asset']['file_extension'] ?? '')), 'size' => (int) ($f['asset']['file_size'] ?? 0),
                            'url' => $f['asset']['public_url'] ?? null];
                        $names[] = (string) $f['asset']['name'];
                    }
                }
                if ($names !== []) {
                    // The files come on the "Files from Monday" update; the column names them (MondayImport::fileColumns).
                    $out['columns'][$title] = $typed('files', $names);
                }

                continue;
            }

            switch ($type) {
                case null:
                case 'formula': // worked out here from the other columns
                case 'creation_log':
                case 'last_updated':
                    break;
                case 'rating':
                    $n = json_decode((string) ($c['value'] ?? ''), true)['rating'] ?? null;
                    if (is_numeric($n)) {
                        $out['columns'][$title] = $typed('rating', (int) $n);
                    }
                    break;
                case 'location':
                    $address = trim((string) ($c['address'] ?? $text));
                    if ($address !== '') {
                        $out['columns'][$title] = $typed('location', ['address' => $address,
                            'lat' => is_numeric($c['lat'] ?? null) ? (float) $c['lat'] : null, 'lng' => is_numeric($c['lng'] ?? null) ? (float) $c['lng'] : null]);
                    }
                    break;
                case 'connect':
                case 'dependency':
                    // Monday item ids; the import names the tasks that carry them (monday:<id>).
                    $linked = array_values(array_map('strval', (array) ($c['linked_item_ids'] ?? [])));
                    if ($linked !== []) {
                        $out['columns'][$title] = $typed($type, $linked);
                    }
                    break;
                case 'people':
                    $people = $emails($c);
                    if ($id === $assignee) {
                        array_push($out['people'], ...$people);
                    } elseif ($people !== []) {
                        $out['columns'][$title] = $typed('people', $people);
                    }
                    break;
                case 'date':
                    $date = (string) ($c['date'] ?? '');
                    if (mb_strtolower(trim($m['title'])) === 'due date' && ! $dueTaken) {
                        $dueTaken = true;
                        $out['due_on'] = $date !== '' ? $date : null;
                    } elseif ($date !== '') {
                        $out['columns'][$title] = $typed('date', $date);
                    }
                    break;
                case 'timeline':
                    if (! empty($c['from']) && ! empty($c['to'])) {
                        $range = ['from' => substr((string) $c['from'], 0, 10), 'to' => substr((string) $c['to'], 0, 10)];
                        if (mb_strtolower(trim($m['title'])) === 'timeline' && $out['timeline'] === null) {
                            $out['timeline'] = $range; // the export's own Timeline (also the due date when there is none)
                        } else {
                            $out['columns'][$title] = $typed('timeline', $range);
                        }
                    }
                    break;
                case 'status':
                    $label = trim((string) ($c['label'] ?? ''));
                    if ($label !== '') {
                        $out['columns'][$title] = $typed('status', $label, $options());
                    }
                    break;
                case 'tags':
                    $labels = $m['type'] === 'tags'
                        ? array_column($c['tags'] ?? [], 'name')
                        : (isset($c['values']) ? array_column($c['values'], 'label') : explode(',', $text));
                    $labels = array_values(array_unique(array_filter(array_map(fn ($l) => trim((string) $l), $labels))));
                    if ($labels !== []) {
                        $out['columns'][$title] = $typed('tags', $labels, $m['type'] === 'tags' ? [] : $options());
                    }
                    break;
                case 'number':
                    $n = $m['type'] === 'rating' ? (json_decode((string) ($c['value'] ?? ''), true)['rating'] ?? null) : $text;
                    if ($n !== null && $n !== '' && is_numeric($n)) {
                        $out['columns'][$title] = $typed(($m['settings']['unit']['symbol'] ?? '') === '$' ? 'currency' : 'number', $n);
                    }
                    break;
                case 'checkbox':
                    $checked = $c['checked'] ?? (json_decode((string) ($c['value'] ?? ''), true)['checked'] ?? null);
                    if ($checked === true || $checked === 'true') {
                        $out['columns'][$title] = $typed('checkbox', true);
                    }
                    break;
                case 'url':
                    $url = (string) ($c['url'] ?? (json_decode((string) ($c['value'] ?? ''), true)['url'] ?? ''));
                    if ($url !== '') {
                        $out['columns'][$title] = $typed('url', $url);
                    }
                    break;
                case 'email':
                    $email = (string) ($c['email'] ?? (json_decode((string) ($c['value'] ?? ''), true)['email'] ?? ''));
                    if ($email !== '') {
                        $out['columns'][$title] = $typed('email', $email);
                    }
                    break;
                case 'phone':
                    $phone = (string) ($c['phone'] ?? (json_decode((string) ($c['value'] ?? ''), true)['phone'] ?? ''));
                    if ($phone !== '') {
                        $out['columns'][$title] = $typed('phone', $phone);
                    }
                    break;
                default: // text, long_text, and every read-only Monday type as the words Monday shows
                    $words = trim((string) ($c['display_value'] ?? '')) !== '' ? trim((string) $c['display_value']) : $text;
                    if ($words !== '') {
                        $out['columns'][$title] = $typed($type === 'long_text' ? 'long_text' : 'text', $words);
                    }
            }
        }

        if ($files !== []) {
            // A file column's files arrive on one update, "Files from Monday"; the Files column then names them.
            $out['updates'][] = ['monday_id' => 'files.'.$item['id'], 'author_email' => $out['creator_email'], 'author_name' => null,
                'created_at' => $item['created_at'] ?? null, 'body' => 'Files from Monday', 'files' => $files, 'replies' => []];
        }

        if ($doneBy !== null && $out['status'] !== trim((string) $doneBy['label'])) {
            // Done in one status column, something else in the other: done wins.
            $out['columns']['Status 2'] = ['type' => 'status', 'value' => $out['status']];
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
