<?php

namespace App\Support\Privacy;

/**
 * A table that says how long its rows stay, and what makes that true (§10).
 *
 * ── Why it is separate from HoldsPersonalData ────────────────────
 *
 * Retention was declared on the personal-data interface, which meant only tables
 * holding personal data could state one. The two tables growing fastest in this
 * system — `automation_events` and `automation_runs` — hold no personal data of
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
 * every implementor returns a policy, and two tests hold the line from opposite
 * ends. The gap they close is not tables kept too long — it is tables nobody
 * ever decided about.
 *
 *   - test_every_model_that_accumulates_rows_has_decided_about_retention walks
 *     app/Models and fails for a model that grows without implementing this.
 *
 *   - test_every_table_in_the_schema_has_been_decided_about walks the SCHEMA,
 *     because the first one cannot see what it is not looking at. Discovery by
 *     model is what the inventory, the purge and the sweep all do, so a table
 *     written by a bare `DB::table()` was invisible to every one of them at
 *     once — `password_resets` was, for a while, and nothing could report it,
 *     because a table missing from a derived walk does not fail, it is simply
 *     never mentioned.
 */
interface StatesRetention
{
    public static function retention(): RetentionPolicy;
}
