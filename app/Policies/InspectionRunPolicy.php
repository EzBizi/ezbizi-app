<?php

namespace App\Policies;

use App\Models\InspectionRun;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Inspection\InspectionAccess;
use Illuminate\Auth\Access\Response;

class InspectionRunPolicy
{
    public function __construct(
        private readonly InspectionAccess $access,
    ) {}

    public function create(User $user): Response
    {
        return $user->hasPermission(InspectionAccess::CREATE)
            ? Response::allow()
            : Refusal::permission('start an inspection');
    }

    public function view(User $user, InspectionRun $run): Response
    {
        if (! $this->access->maySeeRun($user, $run)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function update(User $user, InspectionRun $run): Response
    {
        if (! $this->access->maySeeRun($user, $run)) {
            return Response::denyAsNotFound();
        }

        return $user->hasPermission(InspectionAccess::CREATE)
            ? Response::allow()
            : Refusal::permission('fill in an inspection');
    }

    public function delete(User $user, InspectionRun $run): Response
    {
        if (! $this->access->maySeeRun($user, $run)) {
            return Response::denyAsNotFound();
        }

        return $user->hasPermission('inspection.delete')
            ? Response::allow()
            : Refusal::permission('discard an in-progress inspection');
    }
}
