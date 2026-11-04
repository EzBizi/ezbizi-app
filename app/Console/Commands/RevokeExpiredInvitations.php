<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\Organization;
use App\Support\Hr\InvitationActions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Invitations past their expiry, never accepted, are withdrawn (Jose,
 * 2026-11-03: "yes" to revoking expired invitations automatically). The same
 * path the Revoke button takes, so it is audited the same way. Held ones wait
 * for the employer's signature and are not counted as expired.
 */
class RevokeExpiredInvitations extends Command
{
    protected $signature = 'ezbizi:revoke-expired-invitations {--dry-run : Count without withdrawing}';

    protected $description = 'Withdraw invitations that expired without being accepted';

    public function handle(TenantContext $tenant, InvitationActions $actions): int
    {
        $total = 0;
        foreach (Organization::query()->get() as $organization) {
            $total += $tenant->run($organization, function () use ($actions) {
                $expired = Invitation::query()->whereNull('accepted_at')->whereNull('revoked_at')
                    ->whereNull('held_at')->where('expires_at', '<', now())->get();
                if (! $this->option('dry-run')) {
                    $expired->each(fn (Invitation $i) => $actions->revoke($i));
                }

                return $expired->count();
            });
        }
        $this->info(($this->option('dry-run') ? 'Would withdraw ' : 'Withdrew ').$total.' expired invitations.');

        return self::SUCCESS;
    }
}
