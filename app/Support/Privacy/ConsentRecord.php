<?php

namespace App\Support\Privacy;

/**
 * One thing somebody was asked, and agreed to, at a stated moment (§9).
 *
 * ── What was decided before this was built ───────────────────────
 *
 * The obvious build is a `consents` table with `user_id`, `purpose`, `granted`,
 * `granted_at`, and a screen of toggles. That is the wrong shape for this
 * product, and building it would have been worse than building nothing, so the
 * reasoning is here rather than in a commit message.
 *
 * Almost nothing this software does runs on consent. An employee does not
 * consent to their employer holding their bank details — the employment contract
 * requires it, and a "consent" that can be withdrawn without the payroll
 * stopping is not a consent, it is a checkbox. A customer does not consent to
 * being invoiced. A generic consent table invites exactly that mislabelling:
 * every processing purpose acquires a row, each row implies withdrawal is
 * possible, and the first person to withdraw one discovers that the software
 * carries on regardless. §16 defers the LEGAL question of which basis applies to
 * what; what must not be deferred is avoiding a mechanism that answers it wrongly
 * in advance.
 *
 * ── So: consent is what was actually asked ───────────────────────
 *
 * A consent record here is four things and it is not stored twice:
 *
 *   who        the person who agreed
 *   what       the EXACT words they were shown, verbatim, not a purpose code
 *   when       the moment, timed separately from whatever it accompanied
 *   evidence   the row that proves it, still in the table that owns it
 *
 * The verbatim statement is the load-bearing part and it is the same argument
 * `contract_signatures` already made in its migration: "a
 * `consented_to_electronic BOOLEAN` records that somebody ticked something; it
 * cannot say what." A consent stored as a purpose code has the same defect — the
 * wording changes, the code does not, and every historical row silently starts
 * claiming agreement to text its signer never read.
 *
 * ── Derived, never a second copy ─────────────────────────────────
 *
 * There is one real consent in this product today: ESIGN's agreement to transact
 * electronically, taken during signing, stored as the sentence displayed and
 * timed with its own column. It is already correct. Copying it into a consents
 * table would create two homes for one fact (R6) with no way to tell which was
 * authoritative — so the ledger READS it where it lives, and a second kind of
 * consent added later implements RecordsConsent on whatever table owns its
 * evidence.
 */
final class ConsentRecord
{
    public function __construct(
        /** A short stable key for the KIND of consent — not the wording. */
        public readonly string $kind,
        /** What that kind is, for somebody who is not a lawyer. */
        public readonly string $label,
        /** The exact words shown, as they were shown. Never re-composed. */
        public readonly string $statement,
        public readonly ?string $givenAt,
        /** What they did that constituted agreeing — the affirmative act. */
        public readonly string $act,
        /** Where the proof is: the table and row that owns this. */
        public readonly string $evidenceType,
        public readonly string $evidenceId,
        /**
         * Whether this consent can be withdrawn, and what happens if it is.
         *
         * Stated rather than assumed, because the honest answer here is "no" and
         * a ledger that implied otherwise would be making a promise the product
         * cannot keep. Withdrawing consent to have signed something electronically
         * does not unsign it — ESIGN's consent governs the MEDIUM, and the
         * agreement it was given for has already been reached.
         */
        public readonly string $withdrawal,
    ) {}

    public function toArray(): array
    {
        return [
            'kind'       => $this->kind,
            'label'      => $this->label,
            'statement'  => $this->statement,
            'given_at'   => $this->givenAt,
            'act'        => $this->act,
            'evidence'   => ['type' => $this->evidenceType, 'id' => $this->evidenceId],
            'withdrawal' => $this->withdrawal,
        ];
    }
}
