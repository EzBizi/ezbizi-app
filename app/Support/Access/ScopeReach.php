<?php

namespace App\Support\Access;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * "May this person act at that level?" — asked in one place (plan §4).
 *
 * ── Why this exists rather than a fourth copy of the match ───────
 *
 * §4's reach rule already lives on the User: `canActOnFranchise`,
 * `canActOnCompany`, and `canActOnOrganization`. What did not exist was
 * anywhere that took a LEVEL and an ID — which is what an invitation carries,
 * what the assignment picker offers, and what the people list has to filter by —
 * and routed to the right one of the three.
 *
 * Without this, that three-way `match` would have been written four times over:
 * inviting, accepting, listing people, and listing invitations. The owner's rule
 * is a single sentence — "he should only be able to see same level or downwards
 * when his options to assign an employee are presented" — and four copies of a
 * single sentence is three chances to write it slightly differently, in the one
 * part of the system where "slightly differently" means somebody assigns staff
 * to a company they have no authority over.
 *
 * Nothing new is decided here. Every branch delegates to the User methods that
 * have held §4's rule since the foundation; this only chooses which to ask.
 */
class ScopeReach
{
    /** The three levels an assignment — and therefore an invitation — may name. */
    public const TYPES = [
        Assignment::SCOPE_ORGANIZATION,
        Assignment::SCOPE_COMPANY,
        Assignment::SCOPE_FRANCHISE,
    ];

    /**
     * Everything a given person can reach, worked out once.
     *
     * @var array<string, array{organization: bool, companies: string[], franchises: string[]}>
     */
    private array $memo = [];

    /**
     * Does this person's authority cover that place?
     *
     * An id that is not in the set answers false, and that covers "no such
     * thing" and "belongs to another organization" with the same word — which is
     * the right answer rather than a convenient one. The sets are built from
     * tenant-scoped queries, so an id from elsewhere on the platform is simply
     * absent, and telling a caller which of the two it was would be an existence
     * oracle.
     */
    public function covers(User $actor, string $type, ?string $id): bool
    {
        if ($id === null) {
            return false;
        }

        $reach = $this->reachOf($actor);

        return match ($type) {
            // The organization is the one level nothing below can reach, so this
            // is not "is the id in a set" but "does this person stand at the top".
            Assignment::SCOPE_ORGANIZATION =>
                $reach['organization'] && $id === $actor->organization_id,

            Assignment::SCOPE_COMPANY   => in_array($id, $reach['companies'], strict: true),
            Assignment::SCOPE_FRANCHISE => in_array($id, $reach['franchises'], strict: true),

            default => false,
        };
    }

    /**
     * The three answers, resolved once per person and kept.
     *
     * `reachableCompanies()` and `reachableFranchises()` each read the whole
     * table and filter in PHP, which is fine when a screen asks once and
     * catastrophic when a list of fifty people asks twice per assignment. The
     * people list does exactly that — it has to say, for each person, which of
     * their places the reader is allowed to see — so a request that should be
     * two queries was a hundred and fifty.
     *
     * Memoised per ACTOR rather than globally, because the same request asks
     * about the caller in the controller and about the caller again in the
     * policy, and there is no request in which a person's reach changes halfway
     * through. The class is registered `scoped` (AppServiceProvider) so the
     * memo is dropped between requests and between queued jobs — the same
     * lifetime ModuleAvailability's cache is allowed, and for the same reason:
     * a long-lived worker must not keep yesterday's answer.
     *
     * @return array{organization: bool, companies: string[], franchises: string[]}
     */
    private function reachOf(User $actor): array
    {
        // Keyed by role too: a View-as role clone shares its actor's id (ViewAsUser).
        return $this->memo[$actor->getKey().'|'.$actor->role_id] ??= [
            'organization' => $actor->canActOnOrganization($actor->organization),
            'companies'    => $actor->reachableCompanies()->modelKeys(),
            'franchises'   => $actor->reachableFranchises()->modelKeys(),
        ];
    }

    /** The same question about a place somebody is already assigned to. */
    public function coversAssignment(User $actor, Assignment $assignment): bool
    {
        return $this->covers($actor, $assignment->scopeType(), $assignment->scopeId());
    }

    /**
     * Refuse an out-of-reach target, as a 422 naming the field.
     *
     * 422 and not 403, matching the split RolesController set out: 403 means the
     * TARGET is off limits, 422 means the VALUES are. Somebody who may invite
     * people and picked the wrong franchise has not done anything forbidden —
     * they sent a form with one field they are not entitled to choose, and the
     * response says which.
     *
     * @throws ValidationException
     */
    public function assertCovers(User $actor, string $type, ?string $id, string $field = 'scope_id'): void
    {
        if ($this->covers($actor, $type, $id)) {
            return;
        }

        throw ValidationException::withMessages([$field => sprintf(
            'You cannot place somebody at that %s. You may only assign people at or below '
            .'your own level, and that one is outside it.',
            $type === Assignment::SCOPE_ORGANIZATION ? 'level' : $type,
        )]);
    }

    /**
     * The same question asked of a whole table, in SQL.
     *
     * For any table carrying the three `assigned_*` columns — `assignments` and
     * `invitations` — narrow it to rows whose scope this person's authority
     * covers.
     *
     * ── Why this exists beside covers(), rather than instead of it ──
     *
     * A list cannot be filtered row by row in PHP and paginated at the same
     * time: the count would describe the page before the filter and the
     * navigation would be wrong. So the list needs a predicate.
     *
     * That does put the rule in two shapes, and the risk is that they drift.
     * They are kept in one class, next to each other, for exactly that reason —
     * and neither of them decides anything: both are built from
     * `reachableFranchises`, `reachableCompanies` and `canActOnOrganization`, so
     * a change to §4's rule changes one place and both follow.
     *
     * The organization branch is added only for somebody who reaches the
     * organization; without it, an organization-wide row would be invisible to
     * a company manager, which is correct — they may not manage somebody whose
     * authority is wider than their own.
     */
    public function constrain(Builder $query, User $actor): void
    {
        $reach = $this->reachOf($actor);

        $query->where(function (Builder $scoped) use ($actor, $reach) {
            // An empty list compiles to `0 = 1` rather than being dropped, which
            // is the safe direction: somebody who reaches nothing matches nothing.
            $scoped->whereIn('assigned_franchise_id', $reach['franchises'])
                ->orWhereIn('assigned_company_id', $reach['companies']);

            if ($reach['organization']) {
                $scoped->orWhere('assigned_organization_id', $actor->organization_id);
            }
        });
    }

    /**
     * Which PEOPLE this actor may see — a `users` query narrowed to reach.
     *
     * Three arms:
     *
     *   yourself           always, whatever your assignments say; a list that
     *                      does not contain the person reading it looks broken
     *   anybody assigned   to a place your authority covers (via [constrain]
     *                      on their `assignments`)
     *   the unassigned     only if you reach the whole organization, because
     *                      there is no smaller place to check them against
     *
     * `$query` must already be scoped to the `users` table — directly (a list
     * of people) or through a relation (`whereHas('user', fn ($u) =>
     * $reach->constrainToVisiblePeople($u, $actor))`, for a row that BELONGS
     * to a person rather than being one, such as a monitored device). Shared
     * between HrPeopleController (direct) and Monitoring's DevicesController
     * (through the relation) so the one predicate for "may you see this
     * person" cannot drift into two.
     */
    public function constrainToVisiblePeople(Builder $query, User $actor): void
    {
        $query->whereKey($actor->getKey())
            ->orWhereHas('assignments', fn (Builder $a) => $this->constrain($a, $actor));

        if ($actor->canActOnOrganization($actor->organization)) {
            $query->orWhereDoesntHave('assignments');
        }
    }

    /**
     * Every place this person may assign somebody to — the picker's whole content.
     *
     * The organization itself appears only for somebody assigned at organization
     * level, which is the "same level or downwards" rule at its top tier: a
     * company manager who could hand out an organization-wide assignment would be
     * granting reach over companies they cannot see themselves.
     *
     * Sub Companies come back in the same flat list as their parents, with
     * `parent_company_id` on each, for the same reason /me carries it: drawn as
     * one flat row, a Sub Company reads as a sibling of the company it belongs to.
     *
     * @return array{organization: ?array, companies: array, franchises: array}
     */
    public function optionsFor(User $actor): array
    {
        $organization = $actor->organization;
        // Switched off by the one rule (a Sub Company under a parent that is
        // off is off too), so a picker can leave them out (Jose, 2026-11-10).
        $companies = $actor->reachableCompanies();
        $franchises = $actor->reachableFranchises();
        $offCompanies = Company::query()->whereKey($companies->pluck('id')->all())->switchedOff()->pluck('id')->all();
        $offPlaces = Franchise::query()->whereKey($franchises->pluck('id')->all())->switchedOff()->pluck('id')->all();

        return [
            'organization' => $actor->canActOnOrganization($organization)
                ? ['id' => $organization->getKey(), 'name' => $organization->name]
                : null,

            'companies' => $companies->map(fn (Company $c) => [
                'id'                => $c->getKey(),
                'name'              => $c->name,
                'parent_company_id' => $c->parent_company_id,
                'is_active'         => ! in_array($c->getKey(), $offCompanies, true),
            ])->values()->all(),

            'franchises' => $franchises->map(fn (Franchise $f) => [
                'id'         => $f->getKey(),
                'name'       => $f->name,
                'company_id' => $f->company_id,
                'is_active'  => ! in_array($f->getKey(), $offPlaces, true),
            ])->values()->all(),
        ];
    }
}
