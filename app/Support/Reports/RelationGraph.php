<?php

namespace App\Support\Reports;

/**
 * The shortest declared route between two reportable tables (spec 2026-09-10).
 *
 * ── Edges are walked in BOTH directions ─────────────────────────
 *
 * A declared edge is a foreign key, and a foreign key points one way: `jobs`
 * holds `customer_id`, so `jobs -> customers` is the only direction anybody
 * would think to write down. Walking only that direction meant a report
 * STARTING at Customers could not reach Jobs at all, and one starting at
 * People could reach nothing — which is not the reach Jose asked for. He chose
 * "anything joined to anything", and a graph that answers half its pairs is
 * not that.
 *
 * So every declared edge is traversable from either end. The join predicate is
 * identical in both directions — `jobs.customer_id = customers.id` — and only
 * which table is being ADDED changes, which is why a hop carries a column per
 * side rather than a "local" and a "foreign".
 *
 * This adds no new reach beyond what somebody declared: you still cannot join
 * two tables nobody connected. It makes the declaration mean what a reader
 * assumes it means.
 *
 * ── One-to-many is real, and it multiplies rows ─────────────────
 *
 * Walked forwards an edge is many-to-one: every job has one customer. Walked
 * backwards it is one-to-many: a customer with five jobs produces five rows.
 * That is the correct answer to "customers and their jobs" and it surprises
 * people, so the builder should say so when a report crosses a reverse hop.
 * The graph reports `reversed` on each hop for exactly that.
 *
 * ── There is no depth limit ─────────────────────────────────────
 *
 * Deliberate. The bound is the map, not a number: you can only reach what
 * somebody declared an edge to. Breadth-first, so the path found crosses as
 * few tables as possible — and every table crossed is one the runner re-scopes.
 */
final class RelationGraph
{
    /**
     * @return list<array{
     *     from: string, to: string,
     *     from_column: string, to_column: string,
     *     reversed: bool
     * }>|null  null when there is no declared route; [] when from === to
     */
    public static function pathBetween(string $from, string $to): ?array
    {
        if (ReportMap::get($from) === null || ReportMap::get($to) === null) {
            return null;
        }

        if ($from === $to) {
            return [];
        }

        $adjacency = static::adjacency();

        // `$cameBy` doubles as the visited set, so a cycle terminates rather
        // than looping — and a bidirectional graph is nothing but cycles.
        $queue = [$from];
        $cameBy = [$from => null];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($adjacency[$current] ?? [] as $hop) {
                $next = $hop['to'];

                if (array_key_exists($next, $cameBy)) {
                    continue;
                }

                $cameBy[$next] = $hop;

                if ($next === $to) {
                    return static::rebuild($cameBy, $to);
                }

                $queue[] = $next;
            }
        }

        return null;
    }

    /**
     * Every declared edge, plus its reverse.
     *
     * @return array<string, list<array{
     *     from: string, to: string,
     *     from_column: string, to_column: string,
     *     reversed: bool
     * }>>
     */
    public static function adjacency(): array
    {
        $out = [];

        foreach (ReportMap::all() as $key => $resource) {
            foreach ($resource['relations'] as $edge) {
                $out[$key][] = [
                    'from'        => $key,
                    'to'          => $edge['to'],
                    'from_column' => $edge['local'],
                    'to_column'   => $edge['foreign'],
                    'reversed'    => false,
                ];

                // The same predicate, read from the other end.
                $out[$edge['to']][] = [
                    'from'        => $edge['to'],
                    'to'          => $key,
                    'from_column' => $edge['foreign'],
                    'to_column'   => $edge['local'],
                    'reversed'    => true,
                ];
            }
        }

        return $out;
    }

    /** @return list<array{from: string, to: string, from_column: string, to_column: string, reversed: bool}> */
    private static function rebuild(array $cameBy, string $to): array
    {
        $path = [];

        for ($at = $to; $cameBy[$at] !== null; $at = $cameBy[$at]['from']) {
            array_unshift($path, $cameBy[$at]);
        }

        return $path;
    }
}
