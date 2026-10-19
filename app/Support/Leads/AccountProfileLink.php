<?php

namespace App\Support\Leads;

use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialProvider;

/**
 * Which Business Profile listing each Local Services (Google Ads) account
 * serves from (A1 Leads takeover, 2026-10-19). The listing is the office
 * address; when the listing moves to another city, the account's name follows.
 *
 * A1 Leads matched by scraping Google's pages; EzBizi reads only what it
 * already syncs through the official APIs, so the automatic link is
 * conservative - it links only when exactly one listing fits, and a person
 * sets the rest:
 *   1. the account's branch (leads_franchise_id) has exactly one listing;
 *   2. the city in the account's name ("A1 Chimney - Austin") is exactly one
 *      listing's city, in the same company.
 * A person's choice (business_location_by_user_id) is never replaced.
 */
final class AccountProfileLink
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Link what can be linked, rename what moved; inside the tenant. Returns [linked, renamed]. */
    public function run(): array
    {
        $accounts = SocialConnection::query()->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)->get();
        $locations = BusinessLocation::query()->get();
        $linked = $renamed = 0;

        foreach ($accounts as $account) {
            if ($account->business_location_id === null) {
                $match = self::match($account, $locations);
                if ($match !== null) {
                    $this->link($account, $match, null);
                    $linked++;
                }

                continue;
            }
            if ($this->renameOnMove($account, $locations->firstWhere('id', $account->business_location_id))) {
                $renamed++;
            }
        }

        return [$linked, $renamed];
    }

    /** A person's choice, or null to clear it (the automatic link may then try again). */
    public function link(SocialConnection $account, ?BusinessLocation $location, ?User $by): void
    {
        $before = $account->business_location_id;
        $account->forceFill([
            'business_location_id' => $location?->getKey(),
            'business_location_by_user_id' => $location !== null ? $by?->getKey() : null,
            'business_location_city' => $location?->city,
        ])->save();
        $this->audit->log('social_connection.business_location_linked', $account,
            ['business_location_id' => ['from' => $before, 'to' => $location?->getKey()]],
            ['by' => $by === null ? 'automatic' : 'person'], actor: $by);
    }

    /** @param  \Illuminate\Support\Collection<int, BusinessLocation>  $locations */
    public static function match(SocialConnection $account, $locations): ?BusinessLocation
    {
        if ($account->leads_franchise_id !== null) {
            $inBranch = $locations->where('franchise_id', $account->leads_franchise_id);
            if ($inBranch->count() === 1) {
                return $inBranch->first();
            }
        }

        $city = self::cityOf((string) ($account->label ?: $account->display_name));
        if ($city === null) {
            return null;
        }
        $company = $account->scope_type === SocialConnection::SCOPE_COMPANY ? $account->scope_id : null;
        $same = $locations->filter(fn (BusinessLocation $l) => mb_strtolower(trim((string) $l->city)) === $city
            && ($company === null || $l->company_id === $company));

        return $same->count() === 1 ? $same->first() : null;
    }

    /**
     * The listing moved to another city: "A1 Chimney - Old" becomes "A1 Chimney - New"
     * in the account's EzBizi name (label), audited. Only a name that ends in the
     * old city is touched.
     */
    private function renameOnMove(SocialConnection $account, ?BusinessLocation $location): bool
    {
        $old = trim((string) $account->business_location_city);
        $new = trim((string) $location?->city);
        if ($location === null || $new === '' || $old === '' || mb_strtolower($old) === mb_strtolower($new)) {
            return false;
        }
        $name = (string) ($account->label ?: $account->display_name);
        $renamed = preg_replace('/(\s[-–—]\s)'.preg_quote($old, '/').'$/iu', '${1}'.$new, $name);
        $account->forceFill(['business_location_city' => $new]
            + ($renamed !== null && $renamed !== $name ? ['label' => $renamed] : []))->save();
        if ($renamed !== null && $renamed !== $name) {
            $this->audit->log('social_connection.renamed_on_move', $account, ['label' => ['from' => $name, 'to' => $renamed]]);

            return true;
        }

        return false;
    }

    /** "A1 Chimney - Austin" => "austin". */
    private static function cityOf(string $name): ?string
    {
        $parts = preg_split('/\s[-–—]\s/u', $name);
        $last = count($parts) > 1 ? mb_strtolower(trim((string) end($parts))) : '';

        return $last === '' ? null : $last;
    }
}
