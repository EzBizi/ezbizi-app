<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\User;
use App\Models\WorkizTeamMember;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizTeam;
use App\Support\Workiz\WorkizTeamAttributes;
use Illuminate\Console\Command;

/**
 * Mirror every connected branch's Workiz team, and propose EzBizi users for
 * them by email.
 *
 * ── HOURLY, NOT PER MINUTE, AND THAT IS A QUOTA DECISION ────────
 *
 * Jobs are swept every minute because a status changes all day. A team roster
 * changes when somebody is hired. Reading nine accounts every minute for that
 * would spend the same Workiz quota that refuses the job sweep with
 * `429 "Account reached Api Quotas"` — measured 26 times in one day on A-1's
 * own sync. A1 Tools reached the same conclusion: their team sync is on a
 * separate, slower cron from their job sync.
 *
 * ── THE LINK IS PROPOSED ONCE AND NEVER RE-DECIDED ──────────────
 *
 * An exact email match sets `user_id` with `auto_matched = true`. After that
 * this command never touches `user_id` again — not to change it, not to clear
 * it. A confirmed link is EzBizi's own record, and a sweep that re-derived it
 * every hour would revert a correction the moment somebody made it, which is
 * the defect the Customers session warned about on their side of the import.
 *
 * So: propose into an empty column, never overwrite a decided one.
 */
class SyncWorkizTeam extends Command
{
    protected $signature = 'ezbizi:sync-workiz-team
        {--connection= : Sync only this connection id}';

    protected $description = 'Mirror Workiz team members and propose EzBizi users by email';

    /**
     * Injected here rather than in a constructor: Laravel resolves every
     * discovered command at BOOT to read its signature, and constructor
     * injection therefore builds the container before the test environment's
     * database configuration exists. It surfaces as
     * "Access denied for user 'root'@'localhost'", which names MySQL and has
     * nothing to do with it. See SyncWorkizJobs for the full account.
     */
    public function handle(WorkizTeam $team, TenantContext $tenant): int
    {
        $connections = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No Workiz accounts are connected.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $connections->pluck('organization_id')->unique())
            ->get()
            ->keyBy(fn (Organization $o) => $o->getKey());

        $mirrored = 0;
        $proposed = 0;
        $failed = [];

        foreach ($connections as $connection) {
            $organization = $organizations[$connection->organization_id] ?? null;

            if ($organization === null) {
                $failed[$this->nameOf($connection)] = 'No organization for this connection.';

                continue;
            }

            $result = $team->raw($connection);

            if ($result['failed'] !== null) {
                $failed[$this->nameOf($connection)] = $result['failed'];
            }

            $counts = $tenant->run($organization, function () use ($connection, $result) {
                $mirrored = 0;
                $proposed = 0;

                foreach ($result['members'] as $payload) {
                    $attributes = WorkizTeamAttributes::fromPayload($payload);

                    if ($attributes['remote_id'] === '') {
                        continue;
                    }

                    $row = WorkizTeamMember::query()->firstOrNew([
                        'franchise_id' => $connection->scope_id,
                        'remote_id' => $attributes['remote_id'],
                    ]);

                    $row->fill($attributes);
                    $row->connection_id = $connection->getKey();
                    $row->synced_at = now();

                    // PROPOSE ONLY INTO AN EMPTY COLUMN. A row that already
                    // names a user keeps it, whether somebody confirmed it or
                    // an earlier run guessed it.
                    if ($row->user_id === null && $attributes['email'] !== null) {
                        $user = User::query()
                            ->whereRaw('LOWER(email) = ?', [$attributes['email']])
                            ->first();

                        if ($user !== null) {
                            $row->user_id = $user->getKey();
                            $row->auto_matched = true;
                            $proposed++;
                        }
                    }

                    $row->save();
                    $mirrored++;
                }

                return [$mirrored, $proposed];
            });

            $mirrored += $counts[0];
            $proposed += $counts[1];
        }

        $this->info($mirrored.' Workiz team members mirrored, '.$proposed.' newly matched to an EzBizi user by email.');

        foreach ($failed as $branch => $why) {
            $this->warn($branch.': '.$why);
        }

        return self::SUCCESS;
    }

    private function nameOf(SocialConnection $connection): string
    {
        $name = trim((string) $connection->display_name);

        return $name === '' ? 'connection '.$connection->getKey() : $name;
    }
}
