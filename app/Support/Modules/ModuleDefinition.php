<?php

namespace App\Support\Modules;

use InvalidArgumentException;

/**
 * What a module declares about itself (plan §5.5).
 *
 * The standing rule: **every new module must expose its triggers and actions to
 * the workflow engine. A module that can't participate in automation is not
 * finished.** This class is how that rule stops being a note in a document and
 * becomes something the test suite can check.
 *
 * A module that genuinely has nothing to automate must say so out loud via
 * `automationExempt()` with a reason. Silence is not an option — that's the
 * whole point. Retrofitting automation across twenty modules is how the rule
 * would die, so the cost is paid one module at a time, at the moment it's cheap.
 */
final class ModuleDefinition
{
    /** The default CRUD set. Stored per-action even though the UI shows one toggle (§4). */
    public const DEFAULT_ACTIONS = ['view', 'create', 'edit', 'delete'];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        /** @var string[] permission actions, e.g. ['view','create'] */
        public readonly array $actions = self::DEFAULT_ACTIONS,
        /** @var string[] workflow trigger keys, e.g. ['customer.created'] */
        public readonly array $triggers = [],
        /** @var string[] workflow action keys, e.g. ['customer.assign'] */
        public readonly array $workflowActions = [],
        /** Non-null = deliberately outside automation, with a stated reason. */
        public readonly ?string $automationExemptBecause = null,
    ) {
        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException(
                "Module key '{$key}' must be lower_snake_case — it becomes the permission prefix."
            );
        }

        if ($actions === []) {
            throw new InvalidArgumentException("Module '{$key}' declares no actions.");
        }
    }

    /** Permission keys this module contributes: `customers.view`, `customers.create`… */
    public function permissionKeys(): array
    {
        return array_map(fn (string $a) => "{$this->key}.{$a}", $this->actions);
    }

    /**
     * Does this module satisfy the §5.5 standing rule?
     * Either it participates in automation, or it says why it doesn't.
     */
    public function satisfiesAutomationRule(): bool
    {
        return $this->automationExemptBecause !== null
            || $this->triggers !== []
            || $this->workflowActions !== [];
    }
}
