<?php

namespace App\Support\Inspection;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InspectionDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which named inspections exist for a given person.
 *
 * ── Not the same question as reach, and that is the whole file ───
 *
 * `ScopeReach::covers` answers "may this person ACT on that place". This
 * answers "is that place AT OR ABOVE this person". They disagree in exactly the
 * case that matters: a technician assigned to one franchise reaches that
 * franchise and nothing above it — correctly, because they may not administer
 * the company — and yet the inspection their company owns is precisely the one
 * they are meant to run.
 *
 * Getting this backwards makes a company-scoped inspection invisible to every
 * technician who would use it, and the symptom is an empty picker rather than
 * anything that looks like a scope bug. `Automation::coversScope` records the
 * same hazard from the other side: a scope the API accepted and the engine
 * could never match, so the rule looked configured and never ran.
 *
 * Reach is still the right question for AUTHORING, and
 * InspectionDefinitionsController asks it there — a franchise manager must not
 * be able to scope the group's inspection to their own franchise and take it
 * away from everybody else. Two questions, two answers, deliberately in
 * different classes so the wrong one is inconvenient to reach for.
 *
 * ── Sideways is closed, deliberately ────────────────────────────
 *
 * The chain walks UP from the person's franchises and never down. A franchise
 * sees itself, its company, that company's parents, and the organization. It
 * never sees a sibling franchise's inspection, and never a Sub Company's — the
 * settings cascade runs the other way (§6) and reusing it here would hand one
 * franchise another's forms.
 */
final class InspectionScope
{
    /**
     * Narrow a definitions query to what this person's places can see.
     *
     * An Administrator sees everything: they are the role that administers the
     * organization, and a Builder that hid a company's inspection from the one
     * person who has to fix it would be unusable.
     */
    public function constrain(Builder $query, User $user): void
    {
        if ($user->isAdministrator()) {
            return;
        }

        $franchiseIds = $user->reachableFranchises()->modelKeys();
        $companyIds = $this->companyChainFor($user);

        $query->where(function (Builder $q) use ($franchiseIds, $companyIds) {
            // NULL is the organization, and it is everybody's.
            $q->whereNull('scope_type');

            if ($companyIds !== []) {
                $q->orWhere(fn (Builder $inner) => $inner
                    ->where('scope_type', InspectionDefinition::SCOPE_COMPANY)
                    ->whereIn('scope_id', $companyIds));
            }

            if ($franchiseIds !== []) {
                $q->orWhere(fn (Builder $inner) => $inner
                    ->where('scope_type', InspectionDefinition::SCOPE_FRANCHISE)
                    ->whereIn('scope_id', $franchiseIds));
            }
        });
    }

    /**
     * Every company at or above this person's franchises.
     *
     * Walks up through `parent`, so a Sub Company's franchise also sees the
     * parent company's inspections — the same upward roll-up
     * `AutomationEngine::companyChain` performs, and for the same reason: §4's
     * authority reaches down, so a company owns its Sub Companies' work.
     *
     * Keyed by id while building, so a tenant whose fifty franchises share one
     * company walks that company's parents once rather than fifty times.
     *
     * @return string[]
     */
    public function companyChainFor(User $user): array
    {
        $chain = [];

        $franchises = Franchise::query()
            ->whereIn('id', $user->reachableFranchises()->modelKeys())
            ->with('company')
            ->get();

        foreach ($franchises as $franchise) {
            $company = $franchise->company;

            while ($company instanceof Company) {
                if (isset($chain[$company->getKey()])) {
                    break;
                }

                $chain[$company->getKey()] = true;
                $company = $company->parent;
            }
        }

        return array_keys($chain);
    }
}
