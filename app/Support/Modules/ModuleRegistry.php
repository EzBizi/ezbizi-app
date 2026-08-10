<?php

namespace App\Support\Modules;

use InvalidArgumentException;

/**
 * Every module the platform knows about (plan §5.5).
 *
 * This is a PLATFORM concern, not tenant data — the set of things the software
 * can do is defined by the software (§2.1). Organizations compose these into
 * their own roles; they don't invent new ones, because a permission with no code
 * behind it enforces nothing.
 */
class ModuleRegistry
{
    /** @var array<string, ModuleDefinition> */
    private array $modules = [];

    public function register(ModuleDefinition $module): void
    {
        if (isset($this->modules[$module->key])) {
            throw new InvalidArgumentException(
                "Module '{$module->key}' is already registered. Keys are permission "
                .'prefixes, so a duplicate would silently merge two modules’ permissions.'
            );
        }

        $this->modules[$module->key] = $module;
    }

    /** @return array<string, ModuleDefinition> */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $key): ?ModuleDefinition
    {
        return $this->modules[$key] ?? null;
    }

    /**
     * The module-declared event with this key, or null if nothing declares it.
     *
     * Both the emitter and the automation validator need to ask this, and they
     * were each walking the registry themselves. Two loops with the same job
     * eventually disagree by one condition, and the one that differs is the one
     * that lets a rule be saved for an event that can never arrive.
     */
    public function findTrigger(string $key): ?TriggerDefinition
    {
        foreach ($this->modules as $module) {
            if (($trigger = $module->trigger($key)) !== null) {
                return $trigger;
            }
        }

        return null;
    }

    /** Every permission key across every module — what the sync command writes. */
    public function allPermissionKeys(): array
    {
        return collect($this->modules)
            ->flatMap(fn (ModuleDefinition $m) => $m->permissionKeys())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Modules breaking the §5.5 standing rule — no triggers, no actions, no
     * stated exemption. The test suite fails on any of these, which is what
     * keeps the rule alive after the twentieth module instead of the second.
     *
     * @return array<string, ModuleDefinition>
     */
    public function violatingAutomationRule(): array
    {
        return array_filter(
            $this->modules,
            fn (ModuleDefinition $m) => ! $m->satisfiesAutomationRule()
        );
    }
}
