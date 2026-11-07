<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Promotion;
use App\Support\Promotions\Promotions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one promotion's texts off the request, BATCH at a time: a thousand
 * texts is minutes and the long worker stops a job at 160 s. Each batch queues
 * the next. tries = 1: a text has no idempotency key; the next batch skips
 * the phones already recorded.
 */
final class SendPromotion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public const BATCH = 100;

    public int $timeout = 150;

    public function __construct(public readonly string $promotionId)
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function handle(TenantContext $tenant, Promotions $promotions): void
    {
        $promotion = Promotion::query()->withoutGlobalScopes()->find($this->promotionId);
        $organization = $promotion === null ? null : Organization::query()->withoutGlobalScopes()->find($promotion->organization_id);
        if ($organization === null) {
            return;
        }

        if (! $tenant->run($organization, fn () => $promotions->send($promotion, self::BATCH))) {
            self::dispatch($this->promotionId);
        }
    }
}
