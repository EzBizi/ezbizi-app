<?php

namespace App\Support\Privacy;

/**
 * A table that says how long its rows stay, and what makes that true (§10).
 *
 * ── Why it is separate from HoldsPersonalData ────────────────────
 *
 * Retention was declared on the personal-data interface, which meant only tables
 * holding personal data could state one. The two tables growing fastest in this
 * system — `workflow_events` and `workflow_runs` — hold no personal data of
 * their own and so had no way to say anything at all. They were not exempt from
 * retention; they were invisible to it. §10 is about what accumulates, and what
 * accumulates is not only what identifies somebody.
 *
 * So `HoldsPersonalData` EXTENDS this. A model holding personal data must still
 * state retention — it inherits the requirement rather than escaping it — and a
 * model that merely grows can state one without pretending to hold personal data
 * it does not hold.
 *
 * ── Discovered, not listed ───────────────────────────────────────
 *
 * Same rule as the inventory, for the same reason: `ezbizi:expire-data` finds
 * implementors by looking in app/Models, so a new table appears in the sweep with
 * nothing else edited. A registry of tables-to-expire is the artefact that goes
 * stale the first time somebody adds a table and does not think about it — and
 * the failure is invisible, because a table missing from a cleanup list simply
 * grows.
 *
 * ── What "no policy" is allowed to mean ──────────────────────────
 *
 * `RetentionPolicy::forever()` exists precisely so that "we keep this forever"
 * is sayable, and it demands a reason. What is NOT sayable is nothing at all:
 * every implementor returns a policy, and
 * RetentionTest::test_every_model_that_accumulates_rows_has_decided_about_retention
 * fails the build for any model that grows and does not implement this. The gap this closes is
 * not tables kept too long — it is tables nobody ever decided about.
 */
interface StatesRetention
{
    public static function retention(): RetentionPolicy;
}
