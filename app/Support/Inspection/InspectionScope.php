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
    /**
     * Which FILED REPORTS a person may read, by the branch the work sits at.
     *
     * ── Why this is not a copy in two places ────────────────────
     *
     * It was. `InspectionReportsController::index` carried the filter and
     * `InspectionGlance::constrainToReach` carried a copy whose docblock said
     * "Same franchise filter as InspectionReportsController::index" — so when
     * the controller was wrong the dashboard was wrong identically, and the
     * comment asserting they matched is the reason nobody re-read the second
     * one. One home, two call sites.
     *
     * ── The hole it closes ──────────────────────────────────────
     *
     * Both copies read `whereNull('job_id')->orWhereHas('job', ...)`, which let
     * EVERY report filed without a job through to every branch in the
     * organization. A customer-only inspection is ordinary, not a corner: the
     * picker allows starting one against a client with no job, and
     * `InspectionReport::automationScope()` already falls back to the
     * customer's `owning_franchise_id` for exactly that reason. The READ side
     * simply never asked the second question.
     *
     * So a report answers with its JOB's branch, and with its CUSTOMER's when
     * there is no job — the same order, and the same two questions, that decide
     * who gets notified about it. `withTrashed()` on both, and that is not
     * tidiness: a job soft-deletes and a report outlives it, and §9 anonymises
     * a customer in place rather than removing the row, so reading through the
     * default scope would drop a report out of its own branch's list the moment
     * either was binned.
     *
     * A report whose customer has no owning franchise is visible to nobody
     * here, deliberately. It cannot say where it sits, and the answer to "which
     * branch may read this" is never "all of them".
     *
     * STATIC on purpose: the dashboard glance is not a controller and has no
     * constructor to inject into, and a filter that two callers must agree on
     * is worth more than matching the shape of `constrain()` above it.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\InspectionReport>  $query
     * @param  array<int, string>  $reachable  franchise ids this person may see
     */
    public static function constrainReports(Builder $query, array $reachable): void
    {
        $query->where(function (Builder $q) use ($reachable) {
            $q->whereHas(
                'job',
                fn (Builder $jobs) => $jobs->withTrashed()->whereIn('franchise_id', $reachable),
            )->orWhere(function (Builder $noJob) use ($reachable) {
                $noJob->whereNull('job_id')->whereHas(
                    'customer',
                    fn (Builder $c) => $c->withTrashed()->whereIn('owning_franchise_id', $reachable),
                );
            });
        });
    }

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
