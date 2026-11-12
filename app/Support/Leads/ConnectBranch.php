<?php

namespace App\Support\Leads;

use App\Http\Controllers\Api\LeadsController;
use App\Models\BusinessLocation;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;

/**
 * Which branch a lead account's enquiries belong to, chosen at connect (Jose,
 * 2026-11-12: "doing a connection should take care of the whole process").
 * Only an answer with nothing to weigh is taken; the rest is left for a person
 * in Leads › Accounts, and the Integrations card says so (needsBranch).
 *   1. connected from inside one branch: that branch;
 *   2. the sign-in's company (or organization) has exactly one branch;
 *   3. Google Ads: the one listing AccountProfileLink would link, if it has a branch.
 */
final class ConnectBranch
{
    /** Providers whose leads carry the account's branch (LocalServicesLeads, ThumbtackLeads). */
    public const PROVIDERS = [SocialProvider::GoogleAds->value, SocialProvider::Thumbtack->value];

    public static function choose(SocialConnection $c): ?string
    {
        if ($c->scope_type === SocialConnection::SCOPE_FRANCHISE && $c->scope_id !== null) {
            return (string) $c->scope_id;
        }

        $branches = Franchise::query()
            ->when($c->scope_type === SocialConnection::SCOPE_COMPANY, fn ($q) => $q->where('company_id', $c->scope_id))
            ->pluck('id');
        if ($branches->count() === 1) {
            return (string) $branches->first();
        }

        if ($c->provider === SocialProvider::GoogleAds->value) {
            $location = AccountProfileLink::match($c, BusinessLocation::query()->get());

            return $location?->franchise_id !== null && $branches->contains($location->franchise_id)
                ? (string) $location->franchise_id
                : null;
        }

        return null;
    }

    /** Set it when nobody has; never replaces a person's choice. */
    public static function apply(SocialConnection $c): void
    {
        if (! in_array($c->provider, self::PROVIDERS, true) || $c->is_manager || $c->leads_franchise_id !== null) {
            return;
        }

        $branch = self::choose($c);
        if ($branch !== null) {
            $c->forceFill(['leads_franchise_id' => $branch])->save();
        }
    }

    /** The Integrations card's line: what is wrong and what to do, or null. */
    public static function detail(SocialConnection $c): ?string
    {
        $lines = array_filter([
            // The Leads screen's "Not fetching", on the card too (desk audit, 2026-11-12).
            LeadsController::syncFailing($c) ? 'Not fetching: no read has worked for hours. Reconnect Google.' : null,
            self::needsBranch($c),
        ]);

        return $lines === [] ? null : implode(' ', $lines);
    }

    /** The card's plain instruction while its leads arrive with no branch. */
    public static function needsBranch(SocialConnection $c): ?string
    {
        if (! in_array($c->provider, self::PROVIDERS, true) || $c->is_manager || $c->leads_franchise_id !== null
            || $c->scope_type === SocialConnection::SCOPE_FRANCHISE || ! Franchise::query()->exists()) {
            return null;
        }

        return 'No branch: its leads arrive unassigned. Choose one in Leads › Accounts.';
    }
}
