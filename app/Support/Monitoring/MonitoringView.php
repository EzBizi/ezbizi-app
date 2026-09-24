<?php

namespace App\Support\Monitoring;

use App\Models\Franchise;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows a Monitoring list to the view the switcher is standing in.
 *
 * §3 is two rules and Monitoring only ever applied one. REACH — ScopeReach —
 * is who this person could ever get to. The VIEW — ViewScope — is where they
 * are standing right now, and a list files on the view. Every Monitoring list
 * applied reach and none applied the view, so for an administrator (who
 * reaches the whole organization) switching company changed nothing on any
 * tab. Jose, 2026-09-24, in the EzBizi company looking at A-1 Chimney's people
 * and offices: "Issue on Monitoring with ownership as usual."
 *
 * This is applied IN ADDITION to reach, never instead of it: the view can
 * only narrow what reach already allows.
 *
 * ── The rule ────────────────────────────────────────────────────────
 *
 * A row is in the view by its MOST SPECIFIC placement, answered the way
 * ViewScope::coversFranchise / coversCompany answer it:
 *
 *   - placed at a franchise → the view's franchises
 *   - placed at a company   → the view's companies (a company view includes
 *                             its Sub Companies; a franchise view, its own
 *                             company)
 *   - placed at the organization → the organization view only. ViewScope:
 *                             "Null is the organization itself, and that is
 *                             only the group view."
 *
 * A person is placed by their assignments (any one in view puts them in it); a
 * floor plan by its own `assigned_*` columns, which are shaped like an
 * assignment's. The SQL is LeadsController's view arms minus its
 * organization-level arm — Leads keeps org-level rows in every view on
 * purpose, because an org-scoped CONNECTION feeds every company; an
 * org-placed person or office does not work in every company.
 *
 * ── Where it is NOT applied, deliberately ──────────────────────────
 *
 * Lookups of one record by id — a device's live view, remote control, one
 * plan — stay on reach. Those are authorization, not listing, and narrowing
 * them would drop a live session the moment somebody switched company. And the
 * ownership chart's "where does this person sit" stays on reach: a listed
 * person's desk is a fact about them, not something being filed.
 */
final class MonitoringView
{
    /** Keep only the people with an assignment inside the actor's view. */
    public static function people(Builder $users, User $actor): void
    {
        $view = ViewScope::forUser($actor);

        if ($view->isOrganization()) {
            return;
        }

        $users->whereHas('assignments', fn (Builder $assignment) => self::placedIn(
            $assignment, $view, 'assigned_franchise_id', 'assigned_company_id',
        ));
    }

    /** Keep only the floor plans placed inside the actor's view. */
    public static function plans(Builder $plans, User $actor): void
    {
        $view = ViewScope::forUser($actor);

        if ($view->isOrganization()) {
            return;
        }

        self::placedIn($plans, $view, 'assigned_franchise_id', 'assigned_company_id');
    }

    /**
     * The shared predicate. Wrapped in one `where` group because it is two OR
     * arms, and an ungrouped OR would escape every constraint already on the
     * query — reach included.
     */
    private static function placedIn(Builder $query, ViewScope $view, string $franchise, string $company): void
    {
        $companyIds = $view->type === ViewScope::COMPANY
            ? $view->companyIds()
            : array_values(array_filter([Franchise::find($view->id)?->company_id]));

        $query->where(fn (Builder $in) => $in
            ->whereIn($franchise, $view->franchiseIds())
            ->orWhere(fn (Builder $atCompany) => $atCompany
                ->whereNull($franchise)
                ->whereIn($company, $companyIds)));
    }
}
