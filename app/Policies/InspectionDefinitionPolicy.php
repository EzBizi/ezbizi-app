<?php

namespace App\Policies;

use App\Models\InspectionDefinition;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Builder is `inspection.edit`. Starting a run is `inspection.create` and a
 * grant; this policy is only the authoring surface.
 */
class InspectionDefinitionPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->mayDesign($user);
    }

    public function view(User $user, InspectionDefinition $definition): Response
    {
        if ($definition->organization_id !== $user->organization_id) {
            return Response::deny('That inspection belongs to another organization.');
        }

        return $this->mayDesign($user);
    }

    public function create(User $user): Response
    {
        return $this->mayDesign($user);
    }

    public function update(User $user, InspectionDefinition $definition): Response
    {
        if ($definition->organization_id !== $user->organization_id) {
            return Response::deny('That inspection belongs to another organization.');
        }

        return $this->mayDesign($user);
    }

    /**
     * Removing a never-published draft. Once a version exists, unpublish is
     * the retirement — delete would take history with it.
     */
    public function delete(User $user, InspectionDefinition $definition): Response
    {
        if ($definition->organization_id !== $user->organization_id) {
            return Response::deny('That inspection belongs to another organization.');
        }

        return $this->mayDesign($user);
    }

    private function mayDesign(User $user): Response
    {
        if (! $user->hasPermission('inspection.edit')) {
            return Refusal::permission('design named inspections');
        }

        return Response::allow();
    }
}
