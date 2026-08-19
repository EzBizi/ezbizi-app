<?php

namespace App\Support\Inspection;

use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionGrant;
use App\Models\InspectionRun;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * May this person start that named inspection, see this draft, or submit it?
 *
 * Three questions, same shape as MailboxAccess:
 *   1. Permission — `inspection.create` to start or submit, owner-only to see a run.
 *   2. Grant — the organization offered this named inspection to their role.
 *   3. Reach — customers are org-visible; jobs stay franchise-scoped (asked at start).
 *
 * A grant is about the named inspection, not about someone else's draft.
 * Drafts are personal: Rodrigo's run is not Carlos's even when both are granted.
 *
 * The Administrator holds every named inspection without a grants row, matching
 * Role::hasPermission and the mailbox rule — the role that hands out grants
 * cannot be locked out of the picker.
 */
final class InspectionAccess
{
    public const VIEW   = 'inspection.view';
    public const CREATE = 'inspection.create';

    public function maySeeRun(User $user, InspectionRun $run): bool
    {
        return $user->id === $run->user_id
            && $user->organization_id === $run->organization_id;
    }

    public function maySubmit(User $user, InspectionRun $run): bool
    {
        return $this->maySeeRun($user, $run)
            && $user->hasPermission(self::CREATE);
    }

    public function mayStart(User $user, InspectionDefinition $definition): bool
    {
        return $this->startable($user)->contains(
            fn (InspectionDefinition $row) => $row->id === $definition->id,
        );
    }

    /** Published named inspections this role may start. */
    public function startable(User $user): Collection
    {
        if (! $user->hasPermission(self::CREATE)) {
            return collect();
        }

        $query = InspectionDefinition::query()
            ->whereNotNull('published_at')
            ->orderBy('name');

        if (! $user->isAdministrator()) {
            if ($user->role_id === null) {
                return collect();
            }

            $query->whereIn('id', InspectionDefinitionGrant::query()
                ->where('role_id', $user->role_id)
                ->select('inspection_definition_id'));
        }

        return $query->get();
    }
}
