<?php

namespace Tests\Feature\Automations;

use App\Support\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * Every trigger says what its event is about (builder audit, 2026-10-22):
 * the builder hides steps that cannot work on it, and a step that names a
 * record is refused under a trigger about another. A new trigger in a
 * module without a record must declare `subjects:` (or [] for nothing).
 */
class AutomationTriggerSubjectsTest extends TestCase
{
    public function test_every_trigger_declares_what_it_is_about(): void
    {
        $undeclared = [];
        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->triggers as $trigger) {
                if ($trigger->subjects === null && $module->subject === null) {
                    $undeclared[] = $trigger->key;
                }
                foreach ($trigger->subjects ?? [] as $class) {
                    $this->assertTrue(class_exists($class), "{$trigger->key} names {$class}, which does not exist.");
                }
            }
        }

        $this->assertSame([], $undeclared, 'These triggers say nothing about what their event is about. Add subjects: [...] (or [] for nothing) where each is declared.');
    }
}
