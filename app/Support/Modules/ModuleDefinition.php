<?php

namespace App\Support\Modules;

use Illuminate\Support\Str;
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

    /**
     * Where this module appears in the roles editor, which must match the
     * sidebar a person actually sees.
     *
     * `tab` is a work destination in the rail. `organization` and `settings`
     * are the two pinned screens at the bottom — there is no Companies tab,
     * so those grants live under Organization. `omit` is declared for
     * automations and is not a grantable surface of its own (Human Resources
     * replaced the Users tab).
     */
    public const ROLES_TAB = 'tab';
    public const ROLES_ORGANIZATION = 'organization';
    public const ROLES_SETTINGS = 'settings';
    public const ROLES_OMIT = 'omit';

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
        /**
         * Every model this module EMITS as an automation subject.
         *
         * A DIFFERENT QUESTION from `$subject` above, and conflating them is a
         * mistake this codebase has already made once:
         *
         *   $subject  one per module, governs which model's FIELDS a rule may
         *             set with `update_field`
         *   $emits    every record type the module passes to `events->emit()`,
         *             governing WHO GETS TOLD about it
         *
         * They coincide for a module that emits one kind of record, which is
         * most of them. They come apart for HR, which raises events carrying
         * invitations, onboardings and contract versions, and for Training,
         * which carries progress, attempts and assignments. Both correctly
         * declare NO `$subject`, because naming one would make the field picker
         * silently wrong about the others — and a check built on `$subject`
         * would have failed exactly the module that got scoping right first.
         *
         * WHY THIS EXISTS AT ALL. `NotificationDispatcher::audienceFor` notifies
         * EVERY ACTIVE USER in the organization when a record cannot say where
         * it sits. `notify` is not gated per trigger, so any raised trigger is
         * an attachment point. The defect is an ABSENCE — a model that never
         * implemented `CarriesAutomationScope` — with no route, no permission
         * and no registry entry to review. It appears in no diff.
         *
         * Declaring what a module emits is the one thing that makes the absence
         * checkable, because a test can then ask of each named model: can this
         * say where it sits? Every control in this family that works — the
         * bidirectional `isRaised` check, the dashboard icon gate — works
         * because something was DECLARED and could be compared against reality.
         *
         * @var array<int, class-string>
         */
        public readonly array $emits = [],
        /**
         * Which face of the app this grant belongs to. Default is a sidebar
         * tab of its own. See ROLES_* above.
         */
        public readonly string $rolesGroup = self::ROLES_TAB,
        /**
         * Is this a rail destination?
         *
         * Default true: ROLES_TAB modules are sidebar tabs. Clock in is grantable
         * the same way and is NOT a tab — the top-bar button is the surface.
         * GlanceRegistry completeness and the client rail both honour this.
         */
        public readonly bool $sidebar = true,
        /**
         * The module this one is drawn INSIDE, or null for a module that stands
         * on its own.
         *
         * ── What a parent changes, and what it deliberately does not ──
         *
         * It changes exactly one thing: WHERE the module is drawn. Leads,
         * Reviews and Business Profile are each a module in full — their own
         * permissions, their own place in this registry, their own triggers and
         * their own dashboard glance — that the owner wanted reached through
         * Marketing rather than from three more rows in a sidebar that already
         * holds fifteen.
         *
         * So it is NOT a permission relationship. Holding `marketing.view`
         * grants nothing about Leads, and holding `leads.view` grants nothing
         * about Marketing; the roles editor draws them nested and still switches
         * them one at a time. Anything else would be a silent grant, which is
         * the one thing §4 does not allow a layout decision to do.
         *
         * It is also NOT availability: the platform can switch Leads off for an
         * organization and leave Marketing on, or the reverse, and each says its
         * own sentence.
         *
         * ── Why the server says it rather than the client ────────
         *
         * The client already takes the existence, the order and the label of
         * every module from here; where it sits was the last piece of that shape
         * still compiled into Dart. A second opinion in the client is how a
         * module ends up in two places at once, or in neither.
         *
         * One level only. A parent that is itself a child would make the roles
         * editor and the tab strip both recursive to answer a question nobody
         * has asked, and `ModuleRegistry::register` refuses it.
         */
        /**
         * The prefixes this module's audit actions use, when they are not its key.
         *
         * §2.2 is "every action, every error, every crash", and its only stated
         * exception is the Lead Developer account — not a module. Nothing enforced
         * it, and six modules that can write turned out to write no audit rows at
         * all while fourteen wrote 121 between them. Automations did not rot the
         * same way, and the only difference is that `automationExemptBecause` made
         * silence impossible. This is the equivalent.
         *
         * PREFIXES rather than a list of actions, deliberately. A per-module list
         * of all 121 would be a second copy of what the code already says, and it
         * would drift in the direction nothing catches: add an `audit->log()` call
         * and forget the list, and the list is still "valid". A prefix lets
         * `ModuleRegistryTest` ask the source the actual question — does this
         * module write ANY audit row — so the answer stays true without
         * maintenance.
         *
         * Defaults to the module key, which is right for eleven of the fourteen.
         * Only a module whose actions are named after its records rather than
         * itself declares this: HR writes `onboarding.*`, `invitation.*` and
         * `contract.*`; Email writes `mailbox.*`; Marketing writes `social.*`.
         *
         * @var string[]
         */
        public readonly array $auditActionPrefixes = [],
        /**
         * Non-null = this module deliberately writes no audit rows, with a reason.
         *
         * A reason that describes an unclosed gap is legitimate and should say so
         * plainly; one that invents a design rationale is not. A fabricated
         * decision record is worse than a blank, because the next reader takes it
         * as settled and never reopens it.
         */
        public readonly ?string $auditExemptBecause = null,
        public readonly ?string $parent = null,
    ) {
        if ($parent !== null && $parent === $key) {
            throw new InvalidArgumentException(
                "Module '{$key}' names itself as its parent."
            );
        }

        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException(
                "Module key '{$key}' must be lower_snake_case — it becomes the permission prefix."
            );
        }

        if ($actions === []) {
            throw new InvalidArgumentException("Module '{$key}' declares no actions.");
        }

        if (! in_array($this->rolesGroup, [
            self::ROLES_TAB,
            self::ROLES_ORGANIZATION,
            self::ROLES_SETTINGS,
            self::ROLES_OMIT,
        ], true)) {
            throw new InvalidArgumentException(
                "Module '{$key}' has unknown rolesGroup '{$this->rolesGroup}'."
            );
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

    /**
     * Actions that only read. Anything else a module names is taken to write,
     * so the audit rule asks about it. Kept short on purpose: an action the
     * list does not know is treated as a write, which makes the rule ask a
     * question rather than stay silent — the failure of the first draft,
     * which matched the literals create/edit/delete and so never asked
     * `time_clock` (lock_screen), `training` (manage, assign) or `monitoring`
     * (cameras.manage, control) anything while all three change records.
     */
    public const READ_ONLY_ACTIONS = ['view', 'export', 'monitor', 'lock_screen'];

    /**
     * Can this module change anything? Only a module that writes can owe an
     * audit trail, so a read-only one is outside the rule rather than exempt
     * from it — there is nothing for it to state a reason about. "Writes"
     * means: names any action that is not a read (see READ_ONLY_ACTIONS; a
     * dotted action ending in `.view` is a read too).
     */
    public function writesRecords(): bool
    {
        foreach ($this->actions as $action) {
            if (in_array($action, self::READ_ONLY_ACTIONS, true) || str_ends_with($action, '.view')) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * The prefixes an audit action from this module would start with.
     *
     * @return string[]
     */
    public function auditPrefixes(): array
    {
        if ($this->auditActionPrefixes !== []) {
            return $this->auditActionPrefixes;
        }

        // Module keys are plural and audit actions are singular — the `jobs`
        // module writes `job.created`. Both are tried so the common case needs
        // no declaration at all; `inspection` and `scheduling` are already
        // singular and Str::singular leaves them alone.
        return array_values(array_unique([$this->key, Str::singular($this->key)]));
    }

    /**
     * Does this module satisfy §2.2, given what the source actually contains?
     *
     * `$writesAudit` is the answer the test derives by reading `app/`, rather
     * than a claim stored here. A module owes an audit trail only if it can
     * write; if it can and does not, it has to say why.
     */
    public function satisfiesAuditRule(bool $writesAudit): bool
    {
        return ! $this->writesRecords()
            || $writesAudit
            || $this->auditExemptBecause !== null;
    }

    /**
     * Words the roles editor uses for a group heading.
     *
     * @return array{key: string, label: string, hint: ?string}|null
     */
    public static function rolesGroupMeta(string $group): ?array
    {
        return match ($group) {
            self::ROLES_TAB => null,
            self::ROLES_ORGANIZATION => [
                'key' => self::ROLES_ORGANIZATION,
                'label' => 'Organization',
                'hint' => 'The Organization tab in Settings. There is no Companies or Franchises tab.',
            ],
            self::ROLES_SETTINGS => [
                'key' => self::ROLES_SETTINGS,
                'label' => 'Settings',
                'hint' => 'Settings at the bottom of the sidebar — the shape of the business, how the work behaves, and who may do it.',
            ],
            default => null,
        };
    }
}
