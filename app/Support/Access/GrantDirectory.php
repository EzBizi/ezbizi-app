<?php

namespace App\Support\Access;

use App\Models\Organization;

/** The tenant records one module's roles-editor grants are made of (see RoleGrantAccess). */
interface GrantDirectory
{
    /** The module the records nest under. */
    public function moduleKey(): string;

    /** @return object[] this organization's records, each with a string `id` and toArray(bool $grantable) */
    public function forOrganization(Organization|string $organization): array;

    /** @return array<string, object> the same list keyed by id — what a grant is validated against */
    public function keyedByIdFor(Organization|string $organization): array;
}
