<?php

namespace App\Console\Commands;

use App\Models\BusinessLocation;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\ServiceAreaZip;
use App\Support\Audit\AuditLogger;
use App\Support\CustomerExperience\Places;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Files Customer Experience's records at their branch (Jose, 2026-10-22).
 *
 * 1. A listing with no branch gets the one branch whose service areas hold its
 *    ZIP - only when exactly one does. Anything else is left for a person to
 *    assign under Business Profile, and listed.
 * 2. Every listing with a branch files its reviews, and their complaints, there.
 *
 * Never moves a listing a person already assigned. --dry-run says what it would
 * do and writes nothing. Take a dump first: it rewrites company/branch columns.
 */
class CustomerExperiencePlaces extends Command
{
    protected $signature = 'ezbizi:cx-places {organization} {--dry-run}';

    protected $description = 'Give listings their branch by ZIP, and file reviews and complaints where their listing sits';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $dry = (bool) $this->option('dry-run');
        $organization = Organization::query()->findOrFail($this->argument('organization'));

        $tenant->run($organization, function () use ($dry, $audit) {
            $assigned = [];
            $left = [];

            foreach (BusinessLocation::query()->whereNull('franchise_id')->orderBy('title')->get() as $location) {
                $zip = substr((string) preg_replace('/\D/', '', (string) $location->postal_code), 0, 5);
                $franchises = $zip === '' ? collect() : ServiceAreaZip::query()
                    ->join('service_areas', 'service_areas.id', '=', 'service_area_zips.service_area_id')
                    ->where('service_area_zips.zip', $zip)->distinct()->pluck('service_areas.franchise_id');
                $franchise = $franchises->count() === 1 ? Franchise::query()->find($franchises[0]) : null;

                $label = trim("{$location->title} {$location->city} {$location->state}");
                if ($franchise === null) {
                    $left[] = [$label, $zip === '' ? 'no address' : ($franchises->isEmpty() ? "ZIP {$zip} in no branch" : "ZIP {$zip} in several")];

                    continue;
                }
                $assigned[] = [$label, $franchise->name];
                if (! $dry) {
                    $location->forceFill(['franchise_id' => $franchise->id, 'company_id' => $franchise->company_id])->save();
                    $audit->log('business_location.franchise_set', $location, metadata: ['by' => 'backfill', 'zip' => $zip]);
                }
            }

            $reviews = 0;
            $complaints = 0;
            if (! $dry) {
                foreach (BusinessLocation::query()->whereNotNull('franchise_id')->get() as $location) {
                    [$r, $c] = Places::fileReviewsOf($location);
                    $reviews += $r;
                    $complaints += $c;
                }
            }

            $this->info(count($assigned).' listing(s) given a branch by ZIP:');
            $this->table(['Listing', 'Branch'], $assigned);
            $this->warn(count($left).' left for a person to assign under Business Profile:');
            $this->table(['Listing', 'Why'], $left);
            $this->info($dry ? 'Dry run: nothing written.' : "Filed {$reviews} review(s) and {$complaints} complaint(s) at their listing's branch.");
        });

        return self::SUCCESS;
    }
}
