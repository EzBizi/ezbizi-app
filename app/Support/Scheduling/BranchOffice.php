<?php

namespace App\Support\Scheduling;

use App\Models\BusinessLocation;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\WebLocation;
use App\Models\ZipCentroid;
use Illuminate\Support\Collection;

/**
 * Where a service area's branch physically is (Jose, 2026-11-09: "a lot of
 * dispatchers do not know where our physical locations are"). The most
 * specific address on file, so each branch shows its own office rather than
 * one company headquarters:
 *
 *   1. the Business Profile listing filed on the branch, with a street;
 *   2. a website location whose ZIP lies inside the area;
 *   3. the company's own address.
 *
 * Null when none has a street: the screen says "No address on file".
 */
final class BranchOffice
{
    /** @return array{line: string, maps_url: string, source: string}|null */
    public static function for(ServiceArea $area, ?string $zip = null): ?array
    {
        $franchise = Franchise::query()->find($area->franchise_id);

        // A branch can hold several listings (DMV: Baltimore, Washington,
        // Alexandria): the one inside the area, else the nearest to the ZIP asked.
        $zips = ServiceAreaZip::query()->where('service_area_id', $area->id)->pluck('zip');
        $listings = BusinessLocation::query()->where('franchise_id', $area->franchise_id)
            ->whereNull('not_listed_since')->whereNotNull('address_line1')->orderBy('remote_name')->get();
        $listing = $listings->first(fn (BusinessLocation $b) => $zips->contains(substr((string) $b->postal_code, 0, 5)))
            ?? self::nearest($listings, $zip ?? $zips->first());
        if ($listing !== null) {
            return self::shape($listing->addressLine(), 'google_business_profile');
        }

        $site = $franchise === null ? null : WebLocation::query()->where('company_id', $franchise->company_id)
            ->whereNotNull('address_line1')->whereIn('postal_code', $zips)->first();
        if ($site !== null) {
            return self::shape(self::line([$site->address_line1, $site->address_line2, $site->city, trim($site->state.' '.$site->postal_code)]), 'website');
        }

        $company = $franchise === null ? null : Company::query()->find($franchise->company_id);
        if ($company !== null && trim((string) $company->address_line1) !== '') {
            return self::shape(self::line([$company->address_line1, $company->address_line2, $company->city, trim($company->state.' '.$company->postal_code)]), 'company');
        }

        return null;
    }

    /**
     * The listing closest to $zip's centre, straight-line; the first when
     * either side has no known centre.
     *
     * @param  Collection<int, BusinessLocation>  $listings
     */
    private static function nearest($listings, ?string $zip): ?BusinessLocation
    {
        $from = $zip === null ? null : ZipCentroid::locate(substr($zip, 0, 5));
        if ($from === null) {
            return $listings->first();
        }

        return $listings->sortBy(function (BusinessLocation $b) use ($from) {
            $at = $b->postal_code === null ? null : ZipCentroid::locate(substr((string) $b->postal_code, 0, 5));

            return $at === null ? INF : ($at->latitude - $from->latitude) ** 2 + ($at->longitude - $from->longitude) ** 2;
        })->first();
    }

    /** @param  array<int, ?string>  $parts */
    private static function line(array $parts): string
    {
        return collect($parts)->map(fn ($p) => trim((string) $p))->filter()->implode(', ');
    }

    /** @return array{line: string, maps_url: string, source: string} */
    private static function shape(string $line, string $source): array
    {
        return ['line' => $line, 'maps_url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($line), 'source' => $source];
    }
}
