<?php

namespace App\Support\Privacy;

/**
 * One field of personal data, and why it is held (§9).
 *
 * `purpose` is the load-bearing property and the one that cannot be
 * reconstructed later. "We hold a phone number" is discoverable by anybody with
 * a schema dump; "we hold it because a technician has to call ahead on the day"
 * is the answer a rights request actually needs, and it exists only in the head
 * of whoever added the column. Written down at that moment, it costs a
 * sentence. Written down two years later, it is guesswork presented as fact.
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
        /** The column, or — for tenant-defined data — how the fields are named. */
        public readonly string $field,
        public readonly string $category,
        public readonly string $purpose,
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

    public function toArray(): array
    {
        return [
            'field'          => $this->field,
            'category'       => $this->category,
            'purpose'        => $this->purpose,
            'tenant_defined' => $this->tenantDefined,
            'encrypted'      => $this->encrypted,
        ];
    }
}
