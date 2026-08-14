<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Enforcement of the §5.5 standing rule.
 *
 * The rule — "every new module must expose its triggers and actions to the
 * automation engine; a module that can't participate in automation is not
 * finished" — is the kind that quietly dies around module fifteen, when someone
 * is in a hurry. This test is what stops that: adding a module without
 * automation support fails the build, not a code review someone might skip.
 *
 * Declaring a trigger is now half the rule. A module that declares one without
 * saying what it MEANS puts a wire key in front of the person writing the rule,
 * which is the state this replaced — so the wording is checked here too, and by
 * the same means: the build fails.
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
            "Plan §5.5: every module must declare automation triggers/actions, or state "
            ."automationExemptBecause. Offending module(s): ".implode(', ', array_keys($violations))
        );
    }

    public function test_exempt_modules_state_a_reason(): void
    {
        foreach (app(ModuleRegistry::class)->all() as $key => $module) {
            if ($module->triggers === [] && $module->automationActions === []) {
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

    // ── the wording (§5.5) ───────────────────────────────────────

    /**
     * Checked against the registry as it SHIPS, not against the constructor.
     *
     * AutomationDeclaration refuses most of this at the point of writing, which
     * makes this test look redundant — it is not. It is what still fails if
     * somebody loosens a rule in the value object to get one awkward label
     * through, because it asserts the property everyone actually depends on:
     * nothing in the catalogue is a bare key.
     */
    public function test_every_declared_trigger_and_action_has_words_a_person_can_read(): void
    {
        $checked = 0;

        foreach (app(ModuleRegistry::class)->all() as $key => $module) {
            foreach ([...$module->triggers, ...$module->automationActions] as $declaration) {
                $where = "{$key}: {$declaration->key}";

                $this->assertNotSame('', trim($declaration->label), "No label — {$where}");
                $this->assertNotSame('', trim($declaration->description), "No description — {$where}");

                // The failure this exists for: the key rendered as its own name.
                $this->assertNotSame($declaration->key, $declaration->label, "Label is the key — {$where}");
                $this->assertDoesNotMatchRegularExpression(
                    '/[._]/',
                    $declaration->label,
                    "Label '{$declaration->label}' still looks like a key — {$where}. Write what "
                    .'somebody would call it: "Owner reassigned", not "customer.owner_reassigned".'
                );

                $this->assertStringNotContainsString(
                    $declaration->key,
                    $declaration->description,
                    "The description quotes the wire key — {$where}."
                );

                // A description is a sentence about when it fires. A restated
                // label is a field filled in to satisfy a rule.
                $this->assertNotSame($declaration->label, $declaration->description, $where);
                $this->assertStringEndsWith('.', trim($declaration->description), $where);

                $checked++;
            }
        }

        // A loop over an empty registry passes everything.
        $this->assertGreaterThan(20, $checked, 'The registry has lost its declarations.');
    }

    /**
     * The claim in the wording, checked against the code that would make it true.
     *
     * `isRaised: false` appends "Nothing raises this yet" to what the tenant
     * reads. That sentence is a promise about the source, and promises about the
     * source rot — somebody wires the emission six months from now and the
     * screen goes on saying the rule will never run.
     *
     * So it is checked: a trigger key that appears NOWHERE in application code
     * outside the declaration is certainly never emitted, and one that does
     * appear must not still be claiming otherwise. A mention is not proof of an
     * `emit()` call — the audit log uses some of the same strings — so the
     * second direction is the approximate one. If it ever fails on a key that is
     * only mentioned and not emitted, the fix is to sharpen this, not to flip
     * the flag.
     */
    public function test_a_trigger_that_nothing_raises_says_so_and_one_that_fires_does_not(): void
    {
        $quoted = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->triggers as $trigger) {
                $quoted[] = "'{$trigger->key}'";
            }
        }

        $mentions = $this->mentionedInApplicationCode(array_unique($quoted));

        foreach (app(ModuleRegistry::class)->all() as $key => $module) {
            foreach ($module->triggers as $trigger) {
                $mentioned = $mentions["'{$trigger->key}'"];

                $this->assertSame(
                    $mentioned,
                    $trigger->isRaised,
                    $mentioned
                        ? "'{$trigger->key}' ({$key}) is declared as never raised, but the code "
                          .'mentions it. If it is emitted now, drop isRaised: false — the tenant '
                          .'is still being told the rule will not run.'
                        : "'{$trigger->key}' ({$key}) is declared as raised, but nothing in app/ "
                          .'mentions it. A rule on it would validate, save, switch on and wait '
                          .'forever. Declare isRaised: false until something emits it.'
                );
            }
        }
    }

    /**
     * Which of these strings appear anywhere under app/, outside the files where
     * the keys are declared.
     *
     * ── Why this asks a question instead of returning the source ──
     *
     * It used to concatenate every PHP file under app/ into one string and hand
     * that back to be searched. That worked until it did not: the tree passed
     * two megabytes, PHP grows a string by DOUBLING its buffer, and the
     * reallocation asked for more headroom than was left — so adding an
     * ordinary file to app/ turned this into a fatal that took the whole suite
     * down with it, at a line that had nothing to do with whatever was added.
     * A test that fails on the SIZE of the codebase rather than on anything it
     * claims to check is worse than no test, because the failure points
     * somewhere innocent.
     *
     * Raising the memory limit was the tempting fix and is the wrong one: it
     * moves the cliff without removing it, and the next person meets it with no
     * idea why a test about trigger wording ran out of memory.
     *
     * So nothing is accumulated. One pass, one file held at a time, every needle
     * checked against it — peak memory is the largest single file rather than
     * the whole tree, and the answer is identical. Every needle is asked for in
     * ONE pass rather than one pass per needle, because there are a couple of
     * dozen of them and re-reading the tree for each would trade the memory
     * problem for a time one.
     *
     * @param  list<string>  $needles
     * @return array<string, bool>
     */
    private function mentionedInApplicationCode(array $needles): array
    {
        $declarations = [
            app_path('Support'.DIRECTORY_SEPARATOR.'Modules'),
            app_path('Providers'.DIRECTORY_SEPARATOR.'ModuleServiceProvider.php'),
        ];

        $found = array_fill_keys($needles, false);

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach ($declarations as $excluded) {
                if (str_starts_with($file->getPathname(), $excluded)) {
                    continue 2;
                }
            }

            $contents = $file->getContents();

            foreach ($needles as $needle) {
                if (! $found[$needle] && str_contains($contents, $needle)) {
                    $found[$needle] = true;
                }
            }
        }

        return $found;
    }

    public function test_a_trigger_declared_without_wording_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no label/');

        new TriggerDefinition(key: 'job.created', label: '', description: 'A job was booked.');
    }

    public function test_a_declaration_without_a_description_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no description/');

        // The label says what it is called. Only the description says when it
        // fires, and that is the part people get wrong.
        new TriggerDefinition(key: 'job.created', label: 'Created', description: '');
    }

    public function test_a_label_that_is_really_the_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/looks like a key/');

        new ActionDefinition(
            key: 'customer.update_field',
            label: 'customer.update_field',
            description: 'Sets one field on the customer that triggered the rule.',
        );
    }

    public function test_a_module_may_not_declare_a_trigger_as_a_bare_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TriggerDefinition/');

        // This is what the registry held before, and it is what would reach the
        // catalogue as a trigger with no label at all.
        new ModuleDefinition(key: 'jobs', label: 'Jobs', triggers: ['job.created']);
    }

    public function test_a_trigger_nothing_raises_tells_the_tenant_so(): void
    {
        $trigger = new TriggerDefinition(
            key: 'user.invited',
            label: 'Invited',
            description: 'Somebody was invited to join, before they have accepted.',
            isRaised: false,
        );

        // Shown and marked, not hidden: a hidden trigger is one a tenant cannot
        // build a rule on; a listed one with no warning is one they build a rule
        // on and never hear from again.
        $this->assertStringContainsString(TriggerDefinition::NOT_RAISED_YET, $trigger->description);
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
            key: 'jobs', label: 'Jobs', triggers: [$this->someTrigger('job.created')]
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already registered/');

        // A duplicate would silently merge two modules' permissions.
        $registry->register(new ModuleDefinition(
            key: 'jobs', label: 'Jobs Again', triggers: [$this->someTrigger('job.updated')]
        ));
    }

    public function test_module_keys_must_be_snake_case(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/lower_snake_case/');

        // The key becomes the permission prefix, so 'Customer Jobs' would produce
        // 'Customer Jobs.view' — unmatchable and unsearchable.
        new ModuleDefinition(
            key: 'Customer Jobs', label: 'x', triggers: [$this->someTrigger('job.created')]
        );
    }

    /** A well-formed trigger, for tests about something other than the wording. */
    private function someTrigger(string $key): TriggerDefinition
    {
        return new TriggerDefinition(
            key: $key,
            label: 'Something happened',
            description: 'Stands in for a real trigger in this test.',
        );
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
