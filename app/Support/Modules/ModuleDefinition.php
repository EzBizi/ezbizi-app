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
        /**
         * The record this module's events are about — the thing a rule built on
         * one of them can then change.
         *
         * Null for a module whose events carry no record, for an exempt module
         * that raises none at all, and — since Human Resources — for a module
         * whose events are about SEVERAL kinds of record. One subject per module
         * is what `assertKeysMatchSubject` below enforces, and HR's events are
         * about an invitation, an onboarding and a contract version; naming any
         * one of the three would make the field picker wrong about the other
         * two, so it names none.
         *
         * Null is not "we didn't get round to it": it means an `update_field`
         * action under such a trigger has nothing to write to, and the catalogue
         * and the validator both say so. What it costs is that the builder
         * cannot grey the action out in advance the way it can for a module that
         * names an unwritable subject — it is refused at save instead, in a
         * sentence. Closing that gap means a subject per TRIGGER, which is a
         * change to the wire shape the catalogue publishes as well as to this
         * class.
         */
        public readonly ?AutomationSubject $subject = null,
    ) {
        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException(
                "Module key '{$key}' must be lower_snake_case — it becomes the permission prefix."
            );
        }

        if ($actions === []) {
            throw new InvalidArgumentException("Module '{$key}' declares no actions.");
        }

        /*
         * Every module has a `view`, because the TAB IS THE VIEW PERMISSION (§4).
         *
         * The roles editor shows one switch per module with the actions folded
         * underneath, and the owner's reason for one switch rather than two is
         * that a role must never be able to reach "may edit, tab hidden" or
         * "sees the tab, may view nothing". That collapses to: holding any
         * action of a module implies holding its view (Role::syncPermissionKeys),
         * and reaching a module means holding its view (PermissionCatalogue).
         *
         * A module with no `view` action would have no tab permission at all, so
         * neither half of that rule could be stated about it — it would appear
         * in the editor as a set of actions with no switch to turn on. Refused
         * at boot, where whoever is writing the module is still looking.
         */
        if (! in_array('view', $actions, true)) {
            throw new InvalidArgumentException(
                "Module '{$key}' declares no 'view' action. The tab permission IS the view "
                .'permission (§4), so a module without one cannot be reached or granted.'
            );
        }

        // `array` cannot say "array of what", so it is said here. A bare string
        // used to be legal and is exactly the regression to catch: it would
        // reach the catalogue as a trigger with no label, which is the screen
        // this whole thing exists to fix.
        $this->assertAllAre($triggers, TriggerDefinition::class, 'triggers');
        $this->assertAllAre($automationActions, ActionDefinition::class, 'automationActions');
        $this->assertKeysMatchSubject();
    }

    /**
     * A declared subject has to be the one the keys already name.
     *
     * `AutomationSubject::key()` derives `job` from `App\Models\Job`, and the
     * whole field picker rests on that being the same `job` the trigger keys are
     * prefixed with. A module whose keys disagree with its subject produces a
     * picker offering a customer's fields on a job's trigger — precisely the bug
     * this declaration was added to fix — and the failure is not a crash, so it
     * fails at boot instead, where whoever wrote the module is still looking.
     *
     * ── What it does NOT catch ───────────────────────────────────
     *
     * Only the declared KEYS, against the declared subject. A module that
     * declared `job.customer_notified` and then emitted it carrying a Customer
     * would pass this and still mislead the picker, because nothing here sees
     * the emit. No `->emit()` call site in the tree passes a model of a kind its
     * key does not name — the one that passes NO model is `user.invited`, which
     * fires before the user exists and says so where it is emitted — so the
     * invariant holds today by discipline rather than by construction. The place
     * that could enforce it properly is EventEmitter::emit, which has both the
     * key and the subject in hand.
     *
     * Stated as a rule with its one exception rather than as a count of call
     * sites: the count was "sixteen" and there are twenty, which is what a
     * number nothing re-checks does the moment a controller gains an event.
     */
    private function assertKeysMatchSubject(): void
    {
        if ($this->subject === null) {
            return;
        }

        $expected = $this->subject->key();

        foreach ([...$this->triggers, ...$this->automationActions] as $declaration) {
            $prefix = strstr($declaration->key, '.', before_needle: true);

            if ($prefix !== $expected) {
                throw new InvalidArgumentException(
                    "Module '{$this->key}' declares '{$declaration->key}', but says its events "
                    ."are about {$this->subject->label}, whose keys begin '{$expected}.'. One "
                    .'module cannot be about two kinds of record — the field picker reads the '
                    .'subject off the trigger and would offer the wrong list.'
                );
            }
        }
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
