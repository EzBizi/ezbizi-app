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
}
