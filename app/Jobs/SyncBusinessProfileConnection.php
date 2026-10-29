<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Leads\BusinessProfileLocations;
use App\Services\Reviews\BusinessProfileReviews;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A Business Profile just connected (or reconnected) reads its listings and
 * reviews at once, adopting what a disconnected account left behind, instead
 * of waiting for the nightly sync or a "Check on Google" (Jose, 2026-10-29).
 * The screen says "Syncing…" while SYNCING_KEY is set.
 */
final class SyncBusinessProfileConnection implements ShouldQueue
{
    use Queueable;

    public const SYNCING_KEY = 'gbp-syncing:';

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public readonly string $connectionId) {}

    /** Queue it and raise the screen's "Syncing…" flag; the job lowers it. */
    public static function start(SocialConnection $connection): void
    {
        Cache::put(self::SYNCING_KEY.$connection->getKey(), true, now()->addMinutes(15));
        self::dispatch((string) $connection->getKey())->afterCommit();
    }

    public function handle(TenantContext $tenant, BusinessProfileLocations $locations, BusinessProfileReviews $reviews): void
    {
        try {
            $connection = SocialConnection::query()->withoutGlobalScopes()
                ->where('provider', SocialProvider::GoogleBusinessProfile->value)->find($this->connectionId);
            $organization = $connection ? Organization::query()->withoutGlobalScopes()->find($connection->organization_id) : null;
            if ($organization === null) {
                return; // Disconnected before this ran.
            }

            $tenant->run($organization, function () use ($connection, $locations, $reviews) {
                $connection = SocialConnection::query()->findOrFail($connection->getKey());
                $locations->sync($connection);
                try {
                    $reviews->sync($connection);
                } catch (Throwable $e) {
                    // Listings stand on their own; reviews wait for Google's v4 (403 until released).
                    report($e);
                }
            });
        } finally {
            Cache::forget(self::SYNCING_KEY.$this->connectionId);
        }
    }
}
