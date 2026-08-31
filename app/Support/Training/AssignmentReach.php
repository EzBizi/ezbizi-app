<?php

namespace App\Support\Training;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Whether one person may hand training to another, or to a role
 * (training task-9 brief; docs/EZBIZI-HR-PLAN.md, §4).
 *
 * ── The rule, restated for this module ────────────────────────────
 *
 * "He should only be able to see same level or downwards when his options to
 * assign an employee are presented" — the owner's rule for onboarding, citing
 * §4 rather than anything HR-specific. A flat `training.assign` permission
 * would let a Riverside supervisor pile mandatory training onto Northgate's
 * crew, which is the same scope leak Inventory, Fleet and Phone each shipped
 * and each had to close. This class is where Training closes it before it
 * ships once.
 *
 * ── Why this asks the ASSIGNEE's places, not the content's ───────
 *
 * A guide or a test carries its own `company_id`/`franchise_id` — where it is
 * FILED, and who may manage it (TrainingGuidePolicy, TrainingTestPolicy). An
 * assignment is a different question: who may give this particular guide to
 * this particular person, which depends on where the PERSON stands, not where
 * the guide is filed. An organization-wide guide is filed everywhere and
 * reachable by everyone with `training.view` — reach over ASSIGNING it still
 * has to be reach over the assignee, or a franchise supervisor could hand an
 * org-wide guide to somebody two states over.
 *
 * `mayAssignTo` asks the question the User model already answers for every
 * other §4 decision: is every place the assignee holds one the assigner
 * `canActOnCompany`/`canActOnFranchise`/`canActOnOrganization`? Nothing here
 * re-derives reach — every branch below delegates to those three methods,
 * which is the same discipline `Refusal`'s own docblock names as the trap
 * (R6): a second home for §4 is a second place for it to disagree with the
 * first.
 *
 * An assignee with NO assignment at all is the one case those three methods
 * cannot answer on their own — there is no place to ask about. The
 * conservative reading used everywhere else this comes up
 * (`NotificationDispatcher::audienceFor`'s own docblock, `ScopeReach`'s
 * organization branch) is that "no narrower place" means the organization, so
 * only somebody who reaches the whole organization may assign to somebody
 * unplaced.
 *
 * ── The role case ──────────────────────────────────────────────────
 *
 * Roles are organization-GLOBAL (Role's own docblock) — a role has no place of
 * its own, and its members can be spread across every franchise the tenant
 * has. "Checked the same way against the role's scope" means the role's scope
 * IS the set of places its CURRENT members hold, asked with the identical
 * per-place check `mayAssignTo` already makes for one person — not a second,
 * looser rule for roles. A role with no current holder is the same "nothing
 * to check reach against" case a single unplaced assignee is, and gets the
 * same conservative answer: only organization-wide reach may target it,
 * because the very next member added could be anybody in the tenant and there
 * is no re-check when that happens (decision 1: a role assignment is one row,
 * read-time union, never expanded — the same property that makes adding
 * somebody to a role hand them the training with no sync job is what makes an
 * empty role's current membership tell an assigner nothing about who will
 * hold it next).
 */
final class AssignmentReach
{
    /** Every place the assignee holds is one the assigner may act on. */
    public static function mayAssignTo(User $assigner, User $assignee): bool
    {
        if (! $assignee->is_active || $assignee->organization_id !== $assigner->organization_id) {
            return false;
        }

        return self::coversEveryAssignment($assigner, $assignee->assignments);
    }

    /** The same question asked of everybody who currently holds a role. */
    public static function mayAssignToRole(User $assigner, Role $role): bool
    {
        if ($role->organization_id !== $assigner->organization_id) {
            return false;
        }

        $members = $role->users()->with('assignments')->get();

        if ($members->isEmpty()) {
            return $assigner->canActOnOrganization($assigner->organization);
        }

        return $members->every(fn (User $member) => self::mayAssignTo($assigner, $member));
    }

    /**
     * Why `mayAssignTo` said no, as a sentence via {@see Refusal} — never a
     * section number, a class name, or the word "immutable" (§4's own house
     * style). Called only after `mayAssignTo` has already returned false;
     * this does not re-decide anything, it walks the same assignments a
     * second time to name the one place that failed, for the human being
     * refused.
     */
    public static function explain(User $assigner, User $assignee): Response
    {
        $act = "assign training to {$assignee->name}";

        $assignments = $assignee->assignments;

        if ($assignments->isEmpty()) {
            return Refusal::noReach($assigner, Refusal::AT_ORGANIZATION, $act, $assigner->organization->name);
        }

        foreach ($assignments as $assignment) {
            $refusal = self::refusalFor($assigner, $assignment, $act);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        // mayAssignTo already said yes for every assignment — nothing to
        // explain. Unreachable in practice; explain() is only ever called
        // once mayAssignTo has returned false.
        return Response::allow();
    }

    /** {@see explain}, for a role. */
    public static function explainRole(User $assigner, Role $role): Response
    {
        $act = "assign training to the {$role->name} role";

        $members = $role->users()->with('assignments')->get();

        if ($members->isEmpty()) {
            return Refusal::noReach($assigner, Refusal::AT_ORGANIZATION, $act, $assigner->organization->name);
        }

        foreach ($members as $member) {
            foreach ($member->assignments as $assignment) {
                $refusal = self::refusalFor($assigner, $assignment, $act);

                if ($refusal !== null) {
                    return $refusal;
                }
            }
        }

        return Response::allow();
    }

    private static function refusalFor(User $assigner, Assignment $assignment, string $act): ?Response
    {
        if (self::covers($assigner, $assignment)) {
            return null;
        }

        return match ($assignment->scopeType()) {
            Assignment::SCOPE_FRANCHISE => Refusal::noReach(
                $assigner,
                Refusal::AT_FRANCHISE,
                $act,
                Franchise::find($assignment->assigned_franchise_id)?->name ?? 'that franchise',
            ),
            Assignment::SCOPE_COMPANY => Refusal::noReach(
                $assigner,
                Refusal::AT_COMPANY,
                $act,
                Company::find($assignment->assigned_company_id)?->name ?? 'that company',
            ),
            Assignment::SCOPE_ORGANIZATION => Refusal::noReach(
                $assigner,
                Refusal::AT_ORGANIZATION,
                $act,
                $assigner->organization->name,
            ),
        };
    }

    /** @param  \Illuminate\Support\Collection<int, Assignment>  $assignments */
    private static function coversEveryAssignment(User $assigner, $assignments): bool
    {
        if ($assignments->isEmpty()) {
            return $assigner->canActOnOrganization($assigner->organization);
        }

        foreach ($assignments as $assignment) {
            if (! self::covers($assigner, $assignment)) {
                return false;
            }
        }

        return true;
    }

    private static function covers(User $assigner, Assignment $assignment): bool
    {
        return match ($assignment->scopeType()) {
            Assignment::SCOPE_ORGANIZATION => $assigner->canActOnOrganization($assigner->organization),
            Assignment::SCOPE_COMPANY => ($company = Company::find($assignment->assigned_company_id)) !== null
                && $assigner->canActOnCompany($company),
            Assignment::SCOPE_FRANCHISE => ($franchise = Franchise::find($assignment->assigned_franchise_id)) !== null
                && $assigner->canActOnFranchise($franchise),
        };
    }
}
