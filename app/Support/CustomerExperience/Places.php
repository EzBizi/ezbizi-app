<?php

namespace App\Support\CustomerExperience;

use App\Models\BusinessLocation;
use App\Models\Complaint;
use App\Models\Review;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where Customer Experience's records sit in organization → company → branch
 * (Jose, 2026-10-22: "Customer Experience entire tab is also not respecting org
 * - company - franchise ownership").
 *
 * A listing is filed at its company and, once somebody says so, its branch; its
 * reviews and their complaints sit where the listing sits. Every list here is
 * cut twice: to what the person may reach, and to the place being viewed.
 */
final class Places
{
    /**
     * Reach, then the current view, on any table with company_id and franchise_id.
     * A row at the organization (no company, no branch) belongs to everybody who
     * can see the tab; a company-wide row to a company view and to its branches.
     */
    public static function scope(Builder $query, User $user): Builder
    {
        $t = $query->getModel()->getTable();

        if (! $user->canActOnOrganization($user->organization)) {
            $companies = $user->reachableCompanies()->pluck('id')->all();
            $franchises = $user->reachableFranchises()->pluck('id')->all();
            $query->where(fn ($q) => $q->whereIn("{$t}.company_id", $companies)->orWhereIn("{$t}.franchise_id", $franchises)
                ->orWhere(fn ($w) => $w->whereNull("{$t}.company_id")->whereNull("{$t}.franchise_id")));
        }

        $view = ViewScope::forUser($user);
        if (! $view->isOrganization()) {
            $query->where(fn ($q) => $q->whereIn("{$t}.franchise_id", $view->franchiseIds())
                ->orWhere(fn ($w) => $w->whereNull("{$t}.franchise_id")->whereIn("{$t}.company_id", $view->companyIds()))
                ->orWhere(fn ($w) => $w->whereNull("{$t}.company_id")->whereNull("{$t}.franchise_id")));
        }

        return $query;
    }

    /** `locations/<id>`, the part of a location's name every grant shares. */
    public static function tail(?string $name): ?string
    {
        return preg_match('~(locations/[^/]+)$~', (string) $name, $m) ? $m[1] : null;
    }

    /**
     * File a listing's reviews, and the complaints raised from them, where the
     * listing now sits. Called when a person assigns the listing a branch and by
     * the backfill. Returns [reviews moved, complaints moved].
     *
     * @return array{0: int, 1: int}
     */
    public static function fileReviewsOf(BusinessLocation $location): array
    {
        $tail = self::tail($location->remote_name);
        if ($tail === null) {
            return [0, 0];
        }
        $place = ['company_id' => $location->company_id, 'franchise_id' => $location->franchise_id];
        $reviews = Review::query()->where('location_name', 'like', '%/'.addcslashes($tail, '%_'));
        $ids = (clone $reviews)->pluck('id');

        return [
            $reviews->update($place),
            Complaint::query()->where('source_type', 'review')->whereIn('source_id', $ids)->update($place),
        ];
    }
}
