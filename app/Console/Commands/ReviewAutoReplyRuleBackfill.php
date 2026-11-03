<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\BusinessLocation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Reviews\ReviewAutoReplyRule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Listings switched on before the switch made its own rule (Jose, 2026-11-02:
 * 40 on, nothing posted): makes the rule for each organization that has one,
 * as the person who last switched a listing on.
 */
class ReviewAutoReplyRuleBackfill extends Command
{
    protected $signature = 'ezbizi:review-auto-reply-rule';

    protected $description = 'Make the review auto-reply rule for organizations with listings already switched on';

    public function handle(TenantContext $tenants): int
    {
        foreach (Organization::all() as $org) {
            $tenants->run($org, function () use ($org) {
                if (! BusinessLocation::query()->where('auto_reply', true)->exists()) {
                    return;
                }
                $actor = AuditEvent::query()->where('action', 'business_location.auto_reply_on')->where('actor_type', AuditEvent::ACTOR_USER)
                    ->latest('occurred_at')->value('actor_id');
                $by = $actor === null ? null : User::query()->find($actor);
                if ($by === null || ! $by->hasPermission('reviews.publish')) {
                    $this->warn("{$org->name}: nobody who switched a listing on may publish replies; skipped.");

                    return;
                }
                $rule = ReviewAutoReplyRule::ensure($by);
                $this->info("{$org->name}: rule {$rule->id} ({$rule->name}), runs as {$by->name}.");
            });
        }

        return self::SUCCESS;
    }
}
