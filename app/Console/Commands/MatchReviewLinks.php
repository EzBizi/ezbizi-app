<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\CustomerExperience\ReviewLinkMatcher;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Matches new Google reviews to the review links that probably produced them (Jose, 2026-11-09). */
class MatchReviewLinks extends Command
{
    protected $signature = 'ezbizi:review-link-matches {--since= : backfill from this date; default the last 3 days}';

    protected $description = 'Match Google reviews to the review links sent before them';

    public function handle(TenantContext $tenant, ReviewLinkMatcher $matcher): int
    {
        $since = $this->option('since') ? CarbonImmutable::parse($this->option('since')) : CarbonImmutable::now()->subDays(3);
        Organization::query()->each(function (Organization $org) use ($tenant, $matcher, $since) {
            $n = $tenant->run($org, fn () => $matcher->run($since));
            $n > 0 && $this->line("{$org->name}: {$n} reviews matched to a review link");
        });

        return self::SUCCESS;
    }
}
