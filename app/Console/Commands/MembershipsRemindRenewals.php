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

    public const FOLLOW_UP = 'membership.visit_unconfirmed';


    protected $signature = 'memberships:remind-renewals';

    protected $description = 'Raise membership.renewal_due for memberships ending within a month';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        // ponytail: every organization with a membership not cancelled;
        // narrow it when there are thousands of organizations.
        $ids = Membership::withoutGlobalScopes()->whereNull('cancelled_at')
            ->distinct()->pluck('organization_id');

        foreach (Organization::query()->whereIn('id', $ids)->get() as $organization) {
            $tenant->run($organization, function () use ($events, $organization) {
                // Jose, 11-04: these are the organization's settings, not constants.
                $cutoff = CarbonImmutable::today()->addDays(Membership::setting('customers.membership_reminder_days', $organization->id))->toDateString();
                $followUpDays = Membership::setting('customers.membership_follow_up_days', $organization->id);

                $due = Membership::query()->with('plan')
                    ->whereNull('reminded_at')->whereNull('cancelled_at')
                    ->where('ends_on', '<=', $cutoff)->where('ends_on', '>=', CarbonImmutable::today()->toDateString())
                    ->get();

                foreach ($due as $membership) {
                    $membership->forceFill(['reminded_at' => now()])->save();
                    $events->emit(self::TRIGGER, $membership, ['after' => MembershipsController::payload($membership)]);
                }

                $this->line($due->count().' reminded');

                // Jose: no answer leaves staff a follow-up, once, while no
                // visit is booked.
                $silent = Membership::query()->with('plan')
                    ->whereNotNull('reminded_at')->where('reminded_at', '<=', now()->subDays($followUpDays))
                    ->whereNull('visit_job_id')->whereNull('followed_up_at')->whereNull('cancelled_at')
                    ->get();

                foreach ($silent as $membership) {
                    $membership->forceFill(['followed_up_at' => now()])->save();
                    $events->emit(self::FOLLOW_UP, $membership, ['after' => MembershipsController::payload($membership)]);
                }
            });
        }

        return self::SUCCESS;
    }
}
