<?php

namespace App\Support\Modules;

use InvalidArgumentException;

/**
 * What a module declares about itself (plan §5.5).
 *
 * The standing rule: **every new module must expose its triggers and actions to
 * the automation engine. A module that can't participate in automation is not
 * finished.** This class is how that rule stops being a note in a document and
 * becomes something the test suite can check.
 *
 * A module that genuinely has nothing to automate must say so out loud via
 * `automationExemptBecause` with a reason. Silence is not an option — that's the
 * whole point. Retrofitting automation across twenty modules is how the rule
 * would die, so the cost is paid one module at a time, at the moment it's cheap.
 *
 * Declaring is half of it. A trigger arrives as a TriggerDefinition and an
 * action as an ActionDefinition, each carrying the words a person reads, because
 * the module that raises an event is the only place that can say what it means.
 * A bare key here is a bare key on somebody's screen.
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
        /** @var TriggerDefinition[] events this module raises, each with its wording */
        public readonly array $triggers = [],
        /** @var ActionDefinition[] things a rule may do here, each with its wording */
        public readonly array $automationActions = [],
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

        // `array` cannot say "array of what", so it is said here. A bare string
        // used to be legal and is exactly the regression to catch: it would
        // reach the catalogue as a trigger with no label, which is the screen
        // this whole thing exists to fix.
        $this->assertAllAre($triggers, TriggerDefinition::class, 'triggers');
        $this->assertAllAre($automationActions, ActionDefinition::class, 'automationActions');
    }

    /** @param array<int,mixed> $declarations */
    private function assertAllAre(array $declarations, string $class, string $field): void
    {
        $seen = [];

        foreach ($declarations as $declaration) {
            if (! $declaration instanceof $class) {
                throw new InvalidArgumentException(
                    "Module '{$this->key}' declares a ".get_debug_type($declaration)." in {$field}. "
                    .'Use '.class_basename($class).', so it arrives with a label and a description '
                    .'instead of the tenant reading a wire key off the screen.'
                );
            }

            if (isset($seen[$declaration->key])) {
                throw new InvalidArgumentException(
                    "Module '{$this->key}' declares '{$declaration->key}' twice in {$field}."
                );
            }

            $seen[$declaration->key] = true;
        }
    }

    /** Permission keys this module contributes: `customers.view`, `customers.create`… */
    public function permissionKeys(): array
    {
        return array_map(fn (string $a) => "{$this->key}.{$a}", $this->actions);
    }

    /**
     * Just the keys — for the engine, which routes on them and has no use for
     * the wording.
     *
     * @return string[]
     */
    public function triggerKeys(): array
    {
        return array_map(fn (TriggerDefinition $t) => $t->key, $this->triggers);
    }

    public function trigger(string $key): ?TriggerDefinition
    {
        foreach ($this->triggers as $trigger) {
            if ($trigger->key === $key) {
                return $trigger;
            }
        }

        return null;
    }

    /**
     * Does this module satisfy the §5.5 standing rule?
     * Either it participates in automation, or it says why it doesn't.
     */
    public function satisfiesAutomationRule(): bool
    {
        return $this->automationExemptBecause !== null
            || $this->triggers !== []
            || $this->automationActions !== [];
    }
}
