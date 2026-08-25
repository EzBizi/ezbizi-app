<?php

namespace App\Support\Phone;

/**
 * One of the organization's phone lines, as the roles editor needs to see it.
 *
 * Three facts: what to call it, what to store against a role, and whether the
 * person editing may hand it out. Twilio SIDs and connection secrets do not
 * belong in a permissions tree.
 */
final class GrantablePhoneNumber
{
    public function __construct(
        public readonly string $id,
        public readonly string $e164,
        public readonly ?string $label = null,
    ) {}

    /** @return array{id: string, e164: string, label: string|null, grantable: bool} */
    public function toArray(bool $grantable): array
    {
        return [
            'id' => $this->id,
            'e164' => $this->e164,
            'label' => $this->label,
            'grantable' => $grantable,
        ];
    }
}
