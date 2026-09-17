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
 * That shape is not invented for this. `monitoring.cameras.view`,
 * `monitoring.screenshots.view` and `fleet.specs.view` have been dotted actions
 * since those modules shipped — this names the pattern and gives it a LABEL,
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

        if (str_contains($key, '.')) {
            throw new InvalidArgumentException(
                "Tab key '{$key}' contains a dot. The action is built as '{$key}.view', so a ".
                'dotted key would produce a three-part action that moduleOf() and '.
                'materialise() would split in the wrong place.'
            );
        }
    }

    /** The action this tab contributes to its module — `hours` → `hours.view`. */
    public function action(): string
    {
        return "{$this->key}.view";
    }
}
