<?php

namespace App\Policies;

use App\Models\Automation;
use App\Models\User;

/**
 * Who may automate (plan §5.5, §4).
 *
 * ── Why this is not a franchise-reach question ───────────────────
 *
 * An automation acts on records, so the obvious instinct is to scope it the way
 * jobs are scoped. That is wrong in a way worth stating: a rule is not a record,
 * it is a standing instruction that will act on records nobody has created yet,
 * repeatedly, without anyone watching. The permission to write one is closer to
 * editing a role than to editing a job.
 *
 * So it is a permission question, and the permission belongs to the module that
 * owns automation. Scope narrows what a rule REACHES (Automation::coversScope),
 * not who may write it — and a franchise-scoped rule can only ever touch its own
 * franchise's work regardless of who saved it.
 */
class AutomationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('automations.view');
    }

    public function view(User $user, Automation $automation): bool
    {
        return $user->hasPermission('automations.view')
            && $automation->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('automations.create');
    }

    public function update(User $user, Automation $automation): bool
    {
        return $user->hasPermission('automations.edit')
            && $automation->organization_id === $user->organization_id;
    }

    public function delete(User $user, Automation $automation): bool
    {
        return $user->hasPermission('automations.delete')
            && $automation->organization_id === $user->organization_id;
    }
}
