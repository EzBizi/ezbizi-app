<?php

namespace App\Support\Privacy;

/**
 * One field of personal data, why it is held, and what erasure does to it (§9).
 *
 * `purpose` is the load-bearing property and the one that cannot be
 * reconstructed later. "We hold a phone number" is discoverable by anybody with
 * a schema dump; "we hold it because a technician has to call ahead on the day"
 * is the answer a rights request actually needs, and it exists only in the head
 * of whoever added the column. Written down at that moment, it costs a
 * sentence. Written down two years later, it is guesswork presented as fact.
 *
 * `erasure` is the second thing only that person knows, and it is required for
 * the same reason. See Erasure: the purge is DERIVED from these declarations
 * rather than keeping a list of its own, so a column added without a disposition
 * is a column the purge would silently miss — and the entry refuses to construct
 * without one instead.
 */
final class PersonalDataEntry
{
    /** Broad kinds, because the question "what do you hold" is asked in kinds. */
    public const IDENTITY  = 'identity';
    public const CONTACT   = 'contact';
    public const FINANCIAL = 'financial';
    public const ACTIVITY  = 'activity';
    public const TECHNICAL = 'technical';
    public const CONTENT   = 'content';

    public function __construct(
        /**
         * The column, or — for tenant-defined data — how the fields are named.
         *
         * For an entry the purge acts on this is a comma-separated list of REAL
         * columns and nothing else, because that list is what the Anonymiser
         * reads: there is no second declaration of the columns, so prose here
         * would be prose the purge tries to UPDATE.
         * PersonalDataPurgeTest::test_every_erasable_entry_names_columns_that_exist
         * checks every erasable entry against the live schema, which is what
         * keeps that true after the next edit rather than at the moment of
         * writing.
         */
        public readonly string $field,
        public readonly string $category,
        public readonly string $purpose,
        /** What a purge does to this, and — where it survives one — why. */
        public readonly Erasure $erasure,
        /**
         * True where the data is not in a column at all but inside a value a
         * tenant defined. §9's warning is exactly this: "reconstructing that
         * list later is guesswork", and tenant-defined fields are what make an
         * erasure request unanswerable without an inventory. An entry flagged
         * here tells the reader the list of fields is DATA, and where to find it.
         */
        public readonly bool $tenantDefined = false,
        /** Unreadable in a database dump — Laravel's `encrypted` cast. */
        public readonly bool $encrypted = false,
    ) {}

    /**
     * The columns this entry names, for the purge to write to.
     *
     * Split from `field` rather than declared twice. The alternative — a
     * `columns:` argument beside the display string — is two homes for one fact
     * (R6), and the row where they disagreed would be the one where the
     * inventory told somebody a column was erased and the purge never touched it.
     *
     * @return string[]
     */
    public function columns(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->field))));
    }

    public function toArray(): array
    {
        return [
            'field'          => $this->field,
            'category'       => $this->category,
            'purpose'        => $this->purpose,
            'tenant_defined' => $this->tenantDefined,
            'encrypted'      => $this->encrypted,
            'erasure'        => $this->erasure->toArray(),
        ];
    }
}
