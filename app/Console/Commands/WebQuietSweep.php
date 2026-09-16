<?php

namespace App\Console\Commands;

use App\Models\WebSite;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Websites that have stopped calling home, said once each.
 *
 * ── What this is for ──
 *
 * From plugin 1.9.0 the chat and contact form disappear the moment EzBizi
 * refuses a site's token. That is right — a site that is not connected should
 * not be collecting messages nobody will receive — and it means a disconnected
 * site goes quietly out of business for us, with the only explanation in a
 * wp-admin notice nobody reads.
 *
 * I carried "who should be told?" as a question for Jose for two days before
 * seeing that it is not mine to answer. A trigger lets him decide, per company,
 * with a rule. This sweep is the half that has to be mine, because every
 * judgement in it is about how this plugin behaves.
 *
 * ── Why a day and not an hour ──
 *
 * The plugin refreshes on a five-minute cache, so an hour looks generous and is
 * not. WP-Cron fires on page views: a website with no visitors does not call
 * home, and a small site can be silent all night without anything being wrong.
 * A day is long enough that silence means something and short enough to act on.
 *
 * Measured on the estate while writing this, which is the baseline: 111 sites,
 * 93 seen inside five minutes, 110 inside fifteen. That is a busy afternoon and
 * says nothing about 4am, which is exactly why the threshold is not tuned to it.
 *
 * ── Why never-seen sites are not included ──
 *
 * A site registered before anybody installed the plugin has never called home
 * and has not "gone quiet" — it has not started. Raising for it would mean
 * every new website announces itself as broken the day after it is added.
 */
class WebQuietSweep extends Command
{
    protected $signature = 'ezbizi:web-quiet-sweep {--hours=24 : How long silence has to last}';

    protected $description = 'Raise website.went_quiet for websites that have stopped fetching their values';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);

        $sites = $this->tenant->runWithoutTenant(
            fn () => WebSite::query()
                // Has called home at least once: a site nobody has installed
                // the plugin on has not gone quiet, it has not started.
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '<', $cutoff)
                // And nobody has been told yet. This is the whole reason the
                // column exists: a site dead for a month must not raise the
                // same event on every sweep.
                ->whereNull('went_quiet_at')
                ->get(),
        );

        foreach ($sites as $site) {
            $organization = $this->tenant->runWithoutTenant(fn () => $site->organization);

            $this->tenant->run($organization, function () use ($site, $hours): void {
                /*
                 * Stamped BEFORE the event rather than after. If the emit
                 * throws, the alternative is a site that raises it again on
                 * every sweep — the failure this column exists to prevent,
                 * reached by the error path instead of the happy one.
                 */
                $site->forceFill(['went_quiet_at' => now()])->save();

                $this->events->emitAfterCommit('website.went_quiet', $site, [
                    'after' => [
                        'host' => $site->host,
                        'hours_quiet' => $hours,
                        'last_seen_at' => $site->last_seen_at?->toIso8601String(),
                    ],
                ]);
            });

            $this->line('quiet: '.$site->host.' (last seen '.$site->last_seen_at?->diffForHumans().')');
        }

        $this->info($sites->count().' website'.($sites->count() === 1 ? '' : 's').' went quiet.');

        return self::SUCCESS;
    }
}
