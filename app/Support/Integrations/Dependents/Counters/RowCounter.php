<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\User;
use App\Support\Integrations\Dependents\DependentCounter;
use App\Support\Integrations\Dependents\IntegrationRef;
use Illuminate\Database\Eloquent\Builder;

/**
 * A counter that is one query: "rows of mine pointing at this connection".
 *
 * A subclass says which connections it is about, what to count and how to say
 * it; the organization filter is added here so no counter can forget it.
 */
abstract class RowCounter implements DependentCounter
{
    abstract protected function key(): string;

    /** Whether this counter has anything to say about this connection. */
    abstract protected function applies(IntegrationRef $ref): bool;

    /** The rows that depend on the connection, before the organization filter. */
    abstract protected function query(IntegrationRef $ref): Builder;

    abstract protected function label(int $count): string;

    final public function count(IntegrationRef $ref, User $viewer): ?array
    {
        if (! $this->applies($ref)) {
            return null;
        }

        $query = $this->query($ref);
        $n = (clone $query)->where($query->getModel()->qualifyColumn('organization_id'), $ref->organizationId)->count();

        return ['key' => $this->key(), 'label' => $this->label($n), 'count' => $n];
    }

    protected static function plural(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }
}
