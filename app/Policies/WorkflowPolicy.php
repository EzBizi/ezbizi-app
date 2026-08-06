<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workflow;

/**
 * Who may automate (plan §5.5, §4).
 *
 * ── Why this is not a franchise-reach question ───────────────────
 *
 * A workflow acts on records, so the obvious instinct is to scope it the way
 * jobs are scoped. That is wrong in a way worth stating: a rule is not a record,
 * it is a standing instruction that will act on records nobody has created yet,
 * repeatedly, without anyone watching. The permission to write one is closer to
 * editing a role than to editing a job.
 *
 * So it is a permission question, and the permission belongs to the module that
 * owns automation. Scope narrows what a rule REACHES (Workflow::coversScope),
 * not who may write it — and a franchise-scoped rule can only ever touch its own
 * franchise's work regardless of who saved it.
 */
class WorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('workflows.view');
    }

    public function view(User $user, Workflow $workflow): bool
    {
        return $user->hasPermission('workflows.view')
            && $workflow->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('workflows.create');
    }

    public function update(User $user, Workflow $workflow): bool
    {
        return $user->hasPermission('workflows.edit')
            && $workflow->organization_id === $user->organization_id;
    }

    public function delete(User $user, Workflow $workflow): bool
    {
        return $user->hasPermission('workflows.delete')
            && $workflow->organization_id === $user->organization_id;
    }
}
