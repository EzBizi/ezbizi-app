<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\Contract;
use App\Models\User;
use App\Support\Access\ScopeReach;
use Illuminate\Auth\Access\Response;

/**
 * Who may design the schema (§4, EZBIZI-HR-PLAN.md, "Who may do what").
 *
 * The Contracts tab lives inside Human Resources, so the permissions are HR's —
 * `hr.view` to read the templates, `hr.create` to add one, `hr.edit` to publish
 * a new version of one. There is no separate `contracts` module and there should
 * not be: the plan describes ONE gated module with five tabs, and the roles
 * editor shows one switch per module with the actions folded underneath.
 *
 * ── Writing a template needs ORGANIZATION reach, and that is new ─
 *
 * A contract template is not scoped to a company or a franchise, and it cannot
 * sensibly be: it defines what onboarding asks, and onboarding happens
 * everywhere. So publishing one reaches every franchise in the business.
 *
 * §4's rule is that somebody may act at their own level and downwards. Applied
 * honestly to an object whose effect is organization-wide, that means authoring
 * a template requires standing at the organization. Without this check, a
 * branch manager holding `hr.create` — a perfectly ordinary grant, given it is
 * also what lets them invite a technician — could add a bank-account field to
 * every new starter in the business, or retire a field the other franchises
 * depend on. That is a wider authority than anything else `hr.create` opens, and
 * it would arrive silently attached to it.
 *
 * READING is deliberately not restricted the same way. A franchise manager
 * running an onboarding has to see the template they are working from, and the
 * template contains no personal data — only the questions.
 */
class ContractPolicy
{
    public function __construct(
        private readonly ScopeReach $reach,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hr.view');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->hasPermission('hr.view')
            && $contract->organization_id === $user->organization_id;
    }

    public function create(User $user): Response
    {
        return $this->mayAuthor($user, 'hr.create', 'create a contract template');
    }

    /** Renaming it, and publishing a new version of it — both are `hr.edit`. */
    public function update(User $user, Contract $contract): Response
    {
        if ($contract->organization_id !== $user->organization_id) {
            return Response::deny('That contract belongs to another organization.');
        }

        return $this->mayAuthor($user, 'hr.edit', 'change a contract template');
    }

    private function mayAuthor(User $user, string $permission, string $verb): Response
    {
        if (! $user->hasPermission($permission)) {
            return Response::deny("You do not have permission to {$verb}.");
        }

        if (! $this->reach->covers($user, Assignment::SCOPE_ORGANIZATION, $user->organization_id)) {
            return Response::deny(sprintf(
                'A contract template decides what every onboarding in this organization asks, '
                .'so you have to stand at the organization to %s. Somebody assigned at that '
                .'level can, and you can use the templates they publish.',
                $verb,
            ));
        }

        return Response::allow();
    }
}
