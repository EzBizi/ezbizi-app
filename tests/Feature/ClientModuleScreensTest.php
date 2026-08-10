<?php

namespace Tests\Feature;

use App\Support\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * Every module the client can DRAW must be one the server DECLARES.
 *
 * ── The regression this exists for ───────────────────────────────
 *
 * The sidebar used to be a list compiled into Dart. It is now built from the
 * `modules` block on /me, which is the right answer — one source for "which
 * tabs exist", and both gates (§13 entitlement, §4 permission) applied to every
 * one of them.
 *
 * But the two halves were written by different people on the same afternoon and
 * nothing joined them up. The registry declared nine modules and had no
 * `dashboard` and no `data`; the client had screens for five. The sidebar is the
 * intersection, so **Dashboard and Import & export silently disappeared for
 * everybody, including the Administrator**, and DashboardScreen — all of §18
 * Phase 4 — became unreachable code.
 *
 * It shipped green. `client/test/module_navigation_test.dart` built its own /me
 * payload with a hand-typed `dashboard` module in it, so every navigation test
 * asserted against a server that does not exist. That is the same failure
 * `Tests\Concerns\BuildsTenants` was written to prevent — its docblock says a
 * test starting from an impossible state proves something about a system that
 * does not exist — and the client fixtures had no equivalent discipline.
 *
 * ── Why the check lives here, in PHP ─────────────────────────────
 *
 * This is the only place both facts are visible at once. A Dart test cannot see
 * ModuleRegistry, and a Dart fixture asserting against a hand-copied list is the
 * thing that failed. Reading the Dart file from PHP is unusual, and it is worth
 * it: it is a real coupling between the two halves of the product, and the
 * alternative is finding out from a screenshot.
 */
class ClientModuleScreensTest extends TestCase
{
    private const SCREENS = __DIR__.'/../../client/lib/core/module_screens.dart';

    /** @return string[] the module keys the client has a screen for */
    private function clientKeys(): array
    {
        $source = file_get_contents(self::SCREENS);

        $this->assertNotFalse($source, 'client/lib/core/module_screens.dart is unreadable.');

        // The map's keys, which are the only quoted strings at the start of a
        // line in that literal. Deliberately narrow: a looser pattern would
        // pick up labels and icon names and turn a real failure into noise.
        preg_match_all("/^\s*'([a-z_]+)':\s*ModuleScreen\(/m", $source, $matches);

        return $matches[1];
    }

    public function test_the_client_draws_no_module_the_server_does_not_declare(): void
    {
        $declared = array_keys(app(ModuleRegistry::class)->all());
        $drawn    = $this->clientKeys();

        $this->assertNotEmpty($drawn, 'Found no module screens — has the file moved?');

        $undeclared = array_values(array_diff($drawn, $declared));

        $this->assertSame([], $undeclared, sprintf(
            "The client can draw these, and the server declares no module for them:\n  %s\n\n".
            "The sidebar renders declared modules only, so each of these is a screen nobody\n".
            "can reach. Either add a ModuleDefinition in ModuleServiceProvider (with a `view`\n".
            "action, which is the tab permission), or delete the screen.",
            implode(', ', $undeclared),
        ));
    }

    public function test_every_module_with_a_screen_can_be_granted(): void
    {
        $registry = app(ModuleRegistry::class);
        $missing  = [];

        foreach ($this->clientKeys() as $key) {
            $module = $registry->all()[$key] ?? null;

            if ($module === null) {
                continue;   // the test above owns that failure
            }

            if (! in_array("{$key}.view", $module->permissionKeys(), strict: true)) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These modules have a screen but no `view` permission, so no role could ever be\n".
            "granted the tab: %s",
            implode(', ', $missing),
        ));
    }
}
