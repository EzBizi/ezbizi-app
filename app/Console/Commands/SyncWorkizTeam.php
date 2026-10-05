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
 * So: propose into an empty column, never overwrite a decided one. And never
 * into a column a person emptied on purpose: Scheduling › Settings › Schedule
 * stamps `link_confirmed_at` on every save, "not linked" included.
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

                    if (! $row->exists && str_starts_with($attributes['remote_id'], 'USR-')) {
                        $this->succeed($row, $attributes);
                    }

                    // PROPOSE ONLY INTO AN EMPTY COLUMN. A row that already
                    // names a user keeps it, whether somebody confirmed it or
                    // an earlier run guessed it; a row a person unlinked stays
                    // unlinked.
                    if ($row->user_id === null && $row->link_confirmed_at === null && $attributes['email'] !== null) {
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

    /**
     * A BRANCH MOVED TO ITS V2 KEY (Jose, 2026-10-05) meets each person again
     * under a USR- id. Their v1 row holds the link to the EzBizi user, so the
     * new row takes it over, and the old row leaves the pickers and the seat
     * count. Past jobs, whose payloads still name the v1 id, keep finding the
     * same person through it.
     *
     * Matched by email, else by name, and only when exactly one v1 row in the
     * branch answers: measured on New York, all 11 people matched both ways,
     * and the jobs they share agreed 10 of 10.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function succeed(WorkizTeamMember $row, array $attributes): void
    {
        $v1 = WorkizTeamMember::query()
            ->where('franchise_id', $row->franchise_id)
            ->where('remote_id', 'not like', 'USR-%')
            ->get();

        $old = $v1->filter(fn ($m) => $attributes['email'] !== null && mb_strtolower((string) $m->email) === $attributes['email']);
        $old = $old->count() === 1 ? $old : $v1->filter(fn ($m) => mb_strtolower(trim((string) $m->name)) === mb_strtolower(trim((string) $attributes['name'])));

        if ($old->count() !== 1) {
            return;
        }

        $old = $old->first();
        $row->forceFill([
            'user_id' => $old->user_id,
            'link_confirmed_at' => $old->link_confirmed_at,
            'auto_matched' => $old->auto_matched,
        ]);
        // `succeeded_by` is how the board takes an old payload's v1 id to the
        // person's live row (Scheduling, d458d49f).
        $old->forceFill(['is_active' => false, 'payload' => ['succeeded_by' => $row->remote_id] + (array) $old->payload])->save();
    }

    private function nameOf(SocialConnection $connection): string
    {
        $name = trim((string) $connection->display_name);

        return $name === '' ? 'connection '.$connection->getKey() : $name;
    }
}
