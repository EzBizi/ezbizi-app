<?php

namespace App\Support\Scheduling;

use App\Models\BusinessLocation;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\WebLocation;

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
    public static function for(ServiceArea $area): ?array
    {
        $franchise = Franchise::query()->find($area->franchise_id);

        $listing = BusinessLocation::query()->where('franchise_id', $area->franchise_id)
            ->whereNull('not_listed_since')->whereNotNull('address_line1')->first();
        if ($listing !== null) {
            return self::shape($listing->addressLine(), 'google_business_profile');
        }

        $zips = ServiceAreaZip::query()->where('service_area_id', $area->id)->pluck('zip');
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
