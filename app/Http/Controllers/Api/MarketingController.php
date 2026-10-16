<?php

namespace App\Http\Controllers\Api;

use App\Support\Access\Refusal;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marketing — Newsletter (not built) and Social Media Management (started).
 *
 * ── What an endpoint is FOR, on a module that does nothing ───────
 *
 * Newsletter's screen has to say two things: what it will be, and what it is
 * waiting for. Neither of those is a fact the server knows better than the
 * client does, so both of them are written on the screen, exactly the way the
 * HR dashboard's "Expiring certifications" panel writes its own.
 *
 * The third thing is different, and it is the only reason this controller
 * exists. Marketing has already declared its automation surface — the events
 * Newsletter will raise and the things a rule will be able to do with them —
 * and somebody opening the tab should be able to see it rather than take it on
 * faith. Those declarations live in ModuleServiceProvider, and a copy of them
 * in Dart would be the second dictionary AutomationDeclaration's docblock is
 * about: the client is not expected to know what `newsletter` events mean,
 * because the module that raises them is the only place that can say.
 *
 * So this reads the registry and publishes it. Nothing here names a trigger, an
 * action or a sentence of wording — add one to the module and it appears on the
 * screen; take one away and it leaves.
 *
 * ── Which is also why the keys are not written here ──────────────
 *
 * `ModuleRegistryTest::test_a_trigger_that_nothing_raises_says_so_and_one_that_
 * fires_does_not` scans every PHP file under app/ outside the declarations and
 * fails a trigger that claims "nothing raises this yet" while the source
 * mentions it. Newsletter's triggers all make that claim today. A quoted key in
 * this file would look exactly like an emission to that scan and break the
 * build — correctly, because a mention here would be a second home for
 * something the registry owns.
 *
 * ── The gate ─────────────────────────────────────────────────────
 *
 * `marketing.view`, and nothing about the module being new exempts it (§4: the
 * tab IS the view permission). There is no model to hang a policy off, so the
 * check is here — and it refuses in a sentence rather than with a bare 403,
 * which names no permission and no remedy (R8). The wording comes from Refusal
 * like every other refusal in the app, so somebody told they cannot open
 * Marketing is told the same way they are told they cannot open Customers.
 */
class MarketingController extends Controller
{
    /**
     * The module whose surface this publishes.
     *
     * It was `marketing` until 2026-09-04, when Newsletter became a module of
     * its own so that `send` could be granted separately from `edit`. The
     * events and the action moved with it, so reading `marketing` here would
     * now answer with the SOCIAL pair — a screen about newsletters listing the
     * triggers for social posts, which is worse than listing nothing.
     */
    private const MODULE = 'newsletter';

    /** What somebody needs to reach this at all. */
    private const GATE = 'newsletter.view';

    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * GET /api/v1/marketing/newsletter
     *
     * The automation surface Marketing declares, in the words the module wrote.
     *
     * `raised` is published per trigger, and it carries the fact the screen
     * needs: a rule built on one of these would save, switch on and never run.
     * The description already ends in a sentence saying so — TriggerDefinition
     * appends it — but a sentence is for reading and a flag is for drawing a
     * mark beside the row, and a client picking that apart from prose would be
     * parsing English to find out whether something works.
     *
     * The catalogue at /automations/catalogue does not publish this flag; it
     * relies on the appended sentence alone. That is a difference between two
     * endpoints and worth stating rather than leaving to be discovered: this one
     * is read by a screen whose whole subject is "declared and not yet running",
     * so the flag is the part it is about.
     */
    public function newsletter(Request $request): array
    {
        abort_unless(
            $request->user()->hasPermission(self::GATE),
            Response::HTTP_FORBIDDEN,
            Refusal::permission('open Newsletter')->message(),
        );

        /*
         * Always present by the time this line runs, and there is no 404 guard
         * below it because that guard was unreachable.
         *
         * `hasPermission('marketing.view')` asks ModuleAvailability, which asks
         * this same registry — and the registry is a singleton. So a build with
         * no Marketing definition has no `marketing.view` either, and the
         * refusal above fires first. Probed by deleting the definition: the
         * endpoint answers 403, never 404, so the sentence "Marketing is not
         * part of this build" could not be reached by any request.
         *
         * Removed rather than kept as insurance. A branch nothing can enter is
         * a branch nobody maintains, and its message described a state the
         * permission check has already ruled out.
         */
        $module = $this->registry->get(self::MODULE);

        return [
            'data' => [
                'module' => ['key' => $module->key, 'label' => $module->label],

                'triggers' => array_map(fn (TriggerDefinition $t) => [
                    'key'         => $t->key,
                    'label'       => $t->label,
                    'description' => $t->description,
                    'raised'      => $t->isRaised,
                ], $module->triggers),

                /*
                 * No `implemented` beside these, deliberately.
                 *
                 * Whether the engine can run an action is decided by the generic
                 * set AutomationsController holds, and repeating that list here
                 * would be a copy that goes stale the day the engine gains one.
                 * The screen does not need it either: an action only ever runs
                 * under a trigger, so `raised` above already answers "could a
                 * rule using this do anything" for every action beneath it.
                 */
                'actions' => array_map(fn (ActionDefinition $a) => [
                    'key'         => $a->key,
                    'label'       => $a->label,
                    'description' => $a->description,
                ], $module->automationActions),
            ],
        ];
    }
}
