<?php

namespace App\Support\Integrations\Dependents;

use App\Models\User;

/**
 * One module's answer to "what of mine stops working if this goes?".
 *
 * Each module registers its own counter (AppServiceProvider tags them
 * DependentRegistry::TAG), so the disconnect panel never hardcodes a list.
 * Counts are always computed; `names` are only filled in when the viewer may
 * see that module.
 */
interface DependentCounter
{
    /**
     * Null (or a count of 0) when nothing of this module depends on it.
     *
     * @return array{key: string, label: string, count: int, names?: list<string>}|null
     */
    public function count(IntegrationRef $ref, User $viewer): ?array;
}
