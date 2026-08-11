<?php

namespace App\Support\Privacy;

/**
 * A table that holds personal data, and says which of it and why (§9).
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
 */
interface HoldsPersonalData
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
     * How long it stays, in the words of whatever decides it.
     *
     * A sentence rather than a number of days, because the honest answers are
     * not all numbers: some of this is kept for as long as the record it belongs
     * to, some has a retention window set in §10, and some — the audit trail —
     * is deliberately append-only. A column of integers would force each of
     * those into a shape that misrepresents it.
     */
    public static function personalDataRetention(): string;
}
