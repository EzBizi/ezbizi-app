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

        $this->assertParentIsDrawable($module);

        $this->modules[$module->key] = $module;
    }

    /**
     * A module drawn inside another has to name one that exists and is itself
     * top level.
     *
     * Checked here rather than in ModuleDefinition because neither fact is
     * knowable from one definition: a definition is constructed alone and has no
     * view of what else the platform registers. Checked at REGISTRATION rather
     * than on first read because the failure is otherwise invisible until
     * somebody opens the roles editor — a module naming a parent nobody
     * registers is drawn nowhere at all, which looks exactly like a lost
     * permission.
     *
     * It also fixes the order: a parent must be registered before its children,
     * which is the order the sidebar and the roles editor already read.
     */
    private function assertParentIsDrawable(ModuleDefinition $module): void
    {
        if ($module->parent === null) {
            return;
        }

        $parent = $this->modules[$module->parent] ?? null;

        if ($parent === null) {
            throw new InvalidArgumentException(
                "Module '{$module->key}' is drawn inside '{$module->parent}', which is not "
                .'registered before it. A module whose parent never arrives has no surface at '
                .'all — it would vanish from the sidebar and the roles editor together, which '
                .'is indistinguishable from a permission somebody has lost.'
            );
        }

        if ($parent->parent !== null) {
            throw new InvalidArgumentException(
                "Module '{$module->key}' is drawn inside '{$module->parent}', which is itself "
                ."drawn inside '{$parent->parent}'. Nesting is one level: the tab strip and the "
                .'roles editor both draw a parent and its children, and neither is recursive.'
            );
        }
    }

    /**
     * The modules drawn inside this one, in registry order.
     *
     * @return ModuleDefinition[]
     */
    public function childrenOf(string $key): array
    {
        return array_values(array_filter(
            $this->modules,
            fn (ModuleDefinition $m) => $m->parent === $key,
        ));
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

    /**
     * The record a trigger fires about, or null when nothing declares it.
     *
     * The one question the save-time check asks: an `update_field` action writes
     * to the subject of the event, so "may this rule set this field" is really
     * "is this field writable on the thing this TRIGGER is about". Asked here so
     * the controller does not walk the registry itself — findTrigger's comment
     * already records what happens when two loops with the same job drift apart.
     */
    public function subjectOfTrigger(string $key): ?AutomationSubject
    {
        foreach ($this->modules as $module) {
            if ($module->trigger($key) !== null) {
                return $module->subject;
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
