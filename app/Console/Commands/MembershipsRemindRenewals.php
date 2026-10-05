<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\MembershipsController;
use App\Models\Membership;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Each morning, raise `membership.renewal_due` once for every live membership
 * ending within a month (Jose, 2026-10-05: "1 month before the 1 year mark,
 * the customer gets sent a reminder"). What is sent, and by text, WhatsApp or
 * email, is the organization's automation on that trigger.
 */
class MembershipsRemindRenewals extends Command
{
    public const TRIGGER = 'membership.renewal_due';

    protected $signature = 'memberships:remind-renewals';

    protected $description = 'Raise membership.renewal_due for memberships ending within a month';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $cutoff = CarbonImmutable::today()->addMonth()->toDateString();
        $ids = Membership::withoutGlobalScopes()
            ->whereNull('reminded_at')->whereNull('cancelled_at')
            ->where('ends_on', '<=', $cutoff)->where('ends_on', '>=', CarbonImmutable::today()->toDateString())
            ->distinct()->pluck('organization_id');

        foreach (Organization::query()->whereIn('id', $ids)->get() as $organization) {
            $tenant->run($organization, function () use ($events, $cutoff) {
                $due = Membership::query()->with('plan')
                    ->whereNull('reminded_at')->whereNull('cancelled_at')
                    ->where('ends_on', '<=', $cutoff)->where('ends_on', '>=', CarbonImmutable::today()->toDateString())
                    ->get();

                foreach ($due as $membership) {
                    $membership->forceFill(['reminded_at' => now()])->save();
                    $events->emit(self::TRIGGER, $membership, ['after' => MembershipsController::payload($membership)]);
                }

                $this->line($due->count().' reminded');
            });
        }

        return self::SUCCESS;
    }
}
