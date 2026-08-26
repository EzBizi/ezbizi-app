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
    private readonly string $executionType;

    public function __construct(
        string $key,
        string $label,
        string $description,
        ?string $executionType = null,
        public readonly bool $subjectBound = true,
    ) {
        parent::__construct($key, $label, $description);

        $this->executionType = $executionType
            ?? substr($key, strpos($key, '.') + 1);

        if ($executionType !== null
            && ! preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $this->executionType)) {
            throw new \InvalidArgumentException(
                "Action execution type '{$this->executionType}' must use a dotted "
                .'lower_snake_case key such as task.create.'
            );
        }
    }

    /**
     * The generic action the engine performs for this, e.g. `update_field`.
     *
     * The part after the dot, because `customer.update_field` and
     * `job.update_field` are one implementation applied to two subjects.
     */
    public function type(): string
    {
        return $this->executionType;
    }
}
