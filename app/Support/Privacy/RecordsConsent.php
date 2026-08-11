<?php

namespace App\Support\Privacy;

use App\Models\User;

/**
 * A table whose rows carry evidence that somebody agreed to something (§9).
 *
 * ── The rule this interface enforces ─────────────────────────────
 *
 * Consent is recorded WHERE IT WAS TAKEN, and this is how it becomes readable
 * from one place. A model implements it when its rows already prove an
 * agreement; nothing is copied, nothing is duplicated, and the ledger is
 * assembled by asking rather than by maintaining.
 *
 * The rule that matters, and the reason this is an interface rather than a
 * table: a new consent must be recorded as THE WORDS THAT WERE SHOWN, timed, on
 * the row that evidences the act. A boolean and a purpose code are not a consent
 * record — see ConsentRecord, and see the migration comment on
 * `contract_signatures` that this whole design is taken from.
 *
 * ── What implementing this is a claim about ──────────────────────
 *
 * That the row is evidence a specific human being agreed to specific stated
 * words at a specific moment. Not that a setting is on. Not that a default was
 * accepted. If the honest description of a feature is "we do this and the person
 * can turn it off", that is a preference and belongs in §2.1 category 6, not
 * here — putting it here would mislabel processing that continues regardless as
 * something the person chose.
 *
 * ── Only one implementor today, and that is the finding ──────────
 *
 * `ContractSignature`, for ESIGN's consent to transact electronically. That the
 * list is one long is not an omission to be padded out; it is the accurate
 * answer to "what does this product ask consent for", and it is worth being able
 * to state.
 */
interface RecordsConsent
{
    /**
     * Every consent this table holds evidence of, for this person.
     *
     * @return ConsentRecord[]
     */
    public static function consentsGivenBy(User $subject): array;
}
