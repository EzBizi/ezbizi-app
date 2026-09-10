<?php

namespace App\Support\Reports;

/**
 * The shortest declared route between two reportable tables (spec 2026-09-10).
 *
 * Breadth-first, so the path found is the fewest hops. That matters for more
 * than speed: a shorter path crosses fewer tables, and every table crossed is
 * one the runner must re-scope.
 *
 * There is no depth limit and that is deliberate — Jose asked for anything
 * joined to anything. The bound is not a number, it is the map: you can only
 * reach what somebody declared an edge to, so widening the reach is a review
 * of ReportMap rather than a runtime decision.
 */
final class RelationGraph
{
    /**
     * @return list<array{from: string, to: string, local: string, foreign: string}>|null
     *     null when there is no declared route, [] when from and to are the same
     */
    public static function pathBetween(string $from, string $to): ?array
    {
        if (ReportMap::get($from) === null || ReportMap::get($to) === null) {
            return null;
        }

        if ($from === $to) {
            return [];
        }

        // `$cameBy` doubles as the visited set, so a cycle terminates rather
        // than looping — and maps do grow cycles the moment two tables declare
        // edges to each other.
        $queue = [$from];
        $cameBy = [$from => null];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach (ReportMap::get($current)['relations'] as $edge) {
                $next = $edge['to'];

                if (array_key_exists($next, $cameBy)) {
                    continue;
                }

                $cameBy[$next] = [
                    'from'    => $current,
                    'to'      => $next,
                    'local'   => $edge['local'],
                    'foreign' => $edge['foreign'],
                ];

                if ($next === $to) {
                    return static::rebuild($cameBy, $to);
                }

                $queue[] = $next;
            }
        }

        return null;
    }

    /** @return list<array{from: string, to: string, local: string, foreign: string}> */
    private static function rebuild(array $cameBy, string $to): array
    {
        $path = [];

        for ($at = $to; $cameBy[$at] !== null; $at = $cameBy[$at]['from']) {
            array_unshift($path, $cameBy[$at]);
        }

        return $path;
    }
}
