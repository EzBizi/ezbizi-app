<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizV2;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keep a v1 token and secret beside a branch's v2 key, under
 * credentials['v1'], for what only v1 says: who booked each job
 * (FillWorkizCreators). Written for Massachusetts (2026-10-06), connected on
 * v2 alone; the other branches kept theirs when they moved.
 *
 * The pair arrives on STDIN as two lines, token then secret, and is kept only
 * when the jobs it reads are this branch's and no other branch's.
 */
class KeepWorkizV1 extends Command
{
    protected $signature = 'ezbizi:workiz-keep-v1
        {franchise : The branch\'s franchise id}
        {--key-file= : A file holding the two lines, instead of STDIN}
        {--dry-run : Check the pair and report, changing nothing}';

    protected $description = 'Keep a v2 branch\'s v1 token and secret (two lines on STDIN), checked against the branch\'s own jobs';

    public function handle(WorkizJobs $workiz, TenantContext $tenant, AuditLogger $audit): int
    {
        $file = $this->option('key-file');
        $pair = trim((string) ($file !== null ? @file_get_contents($file) : stream_get_contents(STDIN)));
        [$token, $secret] = array_pad(array_map('trim', preg_split('/\R/', $pair, 2)), 2, '');
        $franchise = Franchise::query()->withoutGlobalScopes()->find($this->argument('franchise'));
        $connection = $franchise === null ? null : SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)->where('scope_id', $franchise->getKey())->first();

        if ($franchise === null || $token === '' || $secret === '') {
            $this->error($franchise === null ? 'No such franchise.' : 'Two lines on STDIN: the v1 token, then its secret.');

            return self::FAILURE;
        }

        if ($connection === null || ! WorkizV2::uses($connection)) {
            $this->error($franchise->name.': this branch is not on its Workiz v2 key.');

            return self::FAILURE;
        }

        try {
            $version = $workiz->verify($token);
        } catch (RuntimeException $e) {
            $this->error($franchise->name.': '.$e->getMessage());

            return self::FAILURE;
        }

        if ($version !== 'v1') {
            $this->error($franchise->name.': this is a v2 key, not a v1 token.');

            return self::FAILURE;
        }

        $probe = new SocialConnection;
        $probe->forceFill(['scope_id' => $franchise->getKey(), 'credentials' => ['api_token' => $token, 'auth_secret' => $secret]]);
        $uuids = array_column($workiz->raw($probe, WorkizJobs::PAGE, '2023-01-01')['jobs'], 'UUID');
        $mine = DB::table('workiz_jobs')->where('franchise_id', $franchise->getKey())->whereIn('remote_id', $uuids ?: ['-'])->count();
        $theirs = DB::table('workiz_jobs')->where('franchise_id', '!=', $franchise->getKey())->whereIn('remote_id', $uuids ?: ['-'])->count();

        $this->info(sprintf('%s: v1 token read %d jobs; %d are this branch\'s, %d another branch\'s.', $franchise->name, count($uuids), $mine, $theirs));

        if ($theirs > 0 || $mine === 0) {
            $this->error($franchise->name.': this token does not read this branch\'s jobs. Nothing was changed.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $organization = Organization::query()->withoutGlobalScopes()->findOrFail($franchise->organization_id);

        $tenant->run($organization, function () use ($connection, $token, $secret, $audit, $franchise, $uuids, $mine) {
            $connection->connect(['v1' => ['api_token' => $token, 'auth_secret' => $secret]] + (array) $connection->credentials);
            $connection->save();

            $audit->log('workiz.v1_kept', $connection, metadata: [
                'branch' => $franchise->name,
                'jobs_read' => count($uuids),
                'this_branch' => $mine,
            ]);
        });

        $this->info($franchise->name.' keeps its v1 pair beside the v2 key.');

        return self::SUCCESS;
    }
}
