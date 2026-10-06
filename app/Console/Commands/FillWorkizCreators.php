<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizV2;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Who booked each Workiz job, read back from v1 for the branches on v2.
 *
 * v2 never says who created a job: `createdBy` is only a field it accepts on a
 * write. Reports credits each booking to a dispatcher by v1's `CreatedBy`, and
 * after the switch every dispatcher read "not in Workiz" (Reports, 2026-10-06).
 * A branch moved to v2 kept its v1 pair under credentials['v1'], so:
 *
 *  - each run asks v1 about the jobs booked in the last three days that have
 *    no creator yet, one job/get each, because v1's listing omits jobs;
 *  - `--since=2023-01-01` pages v1's /job/all/ instead, for the rows the
 *    2026-10-05 refresh blanked.
 *
 * A job v1 names nobody for is recorded as '' and not asked about again.
 * Massachusetts was connected on v2 only and has no v1 pair to ask.
 */
class FillWorkizCreators extends Command
{
    protected $signature = 'ezbizi:workiz-fill-creators
        {--since= : Page v1\'s job list back to this date, rather than asking about the last three days\' jobs one by one}
        {--limit=20 : Most jobs asked about one by one, per branch and run}
        {--pause=1 : Seconds between v1 list pages}';

    protected $description = 'Read who booked each Workiz job back from v1 for branches on v2';

    public function handle(WorkizJobs $workiz, TenantContext $tenant): int
    {
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)->get()
            ->filter(fn (SocialConnection $c) => WorkizV2::uses($c) && (string) ($c->credentials['v1']['api_token'] ?? '') !== '');
        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())->get()->keyBy(fn ($o) => $o->getKey());
        $filled = 0;

        foreach ($connections as $connection) {
            $organization = $organizations[$connection->organization_id] ?? null;

            if ($organization === null) {
                continue;
            }

            // An unsaved copy holding the kept v1 pair, so WorkizJobs reads v1
            // through it. Never saved.
            $v1 = $connection->replicate()->forceFill(['credentials' => $connection->credentials['v1']]);

            $result = $tenant->run($organization, fn () => $this->option('since')
                ? $this->fromList($workiz, $connection, $v1)
                : $this->oneByOne($workiz, $connection, $v1));
            $filled += $result['filled'];

            if ($result['failed'] !== null) {
                $line = 'Workiz creators: '.($connection->display_name ?: $connection->getKey()).': '.$result['failed'];
                Log::warning($line);
                $this->warn($line);
            }
        }

        $this->info($filled.' Workiz job(s) given who booked them.');

        return self::SUCCESS;
    }

    /** @return array{filled: int, failed: ?string} */
    private function oneByOne(WorkizJobs $workiz, SocialConnection $connection, SocialConnection $v1): array
    {
        $rows = WorkizJob::query()->where('franchise_id', $connection->scope_id)
            ->whereNull('created_by')
            ->where('booked_at', '>=', now()->subDays(3))
            ->whereRaw("JSON_EXTRACT(payload, '$.CreatedBy') IS NULL")
            ->orderByDesc('booked_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
        $filled = 0;

        foreach ($rows as $row) {
            $read = $workiz->find($v1, $row->remote_id);

            if ($read['failed'] !== null) {
                return ['filled' => $filled, 'failed' => $read['failed']];
            }

            $filled += $this->record($row, $read['job']['CreatedBy'] ?? '');

            // v1 refuses a key at about 30 quick calls (measured 2026-10-05).
            usleep(300_000);
        }

        return ['filled' => $filled, 'failed' => null];
    }

    /** @return array{filled: int, failed: ?string} */
    private function fromList(WorkizJobs $workiz, SocialConnection $connection, SocialConnection $v1): array
    {
        $filled = 0;

        for ($page = 0; ; $page++) {
            $read = $workiz->page($v1, (string) $this->option('since'), $page, 60);

            if ($read['failed'] !== null) {
                return ['filled' => $filled, 'failed' => $read['failed']];
            }

            $named = collect($read['jobs'])->filter(fn ($job) => trim((string) ($job['CreatedBy'] ?? '')) !== '')
                ->keyBy(fn ($job) => (string) ($job['UUID'] ?? ''));

            if ($named->isNotEmpty()) {
                $rows = WorkizJob::query()->where('franchise_id', $connection->scope_id)
                    ->whereIn('remote_id', $named->keys())->whereNull('created_by')->get();

                foreach ($rows as $row) {
                    $filled += $this->record($row, $named[$row->remote_id]['CreatedBy']);
                }
            }

            if (count($read['jobs']) < WorkizJobs::PAGE) {
                return ['filled' => $filled, 'failed' => null];
            }

            sleep(max(0, (int) $this->option('pause')));
        }
    }

    /** Write who booked it into the payload, where Reports reads it, and the column. 1 when it names someone. */
    private function record(WorkizJob $row, mixed $by): int
    {
        $by = trim((string) $by);
        $row->forceFill([
            'payload' => ['CreatedBy' => $by] + (array) $row->payload,
            'created_by' => $by === '' ? null : $by,
        ])->save();

        return $by === '' ? 0 : 1;
    }
}
