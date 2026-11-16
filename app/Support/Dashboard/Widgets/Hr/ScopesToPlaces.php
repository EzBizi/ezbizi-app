<?php

namespace App\Support\Dashboard\Widgets\Hr;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Dashboard\Widgets\ReportsOverAPeriod;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which PLACES an HR widget is answering about — reach (§4) narrowed by the
 * chosen view (§3).
 *
 * ── Why HR cannot use the other widgets' franchise filter ────────
 *
 * `ScopesToReach::reachable()` narrows a query with `where franchise_id in (…)`,
 * which is right for jobs and customers: those rows carry a franchise column,
 * because work happens in exactly one place.
 *
 * People do not. §4 places somebody at organization, company OR franchise level,
 * and a person assigned at company level has no franchise id at all. Filtering
 * headcount by franchise ids would therefore drop every manager, every company
 * -level employee, and — the case that matters most — the founding administrator,
 * who ProvisionOrganization assigns at ORGANIZATION level and who is the only
 * person a new organization has. The dashboard would have reported a headcount of
 * zero for an organization with somebody in it, and looked right doing it.
 *
 * So this resolves the three levels together, and everything downstream filters
 * on all three or on none.
 *
 * ── Reach ∩ view, not reach alone ────────────────────────────────
 *
 * `ScopeReach::constrain()` answers "may this person act there", which is the
 * floor. It does not know which part of the business is currently being LOOKED
 * at, and the top-bar scope switcher is what the rest of the app filters by.
 * Somebody who runs the whole organization but has switched to Connecticut is
 * asking about Connecticut, and a dashboard that answered organization-wide
 * while every list beside it showed one franchise would be reporting a number
 * that belongs to a different screen.
 *
 * The view can only ever NARROW: every list below is built from
 * `reachableCompanies()` / `reachableFranchises()` and then filtered, so a stored
 * scope naming somewhere the user has since lost cannot widen anything.
 */
trait ScopesToPlaces
{
    use ReportsOverAPeriod;

    /**
     * The places in view, as the three lists §4 recognises.
     *
     * `organization` is non-null only for somebody who actually stands at the
     * top AND is looking at the whole organization — the same condition
     * ScopeReach::constrain() puts on its organization branch, and for the same
     * reason: a company manager may not manage somebody whose authority is wider
     * than their own, so organization-wide rows are not theirs to count.
     *
     * @return array{organization: ?string, companies: string[], franchises: string[]}
     */
    protected function placesInView(User $user): array
    {
        $view = ViewScope::forUser($user);

        // Both already intersected with reach — franchiseIds() does it itself,
        // and the company list is intersected here.
        $franchises = $view->franchiseIds();
        $reachableCompanies = $user->reachableCompanies()->modelKeys();

        $companies = match ($view->type) {
            // §3's roll-up: the company being viewed and its Sub Companies,
            // never the parent above it.
            ViewScope::COMPANY => array_values(array_intersect(
                $reachableCompanies,
                $view->companyIds(),
            )),

            // A franchise view is one franchise. Nobody is counted at the
            // company above it, because that is not what is being looked at.
            ViewScope::FRANCHISE => [],

            default => $reachableCompanies,
        };

        return [
            'organization' => $view->isOrganization()
                && $user->canActOnOrganization($user->organization)
                    ? $user->organization_id
                    : null,
            'companies'  => $companies,
            'franchises' => $franchises,
        ];
    }

    /**
     * Narrow any table carrying the three `assigned_*` columns — `assignments`
     * and `invitations` — to the places in view.
     *
     * The shape ScopeReach::constrain() uses, with the view applied. An empty
     * list compiles to `0 = 1` rather than being dropped, which is the safe
     * direction: somebody whose view covers nothing matches nothing, rather than
     * matching everything because a clause disappeared.
     *
     * @param  array{organization: ?string, companies: string[], franchises: string[]}  $places
     */
    protected function constrainToPlaces(Builder $query, array $places): Builder
    {
        return $query->where(function (Builder $scoped) use ($places) {
            $scoped->whereIn('assigned_franchise_id', $places['franchises'])
                ->orWhereIn('assigned_company_id', $places['companies']);

            if ($places['organization'] !== null) {
                $scoped->orWhere('assigned_organization_id', $places['organization']);
            }
        });
    }

    /**
     * Should this view also count people who are not assigned anywhere?
     *
     * HrPeopleController::visibleTo shows assignment-less people to somebody
     * standing at the organization, and the Records tab is the list this
     * dashboard is supposed to be a summary of. If the two disagreed, the first
     * thing anybody would do is count the rows in Records and conclude the
     * dashboard was broken — so the rule is copied from there rather than
     * invented here.
     *
     * Nothing creates such a person today: ProvisionOrganization assigns the
     * founder and acceptance assigns everybody else. It is guarded anyway
     * because "nothing creates one" is a statement about today's code, and the
     * cost of being wrong is a person who exists in one list and not the other.
     */
    protected function countsUnplaced(array $places): bool
    {
        return $places['organization'] !== null;
    }

    /**
     * What the places in view are CALLED, for a row somebody reads.
     *
     * ── A name on its own is not an answer ───────────────────────
     *
     * This returned the bare `name` of each place, and on the first real
     * organization that produced a headcount panel listing **Connecticut** four
     * times and **DMV** twice — six rows naming four different places, with
     * nothing to tell them apart. They are four distinct franchises belonging
     * to A-1 Chimney, Everdoors, Premier Garage Repair and Speedy Garage Doors.
     * A franchise name is unique within its company and nowhere else, and a
     * flat list throws away the one thing that made it unique.
     *
     * The app had already answered this, which is why the answer here matches.
     * ScopePickerPanel's docblock: *"two companies each operating a
     * Connecticut. Typing 'conn' shows both, each under the company that owns
     * it, which is exactly the question somebody has at that moment."* It shows
     * the ancestry whenever the tree's indentation is not there to say it. On a
     * dashboard row there is no indentation at all, so the ancestry is always
     * shown.
     *
     * Every franchise carries its company, not only the ambiguous ones. Two
     * rows formatted differently for a reason the reader cannot see is a worse
     * list than two rows formatted the same — and "is this name unique" is a
     * question whose answer changes the next time somebody opens a branch.
     *
     * A sub company gets the same treatment for the same reason: §3 allows one
     * under another, so two can share a name under different parents.
     *
     * ── Sorted by the whole path, not the leaf ───────────────────
     *
     * A list ordered by franchise name interleaves the companies — Connecticut,
     * Connecticut, Connecticut, DMV, DMV — which is precisely the reading that
     * looked broken. Ordered by the qualified name, each company's places sit
     * together.
     */
    protected function placeNames(array $places, User $user): array
    {
        $names = [];

        if ($places['organization'] !== null) {
            $names[$places['organization']] = $user->organization->name;
        }

        // Every company in the organization, not only the ones in view: a
        // franchise in view needs its parent's NAME even when that parent holds
        // nobody and has no row of its own.
        $companies = Company::query()->get(['id', 'name', 'parent_company_id']);
        $named     = $companies->keyBy('id');

        $qualify = function (?string $companyId, string $leaf) use ($named): string {
            $parent = $companyId === null ? null : $named->get($companyId);

            // "Company · Branch", the app's one way to name a branch (Jose's
            // branch-label rule; desktop review 2026-11-13 found "›" here).
            return $parent === null ? $leaf : $parent->name.' · '.$leaf;
        };

        foreach ($companies->whereIn('id', $places['companies']) as $company) {
            $names[$company->getKey()] = $qualify($company->parent_company_id, $company->name);
        }

        foreach (Franchise::query()->whereKey($places['franchises'])->get() as $franchise) {
            $names[$franchise->getKey()] = $qualify($franchise->company_id, $franchise->name);
        }

        // The organization stays first — it is the whole of the business and
        // reads as the total, not as one place among its own branches. The rest
        // sort by the name a person actually sees.
        $organization = $places['organization'];
        $head         = $organization === null ? [] : [$organization => $names[$organization]];

        unset($names[$organization]);
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $head + $names;
    }
}
