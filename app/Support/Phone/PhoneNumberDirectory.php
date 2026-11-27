<?php

namespace App\Support\Phone;

use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Support\Access\GrantDirectory;

/**
 * The phone lines the roles editor may grant, for this organization.
 */
final class PhoneNumberDirectory implements GrantDirectory
{
    public const MODULE = 'phone';

    public function moduleKey(): string
    {
        return self::MODULE;
    }

    /** @return GrantablePhoneNumber[] */
    public function forOrganization(Organization|string $organization): array
    {
        return PhoneNumber::query()
            ->where('organization_id', $organization instanceof Organization
                ? $organization->getKey()
                : $organization)
            ->orderBy('e164')
            ->get(['id', 'e164', 'label'])
            ->map(fn (PhoneNumber $number) => new GrantablePhoneNumber(
                (string) $number->getKey(),
                $number->e164,
                $number->label,
            ))
            ->all();
    }

    /** @return array<string, GrantablePhoneNumber> */
    public function keyedByIdFor(Organization|string $organization): array
    {
        return array_column($this->forOrganization($organization), null, 'id');
    }
}
