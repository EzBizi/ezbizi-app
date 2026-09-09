<?php

namespace App\Console\Commands;

use App\Models\Lead;
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
 * Tiering busy accounts fast and quiet ones slow decouples cost from account
 * count in a way a flat cadence cannot. It was deliberately not the first
 * answer, so that five minutes across a real fleet would build the usage
 * record Google grants quota on. Google answered on 2026-09-09: Standard
 * Access is for integrations already exceeding the limit, and the fleet grew
 * to forty-five accounts the same day. So the tick is tiered now (see
 * handle()), and the record still builds honestly from what is read.
 */
class SyncLeads extends Command
{
    protected $signature = 'ezbizi:sync-leads
        {--days= : Re-read this many days back on every account, refreshing what is already held}';

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
        $rested   = 0;

        /*
         * Tiered, because Basic Access is 15,000 operations a day for the
         * whole project and Standard was refused on 2026-09-09 for not yet
         * needing it. Connecting every client of A-1 Chimney's manager takes
         * the fleet from 22 accounts to 45, and 45 lead reads plus their
         * conversation reads every five minutes is past the ceiling - and a
         * day over it drops the evening's leads, which is the one outcome
         * this command exists to prevent.
         *
         * So: an account that has produced a lead in the last thirty days is
         * read every tick, its conversations every other tick (ten minutes);
         * an account that has produced nothing in thirty days is read once
         * per half hour and its conversations not at all, since it has no
         * lead to hang one on. With --days everything is read, every time.
         * Measured 2026-09-09 at 25 producing and 20 quiet accounts: about
         * 11,800 operations a day, with the rest for Fetch new and connects.
         */
        $minute   = now()->minute;
        $forced   = is_numeric($this->option('days'));
        $halfHour = $minute % 30 < 5;
        $tenMin   = $minute % 10 < 5;

        foreach ($connections as $connection) {
            $organization = $organizations->get($connection->organization_id);

            if ($organization === null) {
                continue;
            }

            $producing = Lead::query()
                ->withoutGlobalScopes()
                ->where('connection_id', $connection->getKey())
                ->where('received_at', '>=', now()->subDays(30))
                ->exists();

            // A connection made in the last half hour is due now, whatever
            // the clock: it has no leads only because nobody has asked yet.
            // That is every row a connect or leads:adopt-manager-clients just
            // made. (Not last_reached_at: connect() stamps that too.)
            $fresh = $connection->created_at !== null
                && $connection->created_at->gte(now()->subMinutes(30));

            if (! $forced && ! $producing && ! $halfHour && ! $fresh) {
                $rested++;

                continue;
            }

            try {
                $result = $this->tenant->run(
                    $organization,
                    fn () => $this->localServices->sync(
                        $connection,
                        $forced ? (int) $this->option('days') : null,
                        withConversations: $forced || $fresh || ($producing && $tenMin),
                    ),
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

        $this->info("Synced {$connections->count()} connection(s) ({$rested} quiet ones rested): {$fetched} lead(s), {$messages} new call(s)/message(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
