<?php

namespace App\Console\Commands;

use App\Jobs\MirrorToWorkiz;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Automations\EventEmitter;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizMirror;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Both directions of the Workiz sync, checked every five minutes (Jose, 11-12,
 * after a stuck lock held new jobs back an hour: "there should be a process
 * that constantly checks the workiz - ezbizi ezbizi - workiz sync all the time").
 *
 * Anything older than LATE minutes is drift. The watchdog heals what it can
 * (a stale projection lock, a job that never reached Workiz), keeps the result
 * for the health card, and raises franchise.workiz_sync_drift ONCE per episode,
 * then franchise.workiz_sync_recovered when it clears.
 */
final class WorkizWatchdog extends Command
{
    protected $signature = 'ezbizi:workiz-watchdog {--deep : the last 30 days instead of the last day}';

    protected $description = 'Check the Workiz <-> EzBizi sync both ways, heal what it can, alert once on drift';

    public const LATE = 15;

    public const STATE = 'workiz.watchdog.';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $since = now()->subDays($this->option('deep') ? 30 : 1);

        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)->where('status', 'connected')
            ->where('scope_type', SocialConnection::SCOPE_FRANCHISE)->get();

        foreach ($connections as $connection) {
            $org = Organization::query()->withoutGlobalScopes()->find($connection->organization_id);

            if ($org !== null) {
                $tenant->run($org, fn () => $this->watch($connection, $since, $events));
            }
        }

        return self::SUCCESS;
    }

    private function watch(SocialConnection $connection, $since, EventEmitter $events): void
    {
        $branch = $connection->scope_id;
        $late = now()->subMinutes(self::LATE);
        $items = [];

        // Workiz -> EzBizi: the every-minute sweep is reading this account.
        $read = WorkizJob::query()->where('franchise_id', $branch)->max('synced_at');
        if ($read !== null && $read < $late) {
            $items[] = 'Workiz not read since '.\Illuminate\Support\Carbon::parse($read)->diffForHumans().'.';
        }

        // ...and every job it read became an EzBizi job.
        $unlinked = WorkizJob::query()->where('franchise_id', $branch)->whereNull('job_id')->where('created_at', '<', $late)->where('created_at', '>=', $since);
        if (($n = $unlinked->count()) > 0) {
            // Projection takes ~100s a pass; a row this old means its lock is stuck. Free it; the next minute's run projects.
            Cache::lock(ProjectWorkizRecords::LOCK)->forceRelease();
            $items[] = $n.' Workiz job(s) not in EzBizi, oldest '.$unlinked->min('serial_id').' since '.\Illuminate\Support\Carbon::parse($unlinked->min('created_at'))->diffForHumans().'.';
        }

        // Both ways: a linked pair whose status, date or amount differ, untouched in EzBizi for LATE
        // minutes and already different at the last check (the sweep re-stamps every mirror row each
        // minute, and a Workiz change takes a projection pass to follow, so one sighting is not drift).
        $key = self::STATE.$connection->getKey();
        $was = Cache::get($key, []);
        $differ = DB::table('workiz_jobs as w')->join('jobs as j', 'j.id', '=', 'w.job_id')
            ->where('w.franchise_id', $branch)->where('j.updated_at', '<', $late)->where('j.updated_at', '>=', $since)
            ->where(fn ($q) => $q->whereColumn('w.status', '<>', 'j.status')
                ->orWhereRaw('NOT (w.scheduled_for <=> j.scheduled_for)')
                ->orWhereRaw("NOT (ROUND(JSON_EXTRACT(w.payload, '$.JobTotalPrice') * 100) <=> j.amount_minor)"))
            ->limit(50)->pluck('j.number');
        $drift = $differ->intersect($was['differ'] ?? [])->take(5);
        if ($drift->isNotEmpty()) {
            $items[] = 'Status, date or amount differ: '.$drift->implode(', ').'.';
        }

        // EzBizi -> Workiz: every job made here reached Workiz.
        if (WorkizMirror::connection($branch) !== null) {
            // The last day only, deep or not: 94 older jobs predate their branch's mirroring (measured 11-12).
            $unsent = Job::query()->where('franchise_id', $branch)->where('created_at', '<', $late)->where('created_at', '>=', now()->subDay())
                ->whereNotExists(fn ($q) => $q->from('workiz_jobs')->whereColumn('workiz_jobs.job_id', 'jobs.id'))->limit(20)->get();
            foreach ($unsent as $job) {
                // Once a day per job: a refusal Workiz repeats is not re-sent every five minutes.
                if (Cache::add(self::STATE.'resent.'.$job->getKey(), true, now()->addDay())) {
                    MirrorToWorkiz::later($job, $job->job_type, null);
                }
            }
            if ($unsent->isNotEmpty()) {
                $items[] = 'Not in Workiz: '.$unsent->pluck('number')->take(5)->implode(', ').' (re-sent).';
            }
        }

        Cache::forever($key, ['checked_at' => now()->toIso8601String(), 'items' => $items, 'alerting' => $items !== [], 'differ' => $differ->all()]);

        // One message per episode: on the way in and on the way out.
        $franchise = Franchise::query()->find($branch);
        if ($franchise !== null && ($items !== []) !== (bool) ($was['alerting'] ?? false)) {
            $events->emit($items !== [] ? 'franchise.workiz_sync_drift' : 'franchise.workiz_sync_recovered', $franchise,
                ['summary' => $items !== [] ? 'Workiz sync, '.$franchise->name.': '.implode(' ', $items) : 'Workiz sync, '.$franchise->name.': recovered.']);
        }
    }
}
