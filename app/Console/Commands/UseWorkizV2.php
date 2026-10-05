<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move one branch's Workiz connection to its v2 key, or connect a branch that
 * has none (Massachusetts). Jose, 2026-10-05: "update all the franchises with
 * the new v2 API Keys".
 *
 * The key comes on STDIN, or from a file named by --key-file, and never as an
 * argument: a process list and a shell history keep arguments. Nothing here
 * prints it.
 *
 * ── CHECKED BEFORE ANYTHING CHANGES ─────────────────────────────
 *
 *  - The key speaks v2 (WorkizJobs::verify).
 *  - It is THIS branch's account. Its jobs must be jobs this branch's mirror
 *    holds, and none another branch's: a key saved under the wrong file name
 *    would otherwise put one branch's book on another. A branch with nothing
 *    mirrored yet is only checked for the second.
 *
 * ── THEN, UNDER THE PROJECTION'S LOCK ───────────────────────────
 *
 * The credentials, keeping the v1 pair beside the key so the branch can move
 * back; at once the team (SyncWorkizTeam carries each person's link from their
 * v1 row to the USR- one); then the branch's upcoming jobs, re-read through v2. Held so that no projection follows a v2
 * payload naming a technician EzBizi does not yet know under that id: it
 * would read as the job losing its technician.
 */
class UseWorkizV2 extends Command
{
    protected $signature = 'ezbizi:workiz-use-v2
        {franchise : The branch\'s franchise id}
        {--key-file= : A file holding the key, instead of STDIN}
        {--dry-run : Check the key and report, changing nothing}';

    protected $description = 'Move a branch to its Workiz v2 key (read from STDIN), checked against the branch\'s own jobs';

    public function handle(WorkizJobs $workiz, TenantContext $tenant, AuditLogger $audit): int
    {
        $file = $this->option('key-file');
        $key = trim((string) ($file !== null ? @file_get_contents($file) : stream_get_contents(STDIN)));
        $franchise = Franchise::query()->withoutGlobalScopes()->find($this->argument('franchise'));

        if ($franchise === null || $key === '') {
            $this->error($franchise === null ? 'No such franchise.' : 'No key on STDIN.');

            return self::FAILURE;
        }

        try {
            $version = $workiz->verify($key);
        } catch (RuntimeException $e) {
            $this->error($franchise->name.': '.$e->getMessage());

            return self::FAILURE;
        }

        if ($version !== 'v2') {
            $this->error($franchise->name.': this is a v1 token, not a v2 key.');

            return self::FAILURE;
        }

        $probe = new SocialConnection;
        $probe->forceFill(['scope_id' => $franchise->getKey(), 'credentials' => ['api_token' => $key, 'api_version' => 'v2']]);
        $uuids = array_column($workiz->raw($probe, 3 * WorkizJobs::PAGE, '2023-01-01')['jobs'], 'UUID');

        $mine = DB::table('workiz_jobs')->where('franchise_id', $franchise->getKey())->whereIn('remote_id', $uuids ?: ['-'])->count();
        $theirs = DB::table('workiz_jobs')->where('franchise_id', '!=', $franchise->getKey())->whereIn('remote_id', $uuids ?: ['-'])->count();
        $mirrored = DB::table('workiz_jobs')->where('franchise_id', $franchise->getKey())->exists();

        $this->info(sprintf('%s: v2 key read %d jobs; %d are this branch\'s, %d another branch\'s.', $franchise->name, count($uuids), $mine, $theirs));

        if ($theirs > 0 || ($mirrored && $uuids !== [] && $mine === 0)) {
            $this->error($franchise->name.': this key reads another account\'s jobs. Nothing was changed.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $lock = Cache::lock(ProjectWorkizRecords::LOCK, 300);

        try {
            $lock->block(120);
        } catch (LockTimeoutException) {
            $this->error('The Workiz projection is running. Nothing was changed; run this again.');

            return self::FAILURE;
        }

        try {
            $organization = Organization::query()->withoutGlobalScopes()->findOrFail($franchise->organization_id);

            $connection = $tenant->run($organization, function () use ($franchise, $key, $audit, $mine, $uuids) {
                $connection = SocialConnection::query()
                    ->where('provider', SocialProvider::Workiz->value)
                    ->where('scope_type', SocialConnection::SCOPE_FRANCHISE)
                    ->where('scope_id', $franchise->getKey())
                    ->first() ?? (new SocialConnection)->forceFill([
                        'organization_id' => $franchise->organization_id,
                        'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                        'scope_id' => $franchise->getKey(),
                        'provider' => SocialProvider::Workiz->value,
                        'remote_id' => $franchise->getKey(),
                        'display_name' => $franchise->name,
                    ]);

                $previous = (array) ($connection->credentials ?? []);
                $v1 = ($previous['api_version'] ?? 'v1') === 'v1' && ($previous['api_token'] ?? '') !== ''
                    ? array_filter(['api_token' => $previous['api_token'], 'auth_secret' => $previous['auth_secret'] ?? null])
                    : ($previous['v1'] ?? null);

                $connection->connect(array_filter([
                    'refresh_token' => $key,
                    'api_token' => $key,
                    'api_version' => 'v2',
                    'v1' => $v1,
                ]));
                $connection->save();

                $audit->log('workiz.moved_to_v2', $connection, metadata: [
                    'branch' => $franchise->name,
                    'jobs_read' => count($uuids),
                    'already_mirrored' => $mine,
                    'v1_kept' => $v1 !== null,
                ]);

                return $connection;
            });

            Artisan::call('ezbizi:sync-workiz-team', ['--connection' => $connection->getKey()]);
            $this->info(trim(Artisan::output()));

            // And the upcoming jobs at once, still under the lock: every
            // payload the board can show then names its technicians by the
            // USR- ids the team now holds (Scheduling's catch, 2026-10-05).
            Artisan::call('ezbizi:sync-workiz', ['--connection' => $connection->getKey(), '--reconcile' => 0, '--verify' => 0]);
            $this->info(trim(Artisan::output()));
        } finally {
            $lock->release();
        }

        $this->info($franchise->name.' now reads Workiz through v2.');

        return self::SUCCESS;
    }
}
