<?php

namespace App\Support\Inspection;

/**
 * One named inspection, as the roles editor needs to see it.
 *
 * Three facts: what to call it, what to store against a role, and whether the
 * person editing may hand it out. The screens JSON does not belong here.
 */
final class GrantableInspection
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}

    /** @return array{id: string, name: string, grantable: bool} */
    public function toArray(bool $grantable): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'grantable' => $grantable,
        ];
    }
}
