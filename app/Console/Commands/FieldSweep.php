<?php

namespace App\Console\Commands;

use App\Models\FieldPresence;
use App\Models\Job;
use App\Models\Organization;
use App\Support\Field\FieldEvaluator;
use App\Support\Field\FieldJobs;
use App\Support\Field\FieldMaps;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Field's clock: lost signal (the one fact no incoming point can announce),
 * and geocoding the next two days' job addresses so arrival checks on the
 * ingest path never wait on Google.
 */
class FieldSweep extends Command
{
    protected $signature = 'ezbizi:field-sweep';

    protected $description = 'Raise Field lost-signal alerts and geocode upcoming job addresses';

    /** Google calls per run, across every tenant. */
    private const GEOCODE_BUDGET = 50;

    /** Two weeks ahead: Scheduling's per-technician routes read these pins too (2026-10-14). */
    private const GEOCODE_DAYS = 14;

    public function handle(TenantContext $tenant, FieldEvaluator $evaluator, FieldJobs $jobs, FieldMaps $maps): int
    {
        $silent = $tenant->runWithoutTenant(fn () => FieldPresence::query()
            ->withoutGlobalScopes()
            ->where('signal_lost_alerted', false)
            ->where('last_seen_at', '<=', now()->subMinutes(5)) // the shortest option
            ->with('user.organization')
            ->get());

        $raised = 0;
        foreach ($silent as $presence) {
            $organization = $presence->user?->organization;

            if ($organization !== null) {
                $raised += (int) $tenant->run($organization, fn () => $evaluator->checkSilence($presence));
            }
        }

        $geocoded = 0;
        if ($maps->configured()) {
            $budget = self::GEOCODE_BUDGET;

            $upcoming = $tenant->runWithoutTenant(fn () => Job::query()
                ->withoutGlobalScopes()
                ->whereBetween('scheduled_for', [now()->subDay()->toDateString(), now()->addDays(self::GEOCODE_DAYS)->toDateString()])
                ->pluck('organization_id', 'id'));

            foreach ($upcoming->unique()->values() as $organizationId) {
                $organization = Organization::query()->find($organizationId);

                $tenant->run($organization, function () use ($upcoming, $organizationId, $jobs, &$budget, &$geocoded) {
                    $ids = $upcoming->filter(fn ($org) => $org === $organizationId)->keys();

                    foreach (Job::query()->with('customer')->whereIn('id', $ids)->get() as $job) {
                        if ($budget <= 0) {
                            return;
                        }

                        if ($jobs->needsGeocode($job)) {
                            $jobs->pointFor($job, ask: true);
                            $budget--;
                            $geocoded++;
                        }
                    }
                });
            }
        }

        if ($raised + $geocoded > 0) {
            $this->info("Field: {$raised} lost-signal alert(s), {$geocoded} address(es) geocoded.");
        }

        return self::SUCCESS;
    }
}
