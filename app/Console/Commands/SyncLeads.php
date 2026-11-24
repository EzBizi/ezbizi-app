<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Leads\LocalServicesLeads;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
        {--days= : Re-read this many days back on every account, refreshing what is already held}
        {--messages : Only the conversations of accounts with a lead in 30 days (the leads lane, every minute)}';

    protected $description = 'Fetch Local Services enquiries for every connected Google Ads account';

    /**
     * How many accounts in a row must be refused on quota before the run stops.
     *
     * Three, not one: a single 429 can be one account's own rate limit, and
     * abandoning every healthy account behind it would be the old bug with
     * the sign flipped. (Counts here move: the fleet was ~51 during the
     * 2026-09-16 outage this was written for, and 3 an hour later after Jose
     * disconnected 49 while testing the consent flow. The reasoning does not
     * depend on the size, so do not pin one into it.) Three DIFFERENT accounts refused back to back is
     * a fact about the developer token, which is what the ceiling belongs to.
     */
    private const QUOTA_REFUSALS_BEFORE_STOPPING = 3;

    /**
     * Where a stalled sync says so, for the Leads screen to read.
     *
     * Organization-free on purpose: the ceiling belongs to EzBizi's developer
     * token, so it is spent for everybody at once. A per-tenant key would imply
     * a per-tenant quota and let one organization's screen look healthy while
     * the same outage stopped its enquiries too.
     */
    public const STALLED_KEY = 'leads.sync.stalled_on_quota';

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
        /*
         * LONGEST UNREAD FIRST, because the quota now runs out.
         *
         * From 2026-09-11 the fleet sits at Google's ceiling on purpose, and a
         * capped budget makes the ORDER of this list the thing that decides
         * who gets served. Unordered, it came back in insertion order every
         * tick, so whoever came back first spent the surviving quota and
         * nothing made a loser eventually a winner.
         *
         * State the evidence honestly, because the first version of this
         * comment did not. Measured 2026-09-11 at 13:24, thirty minutes into
         * a stall: 0 of 59 accounts reached in the last tick, 16 in the last
         * half hour — and I wrote "the same sixteen", which I never checked.
         * A wider window contradicted it: 56 of 59 had been reached within the
         * hour. So this fixes a hazard the ordering CREATES, not a starvation
         * anybody observed.
         *
         * Ordering by last_reached_at, nulls first, makes the refusals rotate.
         * Every account is eventually the oldest, so a day at the ceiling
         * delays each of them a little instead of starving some of them
         * completely — which is the difference between an enquiry arriving
         * late and an enquiry nobody ever sees.
         */
        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->orderByRaw('last_reached_at IS NULL DESC')
            ->orderBy('last_reached_at')
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

        // Accounts actually reached, so the "not read" count in the log is the
        // remainder rather than a guess.
        $fetchedAccounts = 0;

        /*
         * EVERY ACCOUNT, EVERY TICK - since Standard Access, 2026-09-26.
         *
         * From 2026-09-10 to 2026-09-28 this was tiered, because Basic Access
         * gave the whole project 15,000 operations a day and sixty accounts
         * every five minutes was past it: a quiet account (no lead in thirty
         * days) was read once per half hour, and a producing account's
         * conversations every ten minutes. Google approved Standard Access on
         * 2026-09-26 - "Unlimited Daily Operations" - so the tiers bought
         * nothing and cost a dormant account up to half an hour before its
         * first new enquiry showed, and a reply up to ten minutes.
         *
         * Now: every account's leads every tick; conversations every tick for
         * an account with a lead in the last thirty days (a quiet one has no
         * lead to hang a conversation on). The quota guards below stay: the
         * per-second and concurrency limits still apply, and three refusals in
         * a row are still a fact about the token.
         */
        $forced = is_numeric($this->option('days'));

        /*
         * TWO PASSES, AND THE ORDER IS THE POINT.
         *
         * Both halves spend the same quota and they are not worth the same. A
         * lead is a member of the public nobody has spoken to yet; the
         * messages on it are detail the next tick fetches just as well. In one
         * pass per account, the day the ceiling is reached the accounts late
         * in the list lose BOTH — their enquiries stop arriving because an
         * earlier account's message history spent the last of the quota.
         *
         * So every due account's leads are read first, and conversations are
         * read afterwards with whatever is left. RESOURCE_EXHAUSTED then costs
         * detail rather than discovery, whatever hour it arrives at.
         *
         * This matters now because the fleet grew to sixty accounts on
         * 2026-09-10 and Google's own counter put the last hour at 730
         * requests, which annualises past the 15,000 ceiling. Going over is
         * how Standard Access is earned — Google refused it on 2026-09-09 for
         * not yet needing it — and it must not be how an evening's enquiries
         * are lost.
         */
        $due = [];

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


            $due[] = [
                'connection'    => $connection,
                'organization'  => $organization,
                'conversations' => $forced || $fresh || $producing,
            ];
        }

        /*
         * CONSECUTIVE QUOTA REFUSALS END THE RUN.
         *
         * "One account must not stop the other twenty-one" is right about an
         * account-shaped failure and wrong about this one. The 15,000/day
         * ceiling belongs to the DEVELOPER TOKEN, not to an account, so once
         * it is spent every remaining account gets the same 429 - and the loop
         * below happily asked all of them, then asked them all again for
         * conversations.
         *
         * Production, 2026-09-16: 2,033 refusals between 09:25 and 12:35,
         * unbroken, about 690 an hour against a sustained rate of roughly 600.
         * Essentially every call refused, and the command kept making them.
         *
         * Three IN A ROW is the evidence, and it is evidence no single response
         * carries: one 429 can be an account's own rate limit, but three
         * different accounts refused back to back is a fact about the token.
         * That is why the count is consecutive and a success resets it.
         *
         * What this buys is not politeness. While the window rolls over, the
         * few operations that free up were being spent instantly by this
         * command, so nothing else - the Ads report, a demo video, a person
         * looking - could ever get a call through.
         */
        $quotaRefusals = 0;
        $abandoned = false;

        /*
         * --messages: the second pass alone, every minute in its own lane
         * (Jose, 2026-11-23: LS texts "arriving late ... like 4 minutes").
         * A text on a lead not held yet still brings its lead: the
         * conversation read fetches an unknown lead by id. The full run,
         * leads first, stays in the slow lane.
         */
        $messagesOnly = (bool) $this->option('messages');
        $leaveToLane = ! $messagesOnly && ! $forced && config('ezbizi.scheduler.leads_lane');

        foreach ($messagesOnly ? [] : $due as $item) {
            [$connection, $organization] = [$item['connection'], $item['organization']];

            try {
                $result = $this->tenant->run(
                    $organization,
                    fn () => $this->localServices->sync(
                        $connection,
                        $forced ? (int) $this->option('days') : null,
                        withConversations: false,
                    ),
                );

                $fetched += $result['fetched'];
                $quotaRefusals = 0;
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

                /*
                 * The message is built in LocalServicesLeads::page() a few
                 * files away and says "(HTTP 429)". Matched rather than typed
                 * into a new exception class, because there is exactly one
                 * thrower and one reader of it.
                 *
                 * An ACCOUNT-scoped 429 does NOT count. Three different
                 * accounts refused back to back is only evidence about the
                 * developer token if the limits are the token's; if each one
                 * is hitting its own, stopping here starves every account
                 * behind them, every run, and the log would say "stopped on
                 * quota" each time while nothing was wrong with the quota.
                 *
                 * A response that says nothing about scope still counts. That
                 * is the outage this was written during, and a brake that
                 * needs a field Google may omit is not a brake.
                 */
                $quotaRefusals = str_contains($e->getMessage(), '(HTTP 429)')
                    && ! str_contains($e->getMessage(), LocalServicesLeads::ACCOUNT_SCOPED)
                    ? $quotaRefusals + 1
                    : 0;

                if ($quotaRefusals >= self::QUOTA_REFUSALS_BEFORE_STOPPING) {
                    $abandoned = true;

                    Log::warning('Lead sync stopped early: Google is refusing on quota.', [
                        'consecutive_refusals' => $quotaRefusals,
                        'accounts_not_read'    => count($due) - $fetchedAccounts,
                    ]);

                    /*
                     * AND SAY SO WHERE SOMEBODY IS LOOKING.
                     *
                     * 2026-09-16: Google refused every lead fetch from 09:25 to
                     * 13:25. Nothing on any screen said so. Jose found out at
                     * 12:40 because he happened to open the Ads report for an
                     * unrelated reason, and by then four hours of enquiries had
                     * not arrived. The log said it the whole time; a log is not
                     * a place anybody is standing.
                     *
                     * A cache key rather than a column because the fact expires
                     * on its own. The TTL is the recovery mechanism: every
                     * abandoned run rewrites it, and the first run that does not
                     * abandon simply stops renewing it, so the banner clears
                     * without anyone writing a "recovered" path that could get
                     * the edge wrong. Clearing a state is rarely the inverse of
                     * entering it, and the cheapest way to be right about that
                     * is not to have an exit at all.
                     *
                     * Thirty-five minutes: longer than the five-minute tick so a
                     * single stalled run keeps saying so, short enough that a
                     * recovered fleet stops within one quiet cadence.
                     */
                    Cache::put(
                        self::STALLED_KEY,
                        now()->toIso8601String(),
                        now()->addMinutes(35),
                    );

                    break;
                }
            }

            $fetchedAccounts++;
        }

        // The second pass, and the one that is allowed to fail. Every enquiry
        // is already filed by the time this runs.
        foreach ($due as $item) {
            // Conversations are detail; leads are people nobody has spoken to.
            // If the token had nothing left for the leads it has nothing for
            // these, and asking anyway is 51 more refusals.
            // With the leads lane on, messages are its job alone; reading them here too
            // would race it on the same rows and spend the quota twice.
            if ($abandoned || ! $item['conversations'] || $leaveToLane) {
                continue;
            }

            [$connection, $organization] = [$item['connection'], $item['organization']];

            try {
                $result = $this->tenant->run(
                    $organization,
                    fn () => $this->localServices->conversationsFor(
                        $connection,
                        $forced ? (int) $this->option('days') : null,
                    ),
                );

                $messages += $result['created'];
            } catch (Throwable $e) {
                $failed++;

                Log::warning('Scheduled conversation sync failed for one connection.', [
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
