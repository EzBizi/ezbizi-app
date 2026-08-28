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
 * Four questions, same shape as MailboxAccess:
 *   1. Permission — `inspection.create` to start or submit, owner-only to see a run.
 *   2. Scope — the named inspection exists in one of this person's places.
 *   3. Grant — the organization offered this named inspection to their role.
 *   4. Reach — customers are org-visible; jobs stay franchise-scoped (asked at start).
 *
 * Scope and grant are separate on purpose: scope says WHERE an inspection
 * exists, the grant says WHO may run it. Answering both in one clause would
 * make a refusal impossible to read.
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

    public function __construct(
        private readonly InspectionScope $scope,
    ) {}

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

        // Scope first, then grants. An Administrator passes both — the scope
        // class exempts them for the same reason this method does, so the role
        // that hands out grants cannot be locked out of its own picker.
        $this->scope->constrain($query, $user);

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
