<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Enforcement of the §5.5 standing rule.
 *
 * The rule — "every new module must expose its triggers and actions to the
 * workflow engine; a module that can't participate in automation is not
 * finished" — is the kind that quietly dies around module fifteen, when someone
 * is in a hurry. This test is what stops that: adding a module without
 * automation support fails the build, not a code review someone might skip.
 */
class ModuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_module_satisfies_the_automation_rule(): void
    {
        $violations = app(ModuleRegistry::class)->violatingAutomationRule();

        $this->assertSame(
            [],
            array_keys($violations),
            "Plan §5.5: every module must declare workflow triggers/actions, or state "
            ."automationExemptBecause. Offending module(s): ".implode(', ', array_keys($violations))
        );
    }

    public function test_exempt_modules_state_a_reason(): void
    {
        foreach (app(ModuleRegistry::class)->all() as $key => $module) {
            if ($module->triggers === [] && $module->workflowActions === []) {
                $this->assertNotEmpty(
                    $module->automationExemptBecause,
                    "Module '{$key}' is outside automation but gives no reason. "
                    .'An unexplained exemption is indistinguishable from an oversight.'
                );
            }
        }

        // Keeps the loop honest if every module later gains triggers.
        $this->assertTrue(true);
    }

    public function test_permission_keys_are_module_prefixed(): void
    {
        foreach (app(ModuleRegistry::class)->all() as $key => $module) {
            foreach ($module->permissionKeys() as $permissionKey) {
                $this->assertStringStartsWith(
                    "{$key}.",
                    $permissionKey,
                    'Permission keys must be module-prefixed, or two modules can collide.'
                );
            }
        }
    }

    public function test_duplicate_module_keys_are_rejected(): void
    {
        $registry = new ModuleRegistry;
        $registry->register(new ModuleDefinition(
            key: 'jobs', label: 'Jobs', triggers: ['job.created']
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already registered/');

        // A duplicate would silently merge two modules' permissions.
        $registry->register(new ModuleDefinition(
            key: 'jobs', label: 'Jobs Again', triggers: ['job.updated']
        ));
    }

    public function test_module_keys_must_be_snake_case(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/lower_snake_case/');

        // The key becomes the permission prefix, so 'Customer Jobs' would produce
        // 'Customer Jobs.view' — unmatchable and unsearchable.
        new ModuleDefinition(key: 'Customer Jobs', label: 'x', triggers: ['a']);
    }

    // ── the sync command ─────────────────────────────────────────

    public function test_sync_creates_a_permission_for_every_module_action(): void
    {
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $expected = app(ModuleRegistry::class)->allPermissionKeys();
        $actual   = Permission::pluck('key')->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    public function test_sync_is_idempotent(): void
    {
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $first = Permission::count();

        // Runs on every deploy — a second run must not duplicate or churn rows.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $this->assertSame($first, Permission::count());
    }

    public function test_sync_does_not_delete_permissions_missing_from_the_registry(): void
    {
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $orphan = Permission::create([
            'key' => 'legacy.view', 'module' => 'legacy', 'action' => 'view', 'label' => 'Legacy',
        ]);

        $this->artisan('ezbizi:sync-permissions --prune')->assertSuccessful();

        // A role may reference it. Silently dropping it would quietly change
        // access with no trace — removal is a deliberate migration.
        $this->assertNotNull($orphan->fresh());
    }
}
