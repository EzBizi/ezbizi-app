<?php

namespace App\Support\Modules;

use InvalidArgumentException;

/**
 * One tab inside a module, and whether a role may open it (Jose, 2026-09-17).
 *
 * ── The rule this replaces ───────────────────────────────────────
 *
 * Until today the module registry said, in as many words: "One module means one
 * set of actions across all five tabs (§4: the tab IS the view permission)."
 * That was a deliberate decision and it is the one being overturned, by the
 * person whose decision it was:
 *
 *   "right now there is no way for me to do that, i can only allow access to
 *    the whole tab ... i should be able to grant access by sub tab as well, so
 *    for example i want them to see their work hours, but not the onboarding
 *    information"
 *
 * A dispatcher who needs to see their own hours currently has to be given the
 * whole of Human Resources, which is everybody's contracts, everybody's
 * onboarding paperwork and the invitation list. The grant that exists is far
 * larger than the one anybody wants to make.
 *
 * ── It is an ACTION, not a new kind of thing ─────────────────────
 *
 * A tab's key becomes the action `{tab}.view`, so Work hours is `hr.hours.view`
 * and the whole of the rest of the system needs no teaching: `moduleOf()` still
 * answers `hr`, `withImpliedView()` still adds `hr.view` beside it,
 * `materialise()` still finds it in `actions`, and `ezbizi:sync-permissions`
 * still writes the row.
 *
 * That shape is not invented for this. `monitoring.cameras.view` and
 * `monitoring.screenshots.view` have been dotted actions since that module
 * shipped — this names the pattern and gives it a LABEL,
 * which is the part they lack: the roles editor currently draws
 * `security.reset` as the words "Security.reset" on a checkbox.
 *
 * ── Visibility only, deliberately ────────────────────────────────
 *
 * A tab grant says which tabs open, not what may be done inside them. `hr.edit`
 * still means edit wherever HR lets you edit. Per-tab CREATE and EDIT is a
 * second, larger question — it multiplies every module's action list by its tab
 * count — and Jose asked for the first one: "i want them to see their work
 * hours, but not the onboarding information". Seeing is the ask.
 */
final class TabDefinition
{
    public function __construct(
        /** Short key, unique within the module. `hours` → `hr.hours.view`. */
        public readonly string $key,
        /**
         * What the tab is called ON SCREEN, and it must be the same word.
         *
         * The label here is what an administrator reads in the roles editor
         * while deciding. If it says "Work hours" and the tab in Human
         * Resources says "Time clock", they are ticking a box for something
         * they cannot then find — and there is no error, because both are
         * working correctly.
         */
        public readonly string $label,
        /**
         * An action this module ALREADY declares, when the tab is one.
         *
         * Null mints `{key}.view`, which is right for a tab that had no grant
         * of its own. Naming one is for a module that split by tab before this
         * mechanism existed, under its own vocabulary.
         *
         * Training is the case that asked for it, and the argument is theirs:
         * their three tabs are already three grants — `training.view` is the
         * learner's Learn pane, `training.monitor` is everybody's results, and
         * `training.manage` is the authoring surface. Minting
         * `training.dashboard.view` beside `monitor` would put TWO NAMES ON ONE
         * AUTHORITY, and an administrator could then grant the tab without the
         * read: a Dashboard that opens and refuses every request inside it. An
         * affordance without its gate, in a product where that state is
         * currently unreachable, and nothing would report it.
         *
         * The reverse — letting the tab key become the only gate — is worse.
         * `monitor` is a disclosure boundary rather than a visibility toggle: it
         * decides who may read named people's results. This class says a tab
         * grant is "visibility only, deliberately", which is the right weight
         * for Work Hours and the wrong weight for that.
         *
         * So what was actually missing for them was the LABEL. The catalogue
         * renders their three as "View training" / "Monitor training" / "Manage
         * training", and an administrator hunting for the box that opens the
         * Dashboard has to infer which one it is. Not a bare key on a checkbox
         * — a correct name that is not the tab's name.
         */
        public readonly ?string $action = null,
        /**
         * The tab this one is drawn INSIDE (Jose, 2026-11-09: "marketing does
         * not show the social media management tab, instead it just shows the
         * sub tabs under it"). Another tab's key in this module, or, for a
         * group with no grant of its own (Leads › Calls), the group's label.
         */
        public readonly ?string $within = null,
    ) {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A tab needs a key.');
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException(
                "Tab '{$key}' has no label. The roles editor prints this word on a checkbox, ".
                'and a bare key there is how `security.reset` came to be shown to '.
                'administrators as "Security.reset".'
            );
        }

        if ($action !== null && trim($action) === '') {
            throw new InvalidArgumentException(
                "Tab '{$key}' names an empty action. Leave it out to mint '{$key}.view'; "
                .'an empty string is neither.'
            );
        }

        if (str_contains($key, '.')) {
            throw new InvalidArgumentException(
                "Tab key '{$key}' contains a dot. The action is built as '{$key}.view', so a ".
                'dotted key would produce a three-part action that moduleOf() and '.
                'materialise() would split in the wrong place.'
            );
        }
    }

    /**
     * The action behind this tab — minted as `{key}.view`, or the named one.
     *
     * One method rather than a nullable field the callers each interpret,
     * because four places ask this question (the registry's merge, the
     * catalogue's `tab` flag, its label lookup, and the migration) and a
     * fifth will be added by somebody who does not know about the third.
     */
    public function action(): string
    {
        return $this->action ?? "{$this->key}.view";
    }

    /** True when this names an action the module already had. */
    public function namesExistingAction(): bool
    {
        return $this->action !== null;
    }
}
