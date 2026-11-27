<?php

namespace App\Support\Phone;

use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which company a phone thing belongs to, and who may see it.
 *
 * ── Why this class exists ────────────────────────────────────────
 *
 * Numbers were scoped from the start. Everything a number POINTS AT — ring
 * groups, opening hours, IVR menus, voicemail boxes — hung off `connection_id`
 * alone, and a connection is deliberately shared: ConnectionResolver walks
 * franchise → company → organization, so a business with one Twilio account at
 * organization level has every company resolving to the same connection.
 *
 * The result was that A-1 Chimney's ring group appeared under a sibling
 * company, because nothing in the data said whose it was. It was not a filter
 * that was missing — it was the column.
 *
 * ── One rule, in one place ───────────────────────────────────────
 *
 * The view test here is the same one PhoneNumberAccess::inView applies to
 * numbers, and that method now delegates to this class rather than keeping its
 * own copy. Five screens judging "may this company see it" separately is how
 * the answers drift apart, which this module has already demonstrated more
 * than once.
 */
final class PhoneScope
{
    public const ORGANIZATION = 'organization';

    public const COMPANY = 'company';

    public const FRANCHISE = 'franchise';

    /**
     * Where a new row belongs: the place the person is standing.
     *
     * Filing on the VIEW, not on reach. Somebody who can act on three
     * companies is still only looking at one, and a ring group made while
     * looking at A-1 Chimney belongs to A-1 Chimney.
     *
     * @return array{0: string, 1: string}
     */
    public static function filing(User $user): array
    {
        $view = ViewScope::forUser($user);

        return match (true) {
            $view->isOrganization() => [self::ORGANIZATION, (string) $user->organization_id],
            $view->type === ViewScope::COMPANY => [self::COMPANY, (string) $view->id],
            default => [self::FRANCHISE, (string) $view->id],
        };
    }

    /**
     * Whether the current view may see something filed on this scope.
     *
     * An organization view sees everything beneath it — authority flows down.
     * A company view sees its own and its franchises', and NOT the
     * organization's: authority does not flow up, and that is the same answer
     * numbers already give.
     */
    public static function visible(User $user, ?string $scopeType, ?string $scopeId): bool
    {
        $view = ViewScope::forUser($user);

        // ── Reach bounds, view narrows. BOTH, always ────────────────
        //
        // ViewScope::forUser reads a stored navigation preference and falls
        // back to an ORGANIZATION view when there is none — so somebody
        // assigned to one company, who has simply never used the switcher,
        // arrives here claiming to be looking at the whole organization.
        //
        // Filtering by view alone therefore showed that person everything.
        // Filtering by reach alone — what the old code did — showed an
        // organization admin every company's settings whichever company they
        // were standing in. Each half looks sufficient on its own and neither
        // is. This is the bug that let A-1 Chimney's ring group appear under a
        // sibling, and the reason it survived a filter being present.
        $organizationWide = $user->organization !== null
            && $user->canActOnOrganization($user->organization);

        if ($view->isOrganization() && $organizationWide) {
            return true;
        }

        // An unscoped row predates this rule. It is shown rather than hidden:
        // making existing ring groups vanish from every company would read as
        // data loss, and the backfill sets them properly anyway.
        if ($scopeType === null || $scopeId === null) {
            return true;
        }

        [$companyIds, $franchiseIds] = self::reachable($user, $view);

        return match ($scopeType) {
            self::COMPANY => in_array($scopeId, $companyIds, true),
            self::FRANCHISE => in_array($scopeId, $franchiseIds, true),

            // The organization's own settings are not a company's to read.
            // Authority flows down, never up — the same answer numbers give.
            default => false,
        };
    }

    /**
     * The companies and franchises this request may see: reach ∩ view.
     *
     * At an explicit company or franchise view, the view IS the answer — it
     * was validated against reach when it was stored. With no view chosen,
     * reach is the answer, because "organization view" for somebody who can
     * only touch one company means that company, not everything.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function reachable(User $user, ViewScope $view): array
    {
        if (! $view->isOrganization()) {
            return [$view->companyIds(), $view->franchiseIds()];
        }

        return [
            $user->reachableCompanies()->pluck('id')->all(),
            $user->reachableFranchises()->pluck('id')->all(),
        ];
    }

    /**
     * Restrict a query to what the current view may see.
     *
     * Applied in SQL rather than by filtering a fetched collection, because
     * these lists grow with the business and "read everything, then discard
     * most of it" is a habit that only shows up as a problem once somebody
     * has a hundred lines.
     */
    public static function apply(Builder $query, User $user): Builder
    {
        $view = ViewScope::forUser($user);

        $organizationWide = $user->organization !== null
            && $user->canActOnOrganization($user->organization);

        if ($view->isOrganization() && $organizationWide) {
            return $query;
        }

        [$companyIds, $franchiseIds] = self::reachable($user, $view);

        return $query->where(function (Builder $q) use ($companyIds, $franchiseIds) {
            // Pre-scope rows, kept visible for the reason given in visible().
            $q->whereNull('scope_type');

            if ($companyIds !== []) {
                $q->orWhere(fn (Builder $w) => $w
                    ->where('scope_type', self::COMPANY)
                    ->whereIn('scope_id', $companyIds));
            }

            if ($franchiseIds !== []) {
                $q->orWhere(fn (Builder $w) => $w
                    ->where('scope_type', self::FRANCHISE)
                    ->whereIn('scope_id', $franchiseIds));
            }
        });
    }

    /**
     * The PEOPLE who work in the company being viewed: assigned to the whole
     * organization, to a company in the view, or to a branch of one, plus
     * Administrators. Jose, 2026-11-11: Communication › Users for EverDoors
     * listed A-1 Chimney's staff. The organization view lists everybody.
     */
    public static function people(Builder $users, User $viewer): Builder
    {
        return self::peopleIn($users, ViewScope::forUser($viewer)->companyIdsInView());
    }

    /** The same rule for a set of companies; null is the whole organization. */
    public static function peopleIn(Builder $users, ?array $companyIds): Builder
    {
        if ($companyIds === null) {
            return $users;
        }

        return $users->where(fn (Builder $q) => $q
            ->whereHas('role', fn (Builder $r) => $r->where('is_administrator', true))
            ->orWhereHas('assignments', fn (Builder $a) => $a
                ->whereNotNull('assigned_organization_id')
                ->orWhereIn('assigned_company_id', $companyIds)
                ->orWhereIn('assigned_franchise_id', \App\Models\Franchise::query()->whereIn('company_id', $companyIds)->select('id'))));
    }
}
