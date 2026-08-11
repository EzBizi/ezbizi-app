<?php

namespace App\Support\Privacy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A table that holds personal data, and says which of it, why, and what erasing
 * it means (§9).
 *
 * §9 asks for a data inventory — "what personal data, in which table, why" —
 * and gives the reason it cannot wait: "Impossible to reconstruct later; trivial
 * to maintain as you go." The maintenance cost is this interface. A model that
 * gains a personal-data column and does not update its answer has told a lie
 * that nobody will catch, so the answer lives ON the model, next to the casts
 * and the fillable list, where somebody adding a column is already looking.
 *
 * ── Why models are discovered rather than listed ─────────────────
 *
 * There is no register of implementors anywhere. DataInventory finds them by
 * looking at what is in app/Models, so a new model that implements this appears
 * in the inventory with nothing else edited — and, more to the point, a model
 * that implements it cannot be left OUT of the inventory by somebody forgetting
 * a second file. A hand-kept list is exactly the artefact §9 warns is
 * impossible to reconstruct.
 *
 * ── What belongs here, and what does not ─────────────────────────
 *
 * Personal data is data about a PERSON, which includes people who are not users
 * of this app: a customer's address, the name on an invitation nobody accepted,
 * the IP address a session came from. §9 is explicit that it also hides in
 * places nobody thinks of — "audit logs, crash reports, uploaded files, search
 * indexes, caches, automation payloads, notification history, exports and
 * backups" — and an inventory that lists only the obvious three tables is worse
 * than none, because it will be believed.
 *
 * ── The inventory is now also the purge ──────────────────────────
 *
 * §9's second build-now mechanism is "purge personal data — anonymise in place",
 * and it "must touch every table holding personal data" — which is the sentence
 * this interface already answers. So the purge is derived from these
 * declarations rather than keeping a parallel list: each PersonalDataEntry
 * carries an Erasure saying what a purge does to it, and `personalDataAbout()`
 * says which rows are about a given person. See App\Support\Privacy\Anonymiser.
 *
 * The two halves are split that way because they are known by different things.
 * WHICH COLUMNS is knowledge the entry already carries and must not be written
 * down twice. WHICH ROWS is a join — through `tokenable`, through an onboarding,
 * through a morph — and nothing but the model can state it, which is why it is a
 * method here and not something the Anonymiser guesses from a column name.
 */
interface HoldsPersonalData extends StatesRetention
{
    /**
     * Why this table exists at all, in one sentence a non-engineer can read.
     *
     * This appears beside the table name in the answer to "what do you hold
     * about me", so it is written for that reader and not for a colleague.
     */
    public static function personalDataPurpose(): string;

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array;

    /**
     * The rows here that are about this person, or null if none ever are.
     *
     * ── Why the model answers rather than the purge ──────────────
     *
     * "Everything about Marta" is a different query in every table: her own row
     * in `users`, rows whose `tokenable` morphs to her in
     * `personal_access_tokens`, rows two joins away through an onboarding in
     * `onboarding_answers`, and — in `audit_events` — rows where she is the
     * ACTOR and rows where she is the TARGET, which are stored as a polymorphic
     * pair rather than a column. A purge that tried to infer those would infer
     * some of them wrongly, and the failure mode is silent: it erases less than
     * it reports.
     *
     * NULL rather than an empty query is deliberate. "This table never holds
     * anything about a customer" and "it does, and this one has none" are
     * different answers, and the purge report prints them differently — the
     * second is a fact about this person, the first is a fact about the schema.
     *
     * ── Scope ────────────────────────────────────────────────────
     *
     * The subject is the person being erased. Today that is a User or a
     * Customer, the two kinds of person this system holds records about, and an
     * implementation is expected to return null for a kind it has nothing to do
     * with rather than to throw — a new kind of subject should not break every
     * existing table.
     *
     * Soft-deleted rows must be INCLUDED. A soft-deleted customer is retained
     * personal data, which is §9's whole point about the tension, and a purge
     * that skipped them would leave the exact rows somebody is asking about.
     *
     * @return Builder<Model>|null
     */
    public static function personalDataAbout(Model $subject): ?Builder;
}
