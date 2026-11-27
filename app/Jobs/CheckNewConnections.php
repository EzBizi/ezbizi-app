<?php

namespace App\Jobs;

use App\Console\Commands\CheckConnectionHealth;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\Liveness\ConnectionLiveness;
use App\Support\Social\Liveness\Liveness;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The health check, run once just after a connect instead of waiting for
 * tomorrow's ezbizi:check-connection-health, so the card has its verdict now
 * (Jose, 2026-11-12). Dispatched with a short delay so the connect's own setup
 * jobs (Thumbtack's webhook) use the grant first; rows are checked one after
 * another, and one Thumbtack exchange answers for its siblings, as the command
 * does, because every exchange rotates the shared grant.
 */
final class CheckNewConnections implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $ids */
    public function __construct(public array $ids) {}

    public function handle(TenantContext $tenant, ConnectionLiveness $liveness): void
    {
        $rows = SocialConnection::withoutGlobalScopes()->whereIn('id', $this->ids)
            ->where('status', SocialConnection::STATUS_CONNECTED)->get()
            ->filter(fn (SocialConnection $c) => ConnectionLiveness::checks((string) $c->provider));
        $organization = Organization::withoutGlobalScopes()->find($rows->first()?->organization_id);
        if ($organization === null) {
            return;
        }

        $tenant->run($organization, function () use ($rows, $liveness) {
            /** @var Liveness|null $thumbtack */
            $thumbtack = null;

            foreach ($rows as $c) {
                // check() reads the webhook back; a sibling sharing the renewal is read back on its own.
                $result = $c->provider === SocialProvider::Thumbtack->value && $thumbtack !== null
                    ? ConnectionLiveness::thumbtackHeard($c, $thumbtack)
                    : $liveness->check($c);
                if ($c->provider === SocialProvider::Thumbtack->value) {
                    $thumbtack ??= $result;
                }

                CheckConnectionHealth::record($c, $result);
            }
        });
    }
}
