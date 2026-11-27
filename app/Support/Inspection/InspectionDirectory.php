<?php

namespace App\Support\Inspection;

use App\Models\InspectionDefinition;
use App\Models\Organization;
use App\Support\Access\GrantDirectory;

/**
 * The named inspections the roles editor may grant, for this organization.
 *
 * Unpublished ones are listed: granting before publish is how a role is ready
 * the day the inspection goes live. The picker still requires published_at.
 */
final class InspectionDirectory implements GrantDirectory
{
    public const MODULE = 'inspection';

    public function moduleKey(): string
    {
        return self::MODULE;
    }

    /** @return GrantableInspection[] */
    public function forOrganization(Organization|string $organization): array
    {
        return InspectionDefinition::query()
            ->where('organization_id', $organization instanceof Organization
                ? $organization->getKey()
                : $organization)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (InspectionDefinition $d) => new GrantableInspection(
                (string) $d->getKey(),
                $d->name,
            ))
            ->all();
    }

    /** @return array<string, GrantableInspection> */
    public function keyedByIdFor(Organization|string $organization): array
    {
        return array_column($this->forOrganization($organization), null, 'id');
    }
}
