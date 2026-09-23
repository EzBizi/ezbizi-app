<?php

namespace Tests\Feature;

use App\Support\Modules\ModuleDefinition;
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

    private const PANELS = __DIR__.'/../../client/lib/core/dashboard_panels.dart';

    /**
     * Modules whose dashboard panel has no icon of its own YET.
     *
     * Each one renders `Icons.insights_outlined` — a generic chart nobody chose
     * — on a customer's dashboard today. They are listed rather than fixed here
     * because `dashboard_panels.dart` belongs to another session; the list is a
     * ratchet, not an acceptance. Delete a line when its icon lands.
     */
    private const PANELS_WITHOUT_AN_ICON = [
        // Empty, and it should stay that way. All five entries were cleared when
        // their icons landed — every sidebar module now draws one somebody chose.
        // Adding a name here is not a fix, it is a deferral with a comment.
    ];

    /**
     * Every sidebar module draws an icon somebody chose.
     *
     * ── Why this test exists ──────────────────────────────────────
     *
     * `GlanceRegistryTest` already asserts sidebar modules against glance keys as
     * a sorted-set equality, so a module declared WITHOUT a glance turns the gate
     * red — loud, and it has caught two modules already.
     *
     * Nothing asserted the icon map. `dashboardPanelIcon` ends in
     * `?? Icons.insights_outlined`, so a module that registers its glance
     * correctly and omits an icon row goes GREEN and renders a generic chart on
     * every customer's dashboard, permanently. Two failure modes on the same
     * declaration: one loud, one silent, and the silent one is the one that
     * reaches the customer.
     *
     * A fallback that makes a missing entry look deliberate is exactly the shape
     * that needs a test, because nothing else will ever notice.
     */
    public function test_every_sidebar_module_has_a_dashboard_icon_somebody_chose(): void
    {
        $source = file_get_contents(self::PANELS);

        $this->assertNotFalse($source, 'client/lib/core/dashboard_panels.dart is unreadable.');

        // `^`-anchored only, with no trailing `$`. A `$` here would pass on a LF
        // checkout and fail on a CRLF one against a byte-identical file --
        // StartUp.md §8 has the whole story.
        preg_match_all("/^\s*'([a-z_]+)':\s*Icons\./m", $source, $matches);

        $withIcons = $matches[1];

        $this->assertNotEmpty($withIcons, 'Found no panel icons — has the map moved?');

        /*
         * A module with NO GLANCE draws no dashboard panel, so it owes no panel
         * icon - `glanceExemptBecause`, added 2026-09-23 for `a1leads_gbp`.
         *
         * This is not the same as a `PANELS_WITHOUT_AN_ICON` entry and must not
         * be written as one. That list is a deferral: the panel exists, renders
         * a generic chart, and somebody owes it a glyph. This is the absence of
         * a panel. Putting a container in the deferral list would leave a name
         * sitting there forever waiting for an icon nothing will ever draw.
         *
         * The exemption itself is kept honest in GlanceRegistryTest, which
         * refuses it to any module that has records.
         */
        $tabs = collect(app(ModuleRegistry::class)->all())
            ->filter(fn ($m) => $m->rolesGroup === ModuleDefinition::ROLES_TAB
                && $m->sidebar
                && $m->glanceExemptBecause === null
                && $m->key !== 'dashboard')
            ->keys()->all();

        /*
         * A FLOOR ON THE REGISTRY SIDE, not only on the Dart side.
         *
         * `array_diff($tabs, ...)` over an EMPTY $tabs is empty, so this test
         * passes green while checking nothing at all -- and nothing says the
         * scan stopped working. Rename ROLES_TAB, change the `sidebar` flag, or
         * break the filter, and the gate reports success forever.
         *
         * An upper bound alone is not a gate; it needs proof that the
         * measurement ran. Found by reading another session's post-mortem of
         * the identical hole in the colour-literal ratchet (f31d990) and asking
         * whether it described this test too. It did.
         */
        $this->assertGreaterThan(5, count($tabs), 'Found almost no sidebar modules — the registry filter has stopped matching, so this test is no longer checking anything.');

        $missing = array_values(array_diff($tabs, $withIcons, self::PANELS_WITHOUT_AN_ICON));

        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "These sidebar modules have no icon in dashboard_panels.dart:
  %s

".
            "They will render Icons.insights_outlined -- a generic chart -- on every
".
            "customer's dashboard, and nothing else will ever go red about it.

".
            "Add a row to `byModule`, or add the key to PANELS_WITHOUT_AN_ICON with a
".
            "reason if it is genuinely meant to be generic.",
            implode("
  ", $missing),
        ));
    }

    /**
     * The gap list is a ratchet and has to shrink, never quietly stop applying.
     *
     * Without this, a module fixed in `byModule` but left in the list looks
     * exempt forever, and the next reader cannot tell which entries are real.
     */
    public function test_the_icon_gap_list_names_only_modules_that_are_still_missing(): void
    {
        $source = file_get_contents(self::PANELS);
        preg_match_all("/^\s*'([a-z_]+)':\s*Icons\./m", (string) $source, $matches);

        $stale = array_values(array_intersect(self::PANELS_WITHOUT_AN_ICON, $matches[1]));

        sort($stale);

        $this->assertSame([], $stale, sprintf(
            "These are listed as having no icon, but dashboard_panels.dart now gives
".
            "them one:
  %s

Remove them from PANELS_WITHOUT_AN_ICON.",
            implode("
  ", $stale),
        ));
    }
}
