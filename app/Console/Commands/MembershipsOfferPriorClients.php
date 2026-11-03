<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * The membership offer to PRIOR clients (Jose's project list: "Option to auto
 * text prior clients to enroll"). It raises `customer.membership_offer` for each
 * chosen customer; the organization's automation on that trigger decides the
 * channel and the words, as every other EzBizi message does.
 *
 * Dry-run unless --send: it counts and prints, and raises nothing. With --send
 * it raises at most --limit events, --per-minute at a time. A customer offered
 * in the last --skip-days, or who is already a member, is skipped.
 */
class MembershipsOfferPriorClients extends Command
{
    public const TRIGGER = 'customer.membership_offer';

    protected $signature = 'memberships:offer-prior-clients
        {organization : organization id}
        {--since-months=12 : had a job in the last N months}
        {--limit=100 : most customers to offer in this run}
        {--per-minute=30 : pace}
        {--skip-days=365 : not offered again within N days}
        {--send : actually raise the offers; without it, nothing happens}';

    protected $description = 'Offer the membership to prior clients (dry-run unless --send)';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $organization = Organization::query()->withoutGlobalScopes()->findOrFail($this->argument('organization'));

        return $tenant->run($organization, function () use ($events) {
            if (! MembershipPlan::query()->where('is_active', true)->exists()) {
                $this->error('No active plan, so there is nothing to offer.');

                return self::FAILURE;
            }

            $query = Customer::query()
                ->whereNotNull('phone')->where('phone', '!=', '')
                ->whereHas('jobs', fn ($q) => $q->where('scheduled_for', '>=', now()->subMonths((int) $this->option('since-months')))
                    ->where('scheduled_for', '<', now()))
                ->whereNotIn('id', Membership::query()->whereNull('cancelled_at')
                    ->where('ends_on', '>=', now()->toDateString())->select('customer_id'))
                ->whereNotIn('id', \App\Models\AutomationEvent::query()->where('event_key', self::TRIGGER)
                    ->where('occurred_at', '>=', now()->subDays((int) $this->option('skip-days')))->select('subject_id'))
                ->orderByDesc('updated_at');

            $total = (clone $query)->count();
            $this->line("Eligible: {$total}. This run: ".min($total, (int) $this->option('limit')).'.');

            if (! $this->option('send')) {
                $this->line('Dry run: nothing was raised. Add --send to offer.');

                return self::SUCCESS;
            }

            $pace = max(1, (int) $this->option('per-minute'));
            foreach ($query->limit((int) $this->option('limit'))->get() as $i => $customer) {
                if ($i > 0 && $i % $pace === 0) {
                    sleep(60);
                }
                $events->emit(self::TRIGGER, $customer, ['after' => ['source' => 'prior_client']]);
            }
            $this->line('Raised.');

            return self::SUCCESS;
        });
    }
}
