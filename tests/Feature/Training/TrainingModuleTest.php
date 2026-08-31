<?php

namespace Tests\Feature\Training;

use App\Http\Controllers\Api\AutomationsController;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\ModuleRegistry;
use ReflectionClass;
use Tests\TestCase;

/**
 * What Training declares about itself (plan §5.5, spec §12).
 *
 * These are the module's PUBLISHED names. A trigger key is what a customer's
 * automation rule stores; a permission key is what a role grants. Renaming one
 * later orphans every rule and every role that used it, silently — so the names
 * are pinned here rather than left to whoever edits the block next.
 */
class TrainingModuleTest extends TestCase
{
    public function test_training_declares_four_actions_and_its_five_triggers(): void
    {
        $module = app(ModuleRegistry::class)->get('training');

        $this->assertNotNull($module, 'No module is registered under the key training.');

        $this->assertSame(['view', 'monitor', 'manage', 'assign'], $module->actions);

        $triggers = collect($module->triggers)->pluck('key')->all();
        $this->assertEqualsCanonicalizing([
            'training.assigned', 'training.completed', 'training.passed',
            'training.failed', 'training.expired',
        ], $triggers);
    }

    public function test_the_four_permission_keys_are_reachable(): void
    {
        // Derived from the module, not registered separately: PermissionCatalogue
        // asks the registry and nothing else, so these four exist the moment the
        // block does.
        $keys = app(ModuleRegistry::class)->allPermissionKeys();

        foreach (['training.view', 'training.monitor', 'training.manage', 'training.assign'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_the_assign_action_is_actually_implemented_not_just_declared(): void
    {
        // AutomationsController holds a second, private const. An action absent
        // from it publishes as implemented:false and is refused at run time —
        // silently, with nothing red anywhere.
        $reflected = new ReflectionClass(AutomationsController::class);

        $this->assertContains(
            'training.assign',
            $reflected->getConstant('IMPLEMENTED_ACTIONS'),
            'Declaring the action in ModuleServiceProvider is only half of it.'
        );
    }

    /**
     * The half of that the literal assertion above cannot see.
     *
     * The catalogue does not compare the action's KEY against that const — it
     * compares `ActionDefinition::type()`, which defaults to everything after
     * the first dot. Left to default, this action's type is the bare word
     * `assign`, the const entry never matches, and the row publishes as "not
     * built yet" with the const looking correct in the diff. time_clock spells
     * its execution type out for exactly this reason.
     */
    public function test_the_declared_action_reduces_to_the_type_the_engine_was_told_about(): void
    {
        $module = app(ModuleRegistry::class)->get('training');
        $this->assertNotNull($module);

        $action = collect($module->automationActions)
            ->first(fn (ActionDefinition $a) => $a->key === 'training.assign');

        $this->assertNotNull($action, 'Training declares no assign action.');

        $implemented = (new ReflectionClass(AutomationsController::class))
            ->getConstant('IMPLEMENTED_ACTIONS');

        $this->assertContains(
            $action->type(),
            $implemented,
            "The action reduces to '{$action->type()}', which is not the vocabulary the "
            .'save path accepts, so the catalogue publishes it as not built yet.'
        );
    }

    /**
     * Spec §12, settled: Training names no automation subject.
     *
     * Its five events are about three different records — an assignment, a
     * guide’s progress and a test attempt — and a subject is one class for the
     * whole module. Naming any one of the three would make the field picker
     * wrong about the other two, which is Human Resources’ answer for the same
     * reason. Pinned so that a later edit adding one has to argue with a test.
     */
    public function test_training_names_no_single_record_because_its_events_are_about_three(): void
    {
        $module = app(ModuleRegistry::class)->get('training');

        $this->assertNotNull($module, 'No module is registered under the key training.');
        $this->assertNull($module->subject);
    }
}
