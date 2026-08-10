<?php

namespace App\Support\Modules;

/**
 * Something a module offers a rule the chance to DO, and what it means (§5.5).
 *
 * Whether the engine can actually perform it is not declared here. A module
 * says what it intends to expose; ActionRunner decides what runs, and the
 * catalogue marks the gap between the two. Asking a module to also track the
 * engine's progress would put the same fact in two places, and the copy in the
 * module is the one that would go stale.
 */
final class ActionDefinition extends AutomationDeclaration
{
    /**
     * The generic action the engine performs for this, e.g. `update_field`.
     *
     * The part after the dot, because `customer.update_field` and
     * `job.update_field` are one implementation applied to two subjects.
     */
    public function type(): string
    {
        return substr($this->key, strpos($this->key, '.') + 1);
    }
}
