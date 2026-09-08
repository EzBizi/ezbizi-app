<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Leads\LocalServicesLeads;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches Local Services enquiries for every connected account, on a schedule.
 *
 * ── Why this exists at all ───────────────────────────────────────
 *
 * Until this, leads arrived only when somebody pressed Fetch new. A screen that
 * shows enquiries is worth having only if it is current when nobody is looking
 * at it: the person who needs the lead is on the phone, not on this tab.
 *
 * ── Five minutes is a quota decision ─────────────────────────────
 *
 * Basic Access is 15,000 operations a day, and that ceiling belongs to the
 * Google Cloud PROJECT — one number shared by every tenant EzBizi will ever
 * have, not one per customer. Per-account polling costs 1,440 calls per account
 * per day at one minute, so the cadence is really a budget for how many
 * accounts the whole platform can carry:
 *
 *   1 min   1,440/account/day    10 accounts
 *   5 min     288/account/day    52 accounts
 *  15 min      96/account/day   156 accounts
 *
 * A-1 Chimney alone connected 22 accounts in one action, so a one-minute
 * cadence was already 2.1x over the ceiling before a second customer existed.
 * Five minutes is 6,334 calls a day at present — 42% — which leaves room for
 * somebody pressing Fetch new and for Reviews when its own approval lands.
 *
 * Tiering busy accounts fast and quiet ones slow is the right eventual answer,
 * because it decouples cost from account count in a way a flat cadence cannot.
 * It is the wrong first one: Google grants quota increases on demonstrated
 * need, and five minutes across a real fleet is what builds that record.
 */
class SyncLeads extends Command
{
    protected $signature = 'ezbizi:sync-leads';

    protected $description = 'Fetch Local Services enquiries for every connected Google Ads account';

    public function __construct(
        private readonly LocalServicesLeads $localServices,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /*
         * Without tenant scope, then back INTO each tenant to do the work.
         *
         * The scheduler is nobody: there is no request and no user, so the
         * global scope would filter every connection to nothing and this would
         * report a clean run having fetched from no one. The same shape as
         * PublishDueSocialPosts — find the work unscoped, enter the tenant per
         * item, and let the boundary be enforced there.
         *
         * And deliberately NO inView() filter. That answers "what may this
         * person see", which is a question about a request. A scheduled sweep
         * legitimately reaches every connected account; borrowing the request
         * filter here would sync nothing at all.
         */
        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->get();

        if ($connections->isEmpty()) {
            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())
            ->get()
            ->keyBy(fn (Organization $o) => $o->getKey());

        $fetched  = 0;
        $messages = 0;
        $failed   = 0;

        foreach ($connections as $connection) {
            $organization = $organizations->get($connection->organization_id);

            if ($organization === null) {
                continue;
            }

            try {
                $result = $this->tenant->run(
                    $organization,
                    fn () => $this->localServices->sync($connection),
                );

                $fetched  += $result['fetched'];
                $messages += $result['conversations']['created'];
            } catch (Throwable $e) {
                /*
                 * One account must not stop the other twenty-one. A suspended
                 * account, a revoked grant and a customer who cancelled all
                 * throw here, and none of them is a reason to leave every other
                 * tenant's enquiries unfetched until somebody notices.
                 *
                 * The connection id, never the token: this line is written on a
                 * path holding three credentials.
                 */
                $failed++;

                Log::warning('Scheduled lead sync failed for one connection.', [
                    'connection_id' => $connection->getKey(),
                    'provider'      => $connection->provider,
                    'reason'        => $e->getMessage(),
                ]);
            }
        }

        $this->info("Synced {$connections->count()} connection(s): {$fetched} lead(s), {$messages} new call(s)/message(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
