<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Leads\BusinessProfileLocations;
use App\Services\Reviews\BusinessProfileReviews;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Business Profile READS on a schedule: each connected profile's locations
 * (with whether Google is showing them) and, with --reviews, its reviews. A new
 * review raises review.received with its rating - the low-star alert is a
 * rule on that. Nothing is written to Google here: A-1's replies and posts
 * stay with Rodrigo's server until it is agreed which side owns each
 * (2026-10-15), so there is never a second writer.
 *
 * ponytail: a full review read each run (A-1 holds ~13,000, ~270 pages), so
 * every six hours; read only what changed once that cost matters.
 */
class GbpSync extends Command
{
    protected $signature = 'ezbizi:gbp-sync {--reviews : also read every review}';

    protected $description = 'Read connected Business Profiles: locations, and reviews with --reviews';

    public function handle(BusinessProfileLocations $locations, BusinessProfileReviews $reviews, TenantContext $tenant): int
    {
        $rows = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::GoogleBusinessProfile->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->where('remote_id', 'not like', 'DEMO-%')   // the seeded demo has no real grant
            ->get();

        foreach ($rows as $row) {
            try {
                $tenant->run(Organization::withoutGlobalScopes()->findOrFail($row->organization_id), function () use ($row, $locations, $reviews) {
                    $connection = SocialConnection::query()->findOrFail($row->getKey());
                    $l = $locations->sync($connection);
                    $line = "{$connection->display_name}: {$l['fetched']} location(s)";
                    if ($this->option('reviews')) {
                        $r = $reviews->sync($connection);
                        $line .= ", {$r['fetched']} review(s), {$r['created']} new";
                    }
                    $this->line($line);
                });
            } catch (Throwable $e) {
                report($e);
                $this->warn($row->display_name.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
