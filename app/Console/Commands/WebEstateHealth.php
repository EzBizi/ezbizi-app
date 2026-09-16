<?php

namespace App\Console\Commands;

use App\Models\WebSite;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Whether the customers' websites are still being answered.
 *
 * ── Why this exists ──
 *
 * From plugin 1.9.0 the chat and contact form vanish at the first 401 or 403
 * from us. That is right for a revoked token, and it means a bug in our own
 * plugin-token validation would blank the widgets on every customer site within
 * about five minutes of a deploy — all at once, and quietly, since the only
 * explanation lands in a wp-admin notice nobody is reading.
 *
 * I gave the deploy desk a curl to run after such a cut and it was useless to
 * them: an unauthenticated request returns exactly the 401 the failure
 * produces, so without a live site token the probe cannot tell "the estate is
 * fine" from "every chat box is gone" — and the token cannot be minted, because
 * `token_hash` is a hash and the plaintext is shown once and never recoverable.
 *
 * ── Why no credential is needed ──
 *
 * `last_seen_at` is written only AFTER a token resolves. A refused fetch
 * returns before that line, so a site being turned away simply stops arriving,
 * and the estate's health is already in our own table. Nothing here calls out,
 * and nothing here needs to be authorised.
 *
 * ── What the numbers mean ──
 *
 * The plugin refreshes on a five-minute cache, so a healthy estate has nearly
 * every live site inside the last fifteen. Measured on 2026-09-16 with 111
 * registered: 93 within five minutes, 110 within fifteen, one never seen — a
 * site registered before anybody installed the plugin.
 */
class WebEstateHealth extends Command
{
    protected $signature = 'ezbizi:web-estate {--window=15 : Minutes a live site should have called home within}';

    protected $description = 'Whether customer websites are still fetching their values (run after any cut touching plugin tokens)';

    public function __construct(private readonly TenantContext $tenant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $window = max(1, (int) $this->option('window'));

        /*
         * Every tenant's sites: this is a question about the estate, and a
         * scoped query would answer it for whichever organization happened to
         * be bound — which on the console is none.
         */
        return $this->tenant->runWithoutTenant(function () use ($window): int {
            $registered = WebSite::query()->count();
            $everSeen = WebSite::query()->whereNotNull('last_seen_at')->count();

            /*
             * The denominator is sites seen in the last DAY, not every site
             * ever registered. A site nobody has installed the plugin on yet,
             * or one whose customer left, is not evidence about this deploy —
             * counting them would make the check read amber for ever and be
             * ignored.
             */
            $live = WebSite::query()
                ->where('last_seen_at', '>', now()->subDay())
                ->count();

            $recent = WebSite::query()
                ->where('last_seen_at', '>', now()->subMinutes($window))
                ->count();

            $this->line('registered .......... '.$registered);
            $this->line('ever seen ........... '.$everSeen);
            $this->line('seen in last 24h .... '.$live);
            $this->line('seen in last '.$window.'m .... '.$recent);

            if ($live === 0) {
                $this->warn('No website has called home in 24 hours. Nothing to compare against.');

                return self::SUCCESS;
            }

            $share = $recent / $live;

            if ($share >= 0.5) {
                $this->info('OK — websites are being answered.');

                return self::SUCCESS;
            }

            /*
             * Half is deliberately generous. The question is not "is every site
             * healthy", which drifts with the estate; it is "did we just stop
             * answering all of them", which looks like a cliff and not a slope.
             */
            $this->error(
                'ONLY '.$recent.' of '.$live.' websites have called home in the last '
                .$window.' minutes. If a cut touching plugin tokens just landed, their '
                .'chat and contact forms are gone. Recovery is automatic on the first '
                .'successful read once it is fixed.'
            );

            return self::FAILURE;
        });
    }
}
