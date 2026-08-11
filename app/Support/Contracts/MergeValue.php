<?php

namespace App\Support\Contracts;

/**
 * One thing a merge tag can resolve to: what it is called, and what it says.
 *
 * Two strings rather than one, because a merge value has to be renderable when
 * it is BLANK, and a blank value cannot name itself. `{{wage}}` with no answer
 * behind it renders as "[Hourly wage not provided]" — see MergeTags::render for
 * why that is the decision and not an empty gap — and the label is the only
 * thing that makes that sentence say which term is missing.
 *
 * It carries no key. The key is the array index of the map these live in, and a
 * value object that repeated it would give two places to disagree about which
 * tag a value belongs to.
 *
 * ── `value` is already in the tenant's own words ─────────────────
 *
 * It is an answer, a name off a profile, or a fact about the business. It is
 * NEVER markup, and it is not escaped here: escaping is the renderer's, done at
 * the moment of substitution, because a value escaped this early would be
 * escaped twice by the time it reached a document.
 */
final class MergeValue
{
    public function __construct(
        /** What a person calls this — the field's label, or the fact's. */
        public readonly string $label,
        /**
         * What it says, or null when there is nothing to say.
         *
         * Null and '' are the same state and callers must send null: an empty
         * string is an answer of "nothing", which is not the same as an
         * unanswered question, and the two rendering as different things is how
         * a document ends up with a gap that reads as a term.
         */
        public readonly ?string $value,
    ) {}

    public function isBlank(): bool
    {
        return $this->value === null || $this->value === '';
    }
}
