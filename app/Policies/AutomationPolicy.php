<?php

namespace App\Policies;

use App\Models\Automation;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

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
 *
 * Which is exactly why the refusals are worth writing out. Every one of them has
 * the same single cause — a permission the role does not hold — so a bare 403
 * withholds the one fact that would have ended the confusion. Somebody who can
 * read the rules and not switch one off is looking at a screen that appears to
 * be broken, and the remedy is one grant away.
 */
class AutomationPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('automations.view')
            ? Response::allow()
            : Refusal::permission('see automations');
    }

    public function view(User $user, Automation $automation): Response
    {
        return $this->may($user, $automation, 'automations.view', 'see this automation');
    }

    public function create(User $user): Response
    {
        return $user->hasPermission('automations.create')
            ? Response::allow()
            : Refusal::permission('build an automation');
    }

    /** Editing a rule, and switching one on or off — both are `automations.edit`. */
    public function update(User $user, Automation $automation): Response
    {
        return $this->may($user, $automation, 'automations.edit', 'change an automation');
    }

    public function delete(User $user, Automation $automation): Response
    {
        return $this->may($user, $automation, 'automations.delete', 'delete an automation');
    }

    /**
     * A rule from another organization answers "no such rule" rather than "not
     * allowed" — the tenant scope already makes this unreachable, and an id that
     * exists elsewhere on the platform is not something to confirm.
     */
    private function may(User $user, Automation $automation, string $permission, string $act): Response
    {
        if ($automation->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return $user->hasPermission($permission)
            ? Response::allow()
            : Refusal::permission($act);
    }
}
