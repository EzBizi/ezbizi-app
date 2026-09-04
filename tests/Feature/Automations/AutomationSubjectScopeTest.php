<?php

namespace Tests\Feature\Automations;

use App\Support\Automations\CarriesAutomationScope;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Who hears about an automation event.
 *
 * ── Why this exists ──────────────────────────────────────────────
 *
 * `NotificationDispatcher::audienceFor` asks a record where it sits. If the
 * record cannot answer — no `CarriesAutomationScope`, and no `franchise_id` or
 * `owning_franchise_id` column for the sniff to find — it falls through to
 * EVERY ACTIVE USER IN THE ORGANIZATION.
 *
 * That fallthrough is deliberate and correct for a genuine organization-level
 * event; "your trial ends tomorrow" should reach everybody. It is wrong for a
 * record belonging to one franchise, and `notify` is not gated per trigger —
 * `AutomationsController::IMPLEMENTED_ACTIONS` is a flat list, so a tenant can
 * attach it to ANY raised trigger. The subject list is therefore the exposure
 * list, and nothing is protected by being obscure.
 *
 * It has already happened once: HR shipped a leak of exactly this shape and
 * fixed it by putting the interface on Onboarding and Invitation. The fix went
 * on those two records; the class of problem was left open.
 *
 * ── Why a test and not a review ──────────────────────────────────
 *
 * THE DEFECT IS AN ABSENCE. A module raises an event with a subject that never
 * implemented an interface — no route added, no permission changed, no registry
 * entry, nothing to grep for. It appears in no diff, so no amount of reviewing
 * changes will catch it. It needs a standing check.
 *
 * Proposed by the Training session, after their reviewer built a `notify` rule
 * on a Training trigger and watched a user holding no training permission get
 * told about another franchise's learner.
 *
 * ── WHAT THIS TEST DOES NOT COVER, and why it is only half ───────
 *
 * This checks `ModuleDefinition::$subject`, and that field answers a DIFFERENT
 * QUESTION from the one above:
 *
 *   $subject               one per module, governs which model's FIELDS a rule
 *                          may set (`update_field`)
 *   CarriesAutomationScope per emitted record, governs WHO GETS TOLD
 *
 * They coincide for a module that emits one kind of record, which is most of
 * them, and that is why checking `$subject` is worth anything at all. They come
 * apart for a module that emits several. HR raises events carrying invitations,
 * onboardings and contract versions; Training will carry progress, attempts and
 * assignments. Both correctly declare NO `$subject`, because naming one would
 * make the field picker silently wrong about the others.
 *
 * So the first version of this test asserted "every module that raises an event
 * declares a subject" and would have failed Training on day one — the one module
 * whose first raised trigger was correctly scoped in the same commit that raised
 * it. The fix it demanded would have made things worse. Caught by the Training
 * session reading the test before it ran.
 *
 * **The complete check needs a declaration that does not exist yet:** the models
 * a module EMITS, as a list, separate from the single `$subject` used for
 * `update_field`. Then every emitted model can be required to carry scope, a
 * module with one record type declares a list of one, and nothing has to lie.
 * That is a change to every module definition in the fleet, so it is a design
 * decision rather than something to invent inside a test file.
 *
 * Until then this covers the modules that declare a single subject, and the
 * six that emit several — hr, time_clock, inspection, marketing, phone, email —
 * are NOT covered by anything. Two of them, `phone` and `inspection`, are known
 * to reach every user today.
 *
 * Also outside it: `HrInvitationsController:178` calls
 * `emit('user.invited', payload: [...])` with the subject skipped entirely, so
 * the subject is `null` and reaches the same fallthrough. There is no model to
 * hand an interface to. Closing that means `emit()` refusing a missing subject.
 *
 * ── AND IT CHECKS SHAPE, NEVER THE ANSWER ────────────────────────
 *
 * The sharpest limitation, because it is the one this file's own name hides.
 * Nothing here ever CALLS `automationScope()` — grep this file for it and the
 * count is zero. Every assertion is about whether the interface is present,
 * not about what it returns.
 *
 * So a model can implement `CarriesAutomationScope`, satisfy every check in
 * these 344 lines, and still answer "the whole organization" for a record that
 * belongs to one franchise — which is the widening the interface exists to
 * prevent. Measured by the Marketing session: with Newsletter's franchise arm
 * flattened to `[null, null]`, this file stays entirely green. Their mutation
 * to `$emits` was caught here; their mutation to the MAPPING was not.
 *
 * Four of the eight models implementing the interface have no test anywhere
 * that reads their answer — `Invitation`, `PhoneCall`, `PhoneMessage`,
 * `TrainingAssignment`. `Mailbox`, `Onboarding`, `TrainingAttempt` and
 * `TrainingProgress` do, in their own modules' suites.
 *
 * ── Why the obvious fix is not written here ──────────────────────
 *
 * The proposal was: for each declared emitter that has a factory, assert a
 * record filed at a franchise does not answer `[null, null]`. **NONE of the
 * eight models has a factory** (checked 2026-09-04: no `Invitation`,
 * `Mailbox`, `Onboarding`, `PhoneCall`, `PhoneMessage`, `TrainingAssignment`,
 * `TrainingAttempt` or `TrainingProgress` factory exists). That assertion would
 * iterate an empty set and pass — a check that is green because it never ran,
 * which is strictly worse than no check, because the next reader counts it.
 *
 * Building the eight factories is not a test-file change either: each needs a
 * whole tenant graph, and each belongs to its module rather than here.
 *
 * **So the honest division is: this file proves the interface is DECLARED, and
 * each module owes a test that reads what its own records ANSWER.** Written
 * down rather than fixed silently, because a gate trusted for something it does
 * not do is how the leak it guards gets re-introduced by somebody who checked.
 *
 * When those four owed tests exist, the assertion worth adding here is a
 * COVERAGE one — every model implementing the interface is read by some test
 * somewhere — which needs no factories and turns the absence above into a red
 * build. It is not added today only because it would go red on four modules
 * that have not been asked yet, and a shared gate is not the place to open a
 * conversation.
 */
class AutomationSubjectScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Declared subjects that reach every active user in the organization.
     *
     * Listed, not accepted. Each is a real exposure today, and each needs its
     * owner to decide whether the record is organization-level in truth or
     * simply never got the interface.
     *
     * A ratchet. Delete a line when the model implements
     * `CarriesAutomationScope`; never add one to make a red go away.
     */
    private const REACHES_THE_WHOLE_ORGANIZATION = [
        'companies',
        'franchises',
        'users',
        'monitoring',
    ];

    /**
     * Modules raising triggers that have not yet declared what they EMIT.
     *
     * Grandfathered, and the list only shrinks. Every module here predates
     * `ModuleDefinition::$emits`; each one's owner declares the record types it
     * passes to `events->emit()` and deletes its line. The next module to raise
     * a trigger cannot join this list — that is the entire point, because the
     * defect this guards is an absence and absences do not appear in diffs.
     *
     * Approved by Jose on 2026-08-31 as the permanent fix for the disclosure
     * class. Proposed by the Training session, whose reviewer hit it live.
     */
    private const HAS_NOT_DECLARED_WHAT_IT_EMITS = [
        'companies', 'franchises', 'users', 'hr', 'time_clock',
        'customers', 'jobs', 'inspection', 'marketing', 'phone',
        'fleet_management', 'inventory', 'services', 'leads', 'reviews',
        'business_profile', 'email', 'monitoring',
    ];

    /** @return array<string, \App\Support\Modules\ModuleDefinition> modules raising at least one trigger */
    private function modulesThatRaise(): array
    {
        $out = [];

        foreach (app(ModuleRegistry::class)->all() as $key => $module) {
            foreach ($module->triggers ?? [] as $trigger) {
                if (($trigger->isRaised ?? false) === true) {
                    $out[$key] = $module;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * A floor, because this scope is DERIVED.
     *
     * Every assertion below diffs against a registry filter. An empty filter
     * makes every diff empty and every test green while checking nothing, with
     * nothing to say the scan stopped working. Rename `isRaised` or change the
     * trigger shape and this file reports success forever.
     *
     * StartUp.md §8: floor anything whose scope can become empty without
     * somebody editing this file.
     */
    public function test_the_registry_scan_actually_finds_modules(): void
    {
        $this->assertGreaterThan(
            10,
            count($this->modulesThatRaise()),
            'Found almost no modules raising triggers — the registry scan has stopped matching, so every other test in this file is passing without checking anything.',
        );
    }

    public function test_every_declared_subject_can_say_where_it_sits(): void
    {
        $unscoped = [];

        foreach ($this->modulesThatRaise() as $key => $module) {
            if ($module->subject === null) {
                continue;   // emits several kinds; see the class docblock
            }

            $model = $module->subject->model;

            $this->assertTrue(class_exists($model), "Module '$key' declares subject $model, which does not exist.");

            if ((new ReflectionClass($model))->implementsInterface(CarriesAutomationScope::class)) {
                continue;
            }

            /*
             * The same column sniff audienceFor performs, asked the same way.
             *
             * Reliable HERE because RefreshDatabase has migrated the full
             * schema. Run the identical check against a partially-migrated dev
             * database and `hasTable` answers "not measured" while looking
             * exactly like "no" — which named four modules as leaking that were
             * not, on 2026-08-31, before the migrations were read instead.
             */
            $table = (new $model)->getTable();

            $this->assertTrue(Schema::hasTable($table), "Module '$key' declares subject $model whose table '$table' does not exist.");

            if (Schema::hasColumn($table, 'franchise_id') || Schema::hasColumn($table, 'owning_franchise_id')) {
                continue;
            }

            if (! in_array($key, self::REACHES_THE_WHOLE_ORGANIZATION, true)) {
                $unscoped[] = sprintf('%s (%s)', $key, class_basename($model));
            }
        }

        sort($unscoped);

        $this->assertSame([], $unscoped, sprintf(
            "These modules raise events whose subject cannot say where it sits, so\n".
            "NotificationDispatcher notifies EVERY ACTIVE USER in the organization:\n  %s\n\n".
            "`notify` is not gated per trigger, so a tenant can attach it to any of these.\n\n".
            "Implement App\\Support\\Automations\\CarriesAutomationScope on the model, or add\n".
            'the module to REACHES_THE_WHOLE_ORGANIZATION if the event genuinely belongs to '.
            'everybody in the business.',
            implode("\n  ", $unscoped),
        ));
    }

    /**
     * The list shrinks and never quietly stops applying.
     *
     * Without this, a module fixed in the registry but left in the list looks
     * exempt forever and the next reader cannot tell which entries are real.
     */
    public function test_the_exception_list_names_only_modules_still_unscoped(): void
    {
        $raising = $this->modulesThatRaise();
        $stale = [];

        foreach (self::REACHES_THE_WHOLE_ORGANIZATION as $key) {
            if (! isset($raising[$key]) || $raising[$key]->subject === null) {
                continue;
            }

            $model = $raising[$key]->subject->model;

            if (class_exists($model) && (new ReflectionClass($model))->implementsInterface(CarriesAutomationScope::class)) {
                $stale[] = "$key — its subject now carries scope";
            }
        }

        sort($stale);

        $this->assertSame([], $stale, sprintf(
            "These are listed as reaching the whole organization and no longer do:\n  %s\n\nRemove them.",
            implode("\n  ", $stale),
        ));
    }

    /**
     * A module that raises an event says which records it emits.
     *
     * This is the assertion the whole file was built for, and it took two
     * attempts. The first version asked whether a module declared `$subject` —
     * which governs field editing, not audience — and would have failed the one
     * module that got scoping right first. `$emits` asks the question that
     * `audienceFor` actually answers.
     */
    public function test_a_module_that_raises_events_declares_what_it_emits(): void
    {
        $missing = [];

        foreach ($this->modulesThatRaise() as $key => $module) {
            if ($module->emits === [] && ! in_array($key, self::HAS_NOT_DECLARED_WHAT_IT_EMITS, true)) {
                $missing[] = $key;
            }
        }

        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "These modules raise automation events without declaring what they emit:
  %s

".
            "Add `emits: [YourModel::class]` to the module definition — every record type you
".
            "pass to events->emit(), not just the main one.

".
            'Until a module declares this, nothing can ask whether the records it broadcasts '.
            'can say where they sit, and a `notify` rule on any of its triggers may reach '.
            'every active user in the organization.',
            implode("
  ", $missing),
        ));
    }

    /** Every declared emitted model can say where it sits. */
    public function test_every_emitted_model_can_say_where_it_sits(): void
    {
        $unscoped = [];

        foreach ($this->modulesThatRaise() as $key => $module) {
            foreach ($module->emits as $model) {
                $this->assertTrue(class_exists($model), "Module '$key' emits $model, which does not exist.");

                if ((new ReflectionClass($model))->implementsInterface(CarriesAutomationScope::class)) {
                    continue;
                }

                $table = (new $model)->getTable();

                if (Schema::hasTable($table)
                    && (Schema::hasColumn($table, 'franchise_id') || Schema::hasColumn($table, 'owning_franchise_id'))) {
                    continue;
                }

                $unscoped[] = sprintf('%s emits %s', $key, class_basename($model));
            }
        }

        sort($unscoped);

        $this->assertSame([], $unscoped, sprintf(
            "These emitted records cannot say where they sit, so NotificationDispatcher
".
            "notifies EVERY ACTIVE USER in the organization about them:
  %s

".
            'Implement App\Support\Automations\CarriesAutomationScope on the model.',
            implode("
  ", $unscoped),
        ));
    }

    /** The grandfather list shrinks and never quietly stops applying. */
    public function test_the_undeclared_list_names_only_modules_that_still_have_not(): void
    {
        $raising = $this->modulesThatRaise();
        $stale = [];

        foreach (self::HAS_NOT_DECLARED_WHAT_IT_EMITS as $key) {
            if (isset($raising[$key]) && $raising[$key]->emits !== []) {
                $stale[] = $key;
            }
        }

        sort($stale);

        $this->assertSame([], $stale, sprintf(
            "These now declare what they emit and no longer need excusing:
  %s

Remove them.",
            implode("
  ", $stale),
        ));
    }
}
