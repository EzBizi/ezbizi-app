<?php

namespace App\Support\Reports;

use App\Models\User;
use App\Support\Access\ScopeReach;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Tasks\TaskBoards;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Turns a saved definition into rows (spec 2026-09-10).
 *
 * ── Everything is checked against the map, nothing against the request ──
 *
 * Table names, column names and join predicates come from ReportMap, never
 * from the definition. The definition only ever supplies KEYS INTO the map,
 * and a key that is not there throws. That is what keeps a report from naming
 * `password_reset_tokens`.
 *
 * The errors are exceptions rather than filtered-out entries, and that
 * asymmetry is deliberate: a silently dropped COLUMN gives a smaller answer
 * and somebody shrugs; a silently dropped FILTER gives a BIGGER one, and
 * nobody reports a result set that is too large.
 *
 * ── Scope goes on every table, on every run ─────────────────────
 *
 * Not once at the root. A report saved in the organization view and run from a
 * company view must come back smaller, so the filters are applied at run time
 * from the CALLER's view and reach.
 *
 * `franchiseIds()` is the workhorse and it is already intersected with reach,
 * which is why there is NO early return for the organization view. An earlier
 * draft skipped scoping entirely when `isOrganization()` was true; that would
 * have handed an organization-view user every franchise's jobs regardless of
 * assignment. Exporter shipped exactly that bug once — its docblock says the
 * permission gate passed and the reach gate was simply absent — and this is
 * the same shape of query.
 *
 * ── A reverse hop multiplies rows, and that is correct ──────────
 *
 * Edges are walked in both directions (see RelationGraph). Forwards an edge is
 * many-to-one — every job has one customer. Backwards it is one-to-many, so a
 * customer with five jobs produces five rows. That is the right answer to
 * "customers and their jobs" and it surprises people; the row cap is what
 * stops it being unbounded.
 *
 * ── Joined tables are scoped in the ON clause, not the WHERE ────
 *
 * A LEFT JOIN with the joined table's conditions in WHERE is an INNER JOIN
 * wearing a disguise: rows where the join found nothing have NULL there, NULL
 * fails the comparison, and the row disappears. A job with no customer would
 * vanish from a report the moment somebody added a customer column — losing
 * rows, quietly, which is the failure this class works hardest to avoid.
 */
final class ReportRunner
{
    /**
     * ponytail: a flat cap, not a paginator. The builder answers questions
     * whose result is a page or a chart; the day somebody genuinely needs
     * 200k rows the answer is an export job, not a bigger number here.
     */
    public const ROW_CAP = 5000;

    /**
     * A report that cannot answer in ten seconds is one somebody should
     * narrow, not a query the database should keep grinding on.
     */
    public const TIMEOUT_MS = 10000;

    private const OPERATORS = [
        'eq' => '=',
        'ne' => '!=',
        'lt' => '<',
        'lte' => '<=',
        'gt' => '>',
        'gte' => '>=',
        'contains' => 'like',
    ];

    /** @var list<string>|null memoised per run; see visiblePeopleIds() */
    private ?array $visiblePeople = null;

    /** Memoised per run, so every row of one report reads the same day. */
    private ?CarbonImmutable $today = null;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * The tenant context is entered HERE, from the user, rather than assumed.
     *
     * A web request has one by the time it reaches a controller, but a queued
     * `report.send` does not — and the rule for delayed work is that the worker
     * enters the context itself rather than inheriting one. Entering it from
     * `$user->organization` means both callers behave identically, and the
     * organization can never arrive from a request payload.
     */
    /**
     * @param  ViewScope|null  $at  the place to render AT, or null for the
     *                              person's current view
     *
     * ── WHY A CALLER MAY NEED TO SAY WHERE ──────────────────────────
     *
     * `forUser` answers "what is this person looking at right now", and it
     * answers it from `preferences['navigation.scope']` — a STORED UI
     * PREFERENCE, which this class's own scoping docblock calls user input.
     * For somebody pressing Run on a screen that is exactly right: the report
     * should cover what they are looking at.
     *
     * For a SCHEDULED send it is wrong, and quietly. The report would render at
     * whatever chip its author last clicked in their top bar, months ago, on a
     * morning unrelated to the report — so the same rule produces different
     * figures depending on a preference nobody thought of as part of it, and
     * nothing on the way out says which.
     *
     * A saved report already carries its own place. `SendSavedReport` passes
     * that, and the person is still required: they are the reach FLOOR, and
     * `ViewScope::forStoredAutomationScope` refuses legibly when they no longer
     * reach the place the report names.
     */
    public function run(array $definition, User $user, ?ViewScope $at = null): ReportResult
    {
        return $this->tenant->run($user->organization, fn () => $this->build($definition, $user, $at));
    }

    private function build(array $definition, User $user, ?ViewScope $at = null): ReportResult
    {
        $root = $definition['root'] ?? '';
        $rootResource = ReportMap::get($root)
            ?? throw new InvalidArgumentException("There is no reportable resource called '{$root}'.");

        $view = $at ?? ViewScope::forUser($user);
        $organizationId = $this->tenant->idOrFail();

        $query = DB::table($rootResource['table']);

        // The root is scoped in WHERE; it is the table every row comes from,
        // so narrowing it is exactly the intent.
        $this->applyScope(
            fn (string $column, string $operator, mixed $value) => $query->where($column, $operator, $value),
            fn (string $column, array $values) => $query->whereIn($column, $values),
            fn (string $column) => $query->whereNull($column),
            fn (string $sql, array $bindings) => $query->whereRaw($sql, $bindings),
            $rootResource,
            $organizationId,
            $view,
            $user,
        );

        $joined = [$root => true];

        foreach ($this->resourcesUsed($definition) as $resourceKey) {
            if (isset($joined[$resourceKey])) {
                continue;
            }

            $path = RelationGraph::pathBetween($root, $resourceKey)
                ?? throw new InvalidArgumentException(
                    "A report starting at '{$rootResource['label']}' cannot reach '{$resourceKey}'."
                );

            foreach ($path as $hop) {
                if (isset($joined[$hop['to']])) {
                    continue;
                }

                $from = ReportMap::get($hop['from']);
                $to = ReportMap::get($hop['to']);

                $query->leftJoin($to['table'], function (JoinClause $join) use (
                    $from, $to, $hop, $organizationId, $view, $user
                ) {
                    // A column per side rather than local/foreign, because an
                    // edge is walked in both directions: the predicate is the
                    // same, only which table is being added changes.
                    $join->on(
                        $from['table'].'.'.$hop['from_column'],
                        '=',
                        $to['table'].'.'.$hop['to_column'],
                    );

                    $this->applyScope(
                        fn (string $c, string $o, mixed $v) => $join->where($c, $o, $v),
                        fn (string $c, array $v) => $join->whereIn($c, $v),
                        fn (string $c) => $join->whereNull($c),
                        fn (string $sql, array $b) => $join->whereRaw($sql, $b),
                        $to,
                        $organizationId,
                        $view,
                        $user,
                    );
                });

                $joined[$hop['to']] = true;
            }
        }

        $columns = [];

        /** @var array<string, array{0: class-string, 1: string}> $php keyed like a row */
        $php = [];
        $phpFilters = [];

        // A summary (group by / totals) selects its own columns below.
        $summary = ($definition['group'] ?? []) !== [] || ($definition['totals'] ?? []) !== [];

        foreach ($summary ? [] : $definition['columns'] ?? [] as $column) {
            $resource = $this->resourceFor($column);
            $declared = $this->columnFor($resource, $column);
            $key = $column['resource'].'.'.$column['column'];
            $this->mayRead($user, $declared);

            if (isset($declared['php'])) {
                $php[$key] = $this->phpOn($root, $column, $declared, $rootResource, $query);
                $columns[] = ['key' => $key, 'label' => $this->labelFor($column, $declared), 'type' => $declared['type']];

                continue;
            }

            /*
             * A DERIVED COLUMN IS A CORRELATED SUBQUERY, not a table column.
             *
             * Some facts a report needs are not stored anywhere: "how many days
             * a week is this service area open" is the number of distinct
             * weekdays it has slot rows for, and no column holds it. Declaring
             * it in the map as `sql` lets it be selected AND filtered exactly
             * like a real column, which is what makes a warning possible — a
             * report that cannot filter on the derived value has to return
             * every row, and a rule that always has rows is never a warning.
             *
             * The SQL is static and lives in ReportMap. Nothing here
             * interpolates user input: the operator comes from the OPERATORS
             * whitelist below and the value is bound.
             */
            $query->addSelect(
                DB::raw(isset($declared['sql'])
                    ? '('.$this->resolve($declared['sql']).') as '.$this->quote($key)
                    : $this->quote($resource['table']).'.'.$this->quote($column['column'])
                        .' as '.$this->quote($key))
            );

            $columns[] = ['key' => $key, 'label' => $this->labelFor($column, $declared), 'type' => $declared['type']];
        }

        if ($columns === [] && ! $summary) {
            throw new InvalidArgumentException('A report needs at least one column.');
        }

        /*
         * FILTERS, joined by AND, or by OR when the report says `match: any`
         * (the builder brief, 2026-10-09). Each SQL filter becomes one clause
         * in a single group, so "any" cannot leak past the tenant and place
         * scoping applied above.
         */
        $any = ($definition['match'] ?? 'all') === 'any';
        $clauses = [];

        foreach ($definition['filters'] ?? [] as $filter) {
            $resource = $this->resourceFor($filter);
            $declaredFilter = $this->columnFor($resource, $filter);

            // A filter reads the value too: `amount > 5000` answers the
            // question the column would have, one row at a time.
            $this->mayRead($user, $declaredFilter);

            $op = (string) ($filter['op'] ?? '');
            $value = $filter['value'] ?? null;

            if (isset($declaredFilter['php'])) {
                $operator = self::OPERATORS[$op]
                    ?? throw new InvalidArgumentException("'{$declaredFilter['label']}' is worked out after the rows are read, so it can only be compared, not '{$op}'.");

                // ponytail: PHP filters run after the fetch, so they can only
                // narrow (AND); an "any" report including one is refused.
                if ($any) {
                    throw new InvalidArgumentException("'{$declaredFilter['label']}' cannot be one of several \"any\" filters.");
                }

                $key = $filter['resource'].'.'.$filter['column'];
                $php[$key] ??= $this->phpOn($root, $filter, $declaredFilter, $rootResource, $query);
                $phpFilters[] = [$key, $operator, $value];

                continue;
            }

            // The SAME expression as the select, so the rows a filter keeps
            // are the rows whose printed value satisfies it. Two spellings of
            // one derived value is how a report comes to exclude a row it then
            // displays.
            $expression = isset($declaredFilter['sql'])
                ? '('.$this->resolve($declaredFilter['sql']).')'
                : $this->quote($resource['table']).'.'.$this->quote($filter['column']);

            $clauses[] = $this->clause($expression, $op, $value, $declaredFilter, $user);
        }

        if ($clauses !== []) {
            $query->where(function ($group) use ($clauses, $any) {
                foreach ($clauses as [$sql, $bindings]) {
                    $any ? $group->orWhereRaw($sql, $bindings) : $group->whereRaw($sql, $bindings);
                }
            });
        }

        if ($summary) {
            if ($phpFilters !== []) {
                throw new InvalidArgumentException('A filter worked out after reading cannot narrow a summary.');
            }

            return $this->summarise($query, $definition, $user);
        }

        /*
         * SORT (Jose, 2026-10-09, the builder brief). By the same expression a
         * column is selected with, so a derived column sorts by the value it
         * prints. Applied before the cap, so a capped report keeps the top of
         * its order rather than an arbitrary 5,000.
         */
        foreach ($definition['sort'] ?? [] as $sort) {
            $resource = $this->resourceFor($sort);
            $declaredSort = $this->columnFor($resource, $sort);
            $this->mayRead($user, $declaredSort);

            // ponytail: a PHP column is computed after the fetch, so it cannot
            // order the rows the database picks; sort it in PHP when wanted.
            if (isset($declaredSort['php'])) {
                throw new InvalidArgumentException("'{$declaredSort['label']}' is worked out after the rows are read, so it cannot be sorted on.");
            }

            $direction = ($sort['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

            $query->orderByRaw((isset($declaredSort['sql'])
                ? '('.$this->resolve($declaredSort['sql']).')'
                : $this->quote($resource['table']).'.'.$this->quote($sort['column'])).' '.$direction);
        }

        // One more than the cap, so "there were more" is a fact rather than a
        // guess — a result of exactly ROW_CAP is ambiguous otherwise.
        $rows = $this->withTimeout(
            fn () => $query->limit(self::ROW_CAP + 1)->get()->map(fn ($row) => (array) $row)->all()
        );

        $capped = count($rows) > self::ROW_CAP;

        if ($capped) {
            $rows = array_slice($rows, 0, self::ROW_CAP);
        }

        if ($php !== []) {
            $rows = $this->withPhpColumns($rows, $php, $phpFilters, array_column($columns, 'key'));
        }

        return new ReportResult($columns, $rows, $capped, count($rows));
    }

    /**
     * A `php` column: computed per ROOT row by a class the map names, because
     * the answer lives in PHP another module owns (ServiceAreaFullness reads
     * Scheduling's Openings). Root only, since it is handed the root row's id.
     *
     * ponytail: filtered after the fetch, so it only sees the first ROW_CAP
     * rows. Fine for service areas (45 on A-1); a php column on a big table
     * needs pushing into SQL or paging before it is declared.
     */
    private function phpOn(string $root, array $item, array $declared, array $rootResource, $query): array
    {
        if (($item['resource'] ?? null) !== $root) {
            throw new InvalidArgumentException(
                "'{$declared['label']}' can only be used on a report that starts at {$rootResource['label']}."
            );
        }

        $query->addSelect($rootResource['table'].'.id as __root_id');

        return $declared['php'];
    }

    /**
     * Fill the php columns and apply their filters with SQL's rules: NULL
     * matches no comparison, so an unconfigured area is never "booked out".
     */
    private function withPhpColumns(array $rows, array $php, array $filters, array $shown): array
    {
        $kept = [];

        foreach ($rows as $row) {
            foreach ($php as $key => [$class, $method]) {
                $row[$key] = app($class)->{$method}((string) $row['__root_id']);
            }

            foreach ($filters as [$key, $operator, $value]) {
                if (! self::compare($row[$key], $operator, $value)) {
                    continue 2;
                }
            }

            // Only what was asked for: a php FILTER on an unselected column
            // and the root id it needed are not columns of the report.
            $kept[] = array_intersect_key($row, array_flip($shown));
        }

        return $kept;
    }

    private static function compare(mixed $left, string $operator, mixed $right): bool
    {
        if ($left === null) {
            return false;
        }

        return match ($operator) {
            '=' => $left == $right,
            '!=' => $left != $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            'like' => str_contains(mb_strtolower((string) $left), mb_strtolower((string) $right)),
        };
    }

    /**
     * Tenant, place and soft deletes for one table.
     *
     * Written as three callables rather than taking a query, because the same
     * rules must be expressible in a WHERE (for the root) and in an ON (for
     * every joined table), and those are different objects.
     */
    private function applyScope(
        callable $where,
        callable $whereIn,
        callable $whereNull,
        callable $whereRaw,
        array $resource,
        string $organizationId,
        ViewScope $view,
        User $user,
    ): void {
        $table = $resource['table'];

        $where($table.'.organization_id', '=', $organizationId);

        if ($resource['soft_deletes']) {
            $whereNull($table.'.deleted_at');
        }

        // COLUMN => SOURCE. The source is named in the map rather than guessed
        // from the column, because `jobs.franchise_id` and `franchises.id` are
        // both answered by the same list of franchise ids.
        foreach ($resource['scope'] as $column => $source) {
            if ($source === 'place') {
                // The CATALOGUE shape: nullable company and franchise, so a row
                // can be filed at the organization, at a company, or at one
                // franchise. Delegated to the reviewed engine rather than
                // re-derived — see placeScope().
                $this->placeScope($whereRaw, $resource, $user);

                continue;
            }

            $ids = match ($source) {
                'franchise' => $view->franchiseIds(),
                // The column says WHICH column holds the user id: `id` on the
                // people themselves, `user_id` on a row that belongs to one.
                'people' => $this->visiblePeopleIds($user),
                // The boards the Task Board itself would show this person.
                'task_board' => app(TaskBoards::class)->visibleTo($user)->pluck('id')->all(),
                default => throw new LogicException(
                    "ReportMap scopes '{$table}.{$column}' by '{$source}', and this runner "
                    .'does not know which view ids resolve that. Teach it here rather than '
                    .'dropping the filter — an unresolved scope source must never become an '
                    .'unscoped table.'
                ),
            };

            // An empty list means this person reaches nowhere, which is a
            // legitimate answer of "no rows" rather than "no filter".
            $whereIn($table.'.'.$column, $ids);
        }
    }

    /**
     * The catalogue place rule, borrowed rather than rebuilt.
     *
     * `InventoryVisibility::constrain` is the reviewed answer for rows with a
     * nullable company and franchise — organization rows to anyone with reach,
     * company General rows to anyone who can see that company, franchise rows
     * only when the franchise is in view. Its own docblock records that the
     * organization arm must answer with REACH and not with
     * `ViewScope::companyIds()`, because anybody who has never touched the
     * switcher arrives claiming an organization view: "Phone shipped exactly
     * that to production."
     *
     * That is too subtle to re-derive here, so this builds the same query
     * through that class and reuses its WHERE as a subquery predicate. It
     * costs one extra clause and buys the guarantee that a report and the
     * module's own list agree — which `ReportRunnerTest` asserts directly, so
     * the two cannot drift apart silently.
     */
    private function placeScope(callable $whereRaw, array $resource, User $user): void
    {
        $model = $resource['model'];
        $scoped = InventoryVisibility::constrain(
            $model::query(),
            $user,
            ViewScope::forUser($user),
        );

        $sql = $scoped->toBase()->toSql();
        $wheres = strstr($sql, ' where ');

        if ($wheres === false) {
            // The engine refused everything (no reach at all). Say no rows
            // rather than no filter.
            $whereRaw('0 = 1', []);

            return;
        }

        $whereRaw(
            '('.substr($wheres, 7).')',
            $scoped->toBase()->getBindings(),
        );
    }

    /**
     * The people this caller may see, as ids.
     *
     * Delegated to `ScopeReach::constrainToVisiblePeople`, which is the shared
     * predicate behind HR's own people list AND Monitoring's device list — its
     * docblock says so in as many words, so that "may you see this person"
     * cannot drift into two answers. Three arms: yourself always, anybody
     * assigned to a place your authority covers, and the unassigned only if you
     * reach the whole organization.
     *
     * THIS IS WHY `monitored_devices` NEEDS NO PLACE COLUMN. A device belongs to
     * a person, not to a branch, and a column would be wrong the moment somebody
     * transfers. Monitoring's own controller checks reach against the device's
     * OWNER for exactly that reason; this does the same, one hop away.
     *
     * Materialised as ids rather than as a subquery because a tenant's people
     * are tens, not millions, and the alternative is SQL-string surgery on a
     * predicate whose correctness is the whole point. Memoised per run: a
     * report naming both `users` and `monitored_devices` asks twice.
     */
    private function visiblePeopleIds(User $user): array
    {
        return $this->visiblePeople ??= User::query()
            ->where(fn (Builder $q) => app(ScopeReach::class)->constrainToVisiblePeople($q, $user))
            ->pluck('id')
            ->all();
    }

    /** @return list<string> */
    /** The sums a summary can show for a column. */
    private const TOTALS = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * GROUP BY and TOTALS (the builder brief, items 10-11; what Workiz's own
     * builder shows when grouped). Rows are one per group, the largest first
     * by the first total; `total` is the same sums over every row, from a
     * second query rather than added up here, so an average stays an average.
     *
     * Group keys: up to two columns; a date can be bucketed by day, week
     * (Monday) or month, in the organization's timezone.
     */
    private function summarise(QueryBuilder $query, array $definition, User $user): ReportResult
    {
        $groups = array_slice($definition['group'] ?? [], 0, 2);
        $columns = [];
        $aggregates = [];
        $keys = [];

        foreach ($groups as $group) {
            [$expression, $declared] = $this->expressionFor($group, $user, 'grouped by');
            $bucket = $group['bucket'] ?? null;

            if ($bucket !== null) {
                if (! in_array($declared['type'] ?? '', ['date', 'datetime'], true)) {
                    throw new InvalidArgumentException("'{$declared['label']}' is not a date, so it cannot be grouped by {$bucket}.");
                }

                if (($declared['type'] ?? '') === 'datetime' && empty($declared['wall_clock'])) {
                    // In the organization's clock; UTC where the database has no zone tables.
                    $zone = DB::getPdo()->quote($this->zone($user));
                    $expression = "coalesce(convert_tz({$expression}, '+00:00', {$zone}), {$expression})";
                }

                $expression = match ($bucket) {
                    'day' => "date({$expression})",
                    'week' => "date({$expression} - interval weekday({$expression}) day)",
                    'month' => "date_format({$expression}, '%Y-%m-01')",
                    default => throw new InvalidArgumentException("'{$bucket}' is not a way to group dates."),
                };
            }

            $key = $group['resource'].'.'.$group['column'].($bucket ? ':'.$bucket : '');
            $keys[] = [$key, $expression];
            $columns[] = [
                'key' => $key,
                'label' => $this->labelFor($group, $declared).($bucket ? " ({$bucket})" : ''),
                'type' => $bucket ? 'date' : $declared['type'],
            ];
        }

        foreach ($definition['totals'] ?? [] as $total) {
            $fn = $total['fn'] ?? 'count';

            if (! in_array($fn, self::TOTALS, true)) {
                throw new InvalidArgumentException("'{$fn}' is not a total this report can show.");
            }

            if ($fn === 'count' && empty($total['column'])) {
                $aggregates[] = ['count', 'count(*)'];
                $columns[] = ['key' => 'count', 'label' => trim((string) ($total['label'] ?? '')) ?: 'Count', 'type' => 'integer'];

                continue;
            }

            [$expression, $declared] = $this->expressionFor($total, $user, 'totalled');

            if ($fn !== 'count' && ! in_array($declared['type'] ?? '', ['integer', 'money', 'date', 'datetime'], true)) {
                throw new InvalidArgumentException("'{$declared['label']}' is not a number, so it has no {$fn}.");
            }

            $key = $total['resource'].'.'.$total['column'].':'.$fn;
            $aggregates[] = [$key, "{$fn}({$expression})"];
            $columns[] = [
                'key' => $key,
                'label' => $this->labelFor($total, $declared).' ('.$fn.')',
                'type' => $fn === 'count' ? 'integer' : $declared['type'],
            ];
        }

        if ($aggregates === []) {
            $aggregates[] = ['count', 'count(*)'];
            $columns[] = ['key' => 'count', 'label' => 'Count', 'type' => 'integer'];
        }

        $select = fn (QueryBuilder $q) => $q->selectRaw(implode(', ', array_map(
            fn (array $a) => $a[1].' as '.$this->quote($a[0]),
            $aggregates,
        )));

        $grandTotal = (array) $this->withTimeout(fn () => $select(clone $query)->first());

        if ($keys === []) {
            return new ReportResult($columns, [], false, 0, $grandTotal);
        }

        $grouped = $select(clone $query);

        foreach ($keys as [$key, $expression]) {
            $grouped->selectRaw($expression.' as '.$this->quote($key))->groupByRaw($expression);
        }

        $grouped->orderByRaw($this->quote($aggregates[0][0]).' desc');

        $rows = $this->withTimeout(
            fn () => $grouped->limit(self::ROW_CAP + 1)->get()->map(fn ($row) => (array) $row)->all()
        );
        $capped = count($rows) > self::ROW_CAP;

        // Group columns first, as they read.
        $order = array_fill_keys(array_column($columns, 'key'), null);
        $rows = array_map(fn (array $row) => array_merge($order, $row), array_slice($rows, 0, self::ROW_CAP));

        return new ReportResult($columns, $rows, $capped, count($rows), $grandTotal);
    }

    /** @return array{0: string, 1: array} the SQL a column is read with, and its map entry */
    private function expressionFor(array $item, User $user, string $use): array
    {
        $resource = $this->resourceFor($item);
        $declared = $this->columnFor($resource, $item);
        $this->mayRead($user, $declared);

        if (isset($declared['php'])) {
            throw new InvalidArgumentException("'{$declared['label']}' is worked out after the rows are read, so it cannot be {$use}.");
        }

        return [isset($declared['sql'])
            ? '('.$this->resolve($declared['sql']).')'
            : $this->quote($resource['table']).'.'.$this->quote($item['column']), $declared];
    }

    private function zone(User $user): string
    {
        return (string) app(SettingsResolver::class)->get('timezone', $user->organization);
    }

    /**
     * One filter as SQL. The expression comes from the map, never the
     * request; every value is bound.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function clause(string $expression, string $op, mixed $value, array $declared, User $user): array
    {
        $list = fn () => array_values(array_filter((array) $value, fn ($v) => $v !== null && $v !== ''))
            ?: throw new InvalidArgumentException("'{$declared['label']}' needs at least one value to match.");

        return match ($op) {
            'contains' => ["{$expression} like ?", ['%'.$value.'%']],
            'in' => ["{$expression} in (".implode(',', array_fill(0, count($l = $list()), '?')).')', $l],
            'not_in' => ["({$expression} is null or {$expression} not in (".implode(',', array_fill(0, count($l = $list()), '?')).'))', $l],
            'between' => count((array) $value) === 2
                ? ["{$expression} between ? and ?", array_values((array) $value)]
                : throw new InvalidArgumentException("'{$declared['label']}' between needs two values."),
            'empty' => ["({$expression} is null or {$expression} = '')", []],
            'not_empty' => ["({$expression} is not null and {$expression} != '')", []],
            'period' => ["{$expression} between ? and ?", $this->period((string) $value, $declared, $user)],
            default => isset(self::OPERATORS[$op])
                ? ["{$expression} ".self::OPERATORS[$op].' ?', [$value]]
                : throw new InvalidArgumentException("'{$op}' is not a filter this report can use."),
        };
    }

    /**
     * A named period's first and last moment, in the organization's own
     * timezone: "today" for a business in New York is not today in UTC. A
     * datetime column is stored in UTC, so its bounds are converted; a date
     * column is compared by date.
     *
     * @return array{0: string, 1: string}
     */
    private function period(string $name, array $declared, User $user): array
    {
        $now = CarbonImmutable::now($this->zone($user));

        [$from, $to] = match ($name) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'this_week' => [$now->startOfWeek(CarbonImmutable::MONDAY), $now->endOfWeek(CarbonImmutable::SUNDAY)],
            'last_week' => [$now->subWeek()->startOfWeek(CarbonImmutable::MONDAY), $now->subWeek()->endOfWeek(CarbonImmutable::SUNDAY)],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_7_days' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'last_30_days' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'last_90_days' => [$now->subDays(89)->startOfDay(), $now->endOfDay()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            default => throw new InvalidArgumentException("'{$name}' is not a period this report knows."),
        };

        return match (true) {
            ($declared['type'] ?? '') === 'date' => [$from->toDateString(), $to->toDateString()],
            // Stored in the place's own clock (Workiz), so compared as it reads.
            ! empty($declared['wall_clock']) => [$from->toDateTimeString(), $to->toDateTimeString()],
            default => [$from->utc()->toDateTimeString(), $to->utc()->toDateTimeString()],
        };
    }

    /** A report's own name for a column ("Revenue" for Amount), or the map's. */
    private function labelFor(array $column, array $declared): string
    {
        $label = trim(mb_substr((string) ($column['label'] ?? ''), 0, 60));

        return $label !== '' ? $label : $declared['label'];
    }

    private function resourcesUsed(array $definition): array
    {
        $keys = [];

        foreach (array_merge($definition['columns'] ?? [], $definition['filters'] ?? [], $definition['sort'] ?? [], $definition['group'] ?? [], $definition['totals'] ?? []) as $item) {
            $keys[] = $item['resource'] ?? '';
        }

        // A plain count names no column, so no resource.
        return array_values(array_filter(array_unique($keys), fn (string $key) => $key !== ''));
    }

    /**
     * A column that names a permission is refused to whoever RUNS the report
     * without it - checked here rather than only in the field picker, because
     * a saved report is run by people other than its author, and a picker is
     * a suggestion. Refused whole rather than the column dropped: a report
     * quietly missing its Amount reads as a report about something else.
     */
    private function mayRead(User $user, array $declared): void
    {
        if (isset($declared['permission']) && ! $user->hasPermission($declared['permission'])) {
            throw new InvalidArgumentException(
                "'{$declared['label']}' needs the {$declared['permission']} permission, which you do not have."
            );
        }
    }

    private function resourceFor(array $item): array
    {
        $key = $item['resource'] ?? '';

        return ReportMap::get($key)
            ?? throw new InvalidArgumentException("There is no reportable resource called '{$key}'.");
    }

    /**
     * Today, fixed for the whole run.
     *
     * Resolved ONCE and memoised, not read per column. A report selecting a
     * derived column and filtering on the same one must use the same day in
     * both, and a run that straddles midnight would otherwise filter on
     * Tuesday and print Wednesday — a row excluded by a value it then displays,
     * on one run a year, at an hour nobody is reading it.
     *
     * `CarbonImmutable::today()` rather than a per-branch clock, deliberately
     * and with a cost: it is the application timezone, and it is the SAME call
     * `SchedulingAvailabilityController` makes to decide which day its grid
     * starts on. So this report and that screen agree about what "today" is,
     * which matters more than either being right for a branch in another
     * timezone — a report disagreeing with the screen it describes is the
     * failure somebody cannot diagnose. A report spanning branches has no
     * single "today" to be correct about anyway.
     */
    private function today(): CarbonImmutable
    {
        return $this->today ??= CarbonImmutable::today();
    }

    /** Fill a derived column's placeholders — ReportMap owns the substitution. */
    private function resolve(string $sql): string
    {
        return ReportMap::resolve($sql, $this->today());
    }

    private function columnFor(array $resource, array $item): array
    {
        $column = $item['column'] ?? '';

        return $resource['columns'][$column]
            ?? throw new InvalidArgumentException(
                "'{$resource['label']}' does not report a column called '{$column}'."
            );
    }

    /**
     * ponytail: a session variable rather than a per-statement hint, because
     * the hint has to sit immediately after the SELECT keyword and Laravel's
     * builder gives no clean seam for that. The ceiling: MySQL only, and reads
     * only, which is all this class runs. If the builder ever grows a hint
     * API, move it there and drop the finally.
     */
    private function withTimeout(callable $fn): mixed
    {
        DB::statement('SET SESSION max_execution_time = '.self::TIMEOUT_MS);

        try {
            return $fn();
        } finally {
            // Connections are pooled, so leaving it set would silently cap
            // every later query on this connection, writes included.
            DB::statement('SET SESSION max_execution_time = 0');
        }
    }

    private function quote(string $identifier): string
    {
        // The identifier always comes from the map, never from the request —
        // but quoting is free, and the day somebody widens the map is the day
        // that stops being obvious.
        return '`'.str_replace('`', '', $identifier).'`';
    }
}
