<?php

namespace App\Support\Integrations\Dependents;

use App\Models\User;
use Illuminate\Contracts\Container\Container;

final class DependentRegistry
{
    public const TAG = 'integration.dependents';

    public function __construct(private readonly Container $app) {}

    /** @return list<array{key: string, label: string, count: int, names?: list<string>}> */
    public function for(IntegrationRef $ref, User $viewer): array
    {
        $out = [];

        foreach ($this->app->tagged(self::TAG) as $counter) {
            /** @var DependentCounter $counter */
            $row = $counter->count($ref, $viewer);

            if ($row !== null && $row['count'] > 0) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Every counter's features for this connection, one line per feature
     * (breaks wins over degrades), breaks first.
     *
     * @return list<array{feature: string, severity: 'breaks'|'degrades'}>
     */
    public function features(IntegrationRef $ref): array
    {
        $seen = [];

        foreach ($this->app->tagged(self::TAG) as $counter) {
            /** @var DependentCounter $counter */
            foreach ($counter->features($ref) as $row) {
                if (! isset($seen[$row['feature']]) || $row['severity'] === Feature::BREAKS) {
                    $seen[$row['feature']] = $row;
                }
            }
        }

        $rows = array_values($seen);
        $rank = fn (array $r) => $r['severity'] === Feature::BREAKS ? 0 : 1;
        usort($rows, fn (array $a, array $b) => $rank($a) <=> $rank($b));

        return $rows;
    }
}
