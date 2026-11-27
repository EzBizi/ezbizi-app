<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\ConnectionSetup;
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

    /** Null: the worker's own limit (60 s). The reviews half sets its own. */
    public ?int $timeout = null;

    /**
     * $reviews: the second half, every review of every listing. Its own job on
     * the long worker (2026-11-02: DFW's 3,018 reviews are 61 pages and about
     * 25 ms a stored row, past the default worker's 60 s; TimeoutExceeded).
     * ponytail: 190 s is under database-long's 200 s retry_after and holds
     * ~3,000 reviews a listing; past that, the 6-hourly gbp-sync --reviews
     * finishes what a connect could not.
     */
    public function __construct(public readonly string $connectionId, public readonly bool $reviews = false)
    {
        if ($reviews) {
            $this->timeout = 190;
            $this->onConnection('database-long')->onQueue('long');
        }
    }

    /** Why the last listings read failed, for the card; cleared by the next one that works. */
    public const FAILED_KEY = 'gbp-sync-failed:';

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
                if ($this->reviews) {
                    try {
                        $reviews->sync($connection);
                    } catch (Throwable $e) {
                        report($e); // As before: the 6-hourly read tries again.
                    }

                    return;
                }
                try {
                    $locations->sync($connection);
                    Cache::forget(self::FAILED_KEY.$connection->getKey());
                } catch (Throwable $e) {
                    // The card says it failed rather than quietly going back to idle (audit, 2026-11-12).
                    Cache::put(self::FAILED_KEY.$connection->getKey(), 'Reading the listings failed: '.$e->getMessage(), now()->addDay());
                    throw $e;
                }
                // Listings stand on their own; their reviews follow on the long worker.
                self::dispatch((string) $connection->getKey(), true);
            });
        } finally {
            Cache::forget(self::SYNCING_KEY.$this->connectionId);
        }
    }

    /** The card goes broken with what did not finish, not green over failed_jobs (Jose, 2026-11-12). */
    public function failed(Throwable $e): void
    {
        if ($this->reviews) {
            return; // The reviews half is retried by the 6-hourly read.
        }

        ConnectionSetup::failed($this->connectionId, 'Business Profile setup did not finish: its listings could not be read. Reconnect Google Business Profile.', $e);
    }
}
