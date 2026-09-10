<?php

namespace App\Support\Reports;

use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
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
        'eq'       => '=',
        'ne'       => '!=',
        'lt'       => '<',
        'lte'      => '<=',
        'gt'       => '>',
        'gte'      => '>=',
        'contains' => 'like',
    ];

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
    public function run(array $definition, User $user): ReportResult
    {
        return $this->tenant->run($user->organization, fn () => $this->build($definition, $user));
    }

    private function build(array $definition, User $user): ReportResult
    {
        $root = $definition['root'] ?? '';
        $rootResource = ReportMap::get($root)
            ?? throw new InvalidArgumentException("There is no reportable resource called '{$root}'.");

        $view = ViewScope::forUser($user);
        $organizationId = $this->tenant->idOrFail();

        $query = DB::table($rootResource['table']);

        // The root is scoped in WHERE; it is the table every row comes from,
        // so narrowing it is exactly the intent.
        $this->applyScope(
            fn (string $column, string $operator, mixed $value) => $query->where($column, $operator, $value),
            fn (string $column, array $values) => $query->whereIn($column, $values),
            fn (string $column) => $query->whereNull($column),
            $rootResource,
            $organizationId,
            $view,
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
                $to   = ReportMap::get($hop['to']);

                $query->leftJoin($to['table'], function (JoinClause $join) use (
                    $from, $to, $hop, $organizationId, $view
                ) {
                    $join->on(
                        $from['table'].'.'.$hop['local'],
                        '=',
                        $to['table'].'.'.$hop['foreign'],
                    );

                    $this->applyScope(
                        fn (string $c, string $o, mixed $v) => $join->where($c, $o, $v),
                        fn (string $c, array $v) => $join->whereIn($c, $v),
                        fn (string $c) => $join->whereNull($c),
                        $to,
                        $organizationId,
                        $view,
                    );
                });

                $joined[$hop['to']] = true;
            }
        }

        $columns = [];

        foreach ($definition['columns'] ?? [] as $column) {
            $resource = $this->resourceFor($column);
            $declared = $this->columnFor($resource, $column);
            $key = $column['resource'].'.'.$column['column'];

            $query->addSelect(
                DB::raw(
                    $this->quote($resource['table']).'.'.$this->quote($column['column'])
                    .' as '.$this->quote($key)
                )
            );

            $columns[] = ['key' => $key, 'label' => $declared['label'], 'type' => $declared['type']];
        }

        if ($columns === []) {
            throw new InvalidArgumentException('A report needs at least one column.');
        }

        foreach ($definition['filters'] ?? [] as $filter) {
            $resource = $this->resourceFor($filter);
            $this->columnFor($resource, $filter);

            $operator = self::OPERATORS[$filter['op'] ?? '']
                ?? throw new InvalidArgumentException(
                    "'".($filter['op'] ?? '')."' is not a filter this report can use."
                );

            $value = $filter['value'] ?? null;

            $query->where(
                $resource['table'].'.'.$filter['column'],
                $operator,
                $operator === 'like' ? '%'.$value.'%' : $value,
            );
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

        return new ReportResult($columns, $rows, $capped, count($rows));
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
        array $resource,
        string $organizationId,
        ViewScope $view,
    ): void {
        $table = $resource['table'];

        $where($table.'.organization_id', '=', $organizationId);

        if ($resource['soft_deletes']) {
            $whereNull($table.'.deleted_at');
        }

        foreach ($resource['scope'] as $column) {
            $ids = match ($column) {
                'franchise_id' => $view->franchiseIds(),
                default => throw new LogicException(
                    "ReportMap declares scope column '{$column}' on '{$table}', and this "
                    .'runner does not know which view ids resolve it. Teach it here rather '
                    .'than dropping the filter — an unresolved scope column must never '
                    .'become an unscoped table.'
                ),
            };

            // An empty list means this person reaches nowhere, which is a
            // legitimate answer of "no rows" rather than "no filter".
            $whereIn($table.'.'.$column, $ids);
        }
    }

    /** @return list<string> */
    private function resourcesUsed(array $definition): array
    {
        $keys = [];

        foreach (array_merge($definition['columns'] ?? [], $definition['filters'] ?? []) as $item) {
            $keys[] = $item['resource'] ?? '';
        }

        return array_values(array_unique($keys));
    }

    private function resourceFor(array $item): array
    {
        $key = $item['resource'] ?? '';

        return ReportMap::get($key)
            ?? throw new InvalidArgumentException("There is no reportable resource called '{$key}'.");
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
