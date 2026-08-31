<?php

namespace App\Support\Training;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
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
 * re-derives that per-place answer — [covers] delegates to
 * `ScopeReach::coversAssignment`, the ONE place in this codebase that already
 * routes an `Assignment`'s scope type to the right one of those three methods
 * (onboarding itself goes through it — `HrOnboardingsController`,
 * `HrPeopleController` — as do `NotificationDispatcher::audienceFor` and
 * `AutomationsController::maySeeSubjectOf`). An earlier version of this class
 * re-implemented that same three-way dispatch by hand; `ScopeReach`'s own
 * docblock names avoiding exactly that copy as its reason for existing, and a
 * §4 tightening made there would otherwise have left Training assigning under
 * the old rule, silently. Nothing here re-derives PLACE reach either way —
 * `Refusal`'s own docblock names a second home for §4 as the trap (R6).
 *
 * An assignee with NO assignment at all is the one case `ScopeReach` cannot
 * answer on its own — there is no place to ask about. The conservative
 * reading used everywhere else this comes up
 * (`NotificationDispatcher::audienceFor`'s own docblock, `ScopeReach`'s
 * organization branch) is that "no narrower place" means the organization, so
 * only somebody who reaches the whole organization may assign to somebody
 * unplaced.
 *
 * ── mayAssignTo and explain share ONE source of truth ─────────────
 *
 * [mayAssignTo] (a bool) and [explain] (a sentence) used to be two separate
 * walks over the assignee's assignments, asking the same question two
 * different ways — which is exactly the shape that drifts. It did: review
 * found that `mayAssignTo` refuses an INACTIVE assignee, but the old
 * `explain` never checked that at all, walked straight to "every assignment
 * is covered" (true — inactivity has nothing to do with place), and fell
 * through to `Response::allow()`. `AssignmentWriter::authorizeUser` calls
 * `explain(...)->authorize()` only once `mayAssignTo` has already said no,
 * and `authorize()` on an ALLOW is a no-op — so the write proceeded for an
 * assignee `mayAssignTo` had just refused. Fail OPEN, not fail closed.
 *
 * Fixed by deleting the second copy of the question rather than patching the
 * missing branch: [refusalForAssignee] is now the only place that decides,
 * returning `null` (allowed) or the `Response` naming why not, and both
 * [mayAssignTo] and [explain] only read its answer. There is no longer a
 * bool-shaped and a sentence-shaped version of the same fact to keep in
 * step — they cannot disagree because there is only one of them.
 *
 * ── The role case — organization reach, always ─────────────────────
 *
 * Roles are organization-GLOBAL (Role's own docblock) — a role has no place of
 * its own, and a role assignment (decision 1: one row, never expanded, read
 * at Learn time via `LearnController::assignedQuery`'s union) is never
 * re-checked when membership changes.
 *
 * An earlier version of this method asked the question per CURRENT member —
 * "is every place a current holder stands one the assigner reaches" — with an
 * empty role answered conservatively (organization reach only) and anything
 * with at least one member answered by walking them. Review found the two-
 * step hole that shape leaves open: a Riverside supervisor legitimately
 * assigns to a role whose only member today is at Riverside; a Northgate
 * hire is given that role tomorrow; the Riverside supervisor's mandatory
 * training is now sitting on a Northgate crew member's list, and nothing
 * re-runs the check when the membership changed to make that true. The old
 * rule was also backwards on its own terms — it trusted a ONE-member role
 * (thinner evidence, since membership can turn over fastest right where it
 * is smallest) more than an EMPTY one, when both carry identical exposure to
 * whoever the role picks up next.
 *
 * Assigning to a role is a promise about EVERYONE WHO WILL EVER HOLD IT, not
 * about who holds it today — decision 1 is exactly what makes that durable
 * rather than a snapshot. A promise that wide is an organization-level act,
 * full stop, whatever the role's membership looks like right now. So
 * [mayAssignToRole] asks one question and nothing about membership:
 * `canActOnOrganization`. Ruled by the reviewer (task-9 round 2); recorded
 * here and in `docs/superpowers/specs/2026-08-28-training-module-design.md`
 * §5, which is where "checked the same way against the role's scope" had
 * previously lived as the only account of this decision.
 */
final class AssignmentReach
{
    /** Every place the assignee holds is one the assigner may act on. */
    public static function mayAssignTo(User $assigner, User $assignee): bool
    {
        return self::refusalForAssignee($assigner, $assignee) === null;
    }

    /**
     * A role assignment is an organization-level act regardless of who
     * currently holds the role — see this class's own docblock, "The role
     * case — organization reach, always".
     */
    public static function mayAssignToRole(User $assigner, Role $role): bool
    {
        return $role->organization_id === $assigner->organization_id
            && $assigner->canActOnOrganization($assigner->organization);
    }

    /**
     * Why `mayAssignTo` said no, as a sentence via {@see Refusal} — never a
     * section number, a class name, or the word "immutable" (§4's own house
     * style). Called only after `mayAssignTo` has already returned false;
     * reads [refusalForAssignee]'s answer rather than re-deriving it, which
     * is what guarantees this can never disagree with `mayAssignTo` — see
     * this class's own docblock, "mayAssignTo and explain share ONE source
     * of truth".
     */
    public static function explain(User $assigner, User $assignee): Response
    {
        return self::refusalForAssignee($assigner, $assignee) ?? Response::allow();
    }

    /**
     * {@see explain}, for a role — always the organization-level sentence,
     * since [mayAssignToRole] only ever refuses for that one reason (a
     * different organization is answered `Response::denyAsNotFound()`-style
     * by `Role::findOrFail` never finding a cross-tenant id in the first
     * place, before this is ever reached).
     */
    public static function explainRole(User $assigner, Role $role): Response
    {
        return Refusal::noReach(
            $assigner,
            Refusal::AT_ORGANIZATION,
            "assign training to the {$role->name} role",
            $assigner->organization->name,
        );
    }

    /**
     * The single source of truth [mayAssignTo] and [explain] both read —
     * `null` means allowed, a `Response` names why not. See this class's own
     * docblock, "mayAssignTo and explain share ONE source of truth", for why
     * this exists rather than a bool-returning and a Response-returning
     * version of the identical walk.
     */
    private static function refusalForAssignee(User $assigner, User $assignee): ?Response
    {
        if (! $assignee->is_active) {
            return Response::deny(
                "{$assignee->name}'s account is not active, so there is nothing to assign training to."
            );
        }

        if ($assignee->organization_id !== $assigner->organization_id) {
            // Defensive only: every real caller resolves the assignee via
            // `User::findOrFail`, which is tenant-scoped (`BelongsToTenant`)
            // — a cross-organization id never reaches here through the real
            // write path. Kept so this method is correct even if that ever
            // stops being true.
            return Response::denyAsNotFound();
        }

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

        return null;
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

    /**
     * Delegates to `ScopeReach::coversAssignment` — see this class's own
     * docblock on why re-implementing the scope-type dispatch here a second
     * time was the defect, not merely a style choice.
     */
    private static function covers(User $assigner, Assignment $assignment): bool
    {
        return app(ScopeReach::class)->coversAssignment($assigner, $assignment);
    }
}
