<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\ConnectSetup;
use App\Support\Social\Liveness\Liveness;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Ask Plaid how one bank login is (/item/get, through the card's own check)
 * and act on Plaid's answer, never on whoever asked: a webhook, or the page
 * Plaid returns to after a reconnect.
 *
 *  - alive: the card turns connected again (a reconnect or LOGIN_REPAIRED)
 *    and the bank is read now;
 *  - refused: the card says Reconnect (ConnectSetup::record marks it broken).
 *
 * Unique per connection for a minute: Plaid can send several webhooks at once.
 */
final class RecheckPlaidItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(public string $connectionId) {}

    public function uniqueId(): string
    {
        return $this->connectionId;
    }

    public function handle(TenantContext $tenant, ConnectSetup $setup): void
    {
        $c = SocialConnection::withoutGlobalScopes()->whereKey($this->connectionId)
            ->where('provider', SocialProvider::Plaid->value)->first();
        $organization = $c ? Organization::withoutGlobalScopes()->find($c->organization_id) : null;
        if ($c === null || $organization === null) {
            return;
        }

        $tenant->run($organization, function () use ($c, $setup) {
            if ($setup->runCheck($c)->verdict !== Liveness::ALIVE) {
                return;
            }
            $c->refresh();
            if ($c->status !== SocialConnection::STATUS_CONNECTED) {
                $c->forceFill(['status' => SocialConnection::STATUS_CONNECTED, 'status_reason' => null])->save();
            }
            Artisan::call('ezbizi:plaid-sync', ['--connection' => (string) $c->getKey()]);
        });
    }
}
