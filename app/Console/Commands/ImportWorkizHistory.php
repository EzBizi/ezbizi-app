<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizJobAttributes;
use Illuminate\Console\Command;

/**
 * Carry A-1's Workiz history onto the EzBizi mirror, from an A1 Tools export.
 *
 * ── WHY THIS EXISTS: THE API CANNOT GIVE US THE PAST ────────────
 *
 * Measured against the live v1 API on 2026-09-23, on a real branch token:
 *
 *     offset=0   100 rows        offset=100  0 rows      offset=200  0 rows
 *     start_date=2025-01-01 / 2026-01-01 / 2026-09-01
 *        -> the SAME 100 rows every time, every one scheduled from today on
 *
 * `/job/all/` ignores `start_date` and does not page. It answers with roughly
 * the next hundred upcoming appointments and nothing else. So no value of
 * `--since` reaches history, and `ezbizi:sync-workiz` never will.
 *
 * A-1's history exists only because A1 Tools has been catching each job while
 * it was still upcoming, every two minutes, for months: **7,823 raw v1 payloads
 * in `a1tools_workiz_jobs`**, going back to 2025. Those payloads are the same
 * shape `WorkizJobAttributes` already consumes, so this is a re-read of data we
 * can already parse rather than a second integration.
 *
 * ── A FILE, NOT A SECOND DATABASE CONNECTION ────────────────────
 *
 * The same decision `ImportA1ToolsVehicles` made and for the same reason: the
 * read and the write stay separable, so the export can be inspected, re-run and
 * tested without a live connection to A1 Tools and without this application
 * holding A1's credentials. A1 Tools is read-only to us.
 *
 * ── IDEMPOTENT, AND IT SHARES THE SWEEP'S KEY ───────────────────
 *
 * Upserts on `(franchise_id, remote_id)`, exactly as `ezbizi:sync-workiz` does,
 * so history and the live window converge on the same rows rather than racing.
 * Re-running costs nothing and fixes nothing twice.
 *
 * Branches are matched by the FRANCHISE's name, with the connection's label as
 * a second key and one recorded alias. A branch that matches nothing is
 * REPORTED AND SKIPPED, never guessed at — attaching a book to the wrong
 * franchise would put real work in another branch's roll-up and nothing would
 * say so.
 */
class ImportWorkizHistory extends Command
{
    protected $signature = 'ezbizi:import-workiz-history
        {file : JSON array exported from a1tools_workiz_jobs}
        {--organization= : Organization name, when more than one has Workiz connected}
        {--dry-run : Report what would land and write nothing}';

    protected $description = 'Import A-1\'s Workiz job history from an A1 Tools export';

    /**
     * A1 Tools' branch name -> the EzBizi franchise it means.
     *
     * One entry, and it is a ruling rather than a guess: Jose, 2026-09-23,
     * *"NY is clearly New York"*. 173 jobs behind it. Recorded here so the next
     * abbreviation is a decision somebody makes rather than a string comparison
     * that happens to work.
     */
    private const ALIASES = ['ny' => 'new york'];

    public function handle(TenantContext $tenant): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows)) {
            $this->error('That file is not a JSON array of exported rows.');

            return self::FAILURE;
        }

        $organizations = Organization::query()
            ->withoutGlobalScopes()
            ->when($this->option('organization'), fn ($q, $name) => $q->where('name', $name))
            ->whereIn('id', SocialConnection::query()
                ->withoutGlobalScopes()
                ->where('provider', SocialProvider::Workiz->value)
                ->select('organization_id'))
            ->get();

        if ($organizations->count() !== 1) {
            $this->error($organizations->isEmpty()
                ? 'No organization has a Workiz account connected.'
                : 'More than one organization has Workiz connected — name one with --organization.');

            return self::FAILURE;
        }

        $organization = $organizations->first();
        $dryRun = (bool) $this->option('dry-run');

        return $tenant->run($organization, function () use ($rows, $dryRun) {
            /*
             * Branch name -> connection, keyed on the FRANCHISE's name and on
             * the connection's label, because on live data they disagree.
             *
             * Measured on production: the connection labelled "Washington" is
             * attached to the franchise named "New York". Matching on
             * `display_name` alone would have refused A-1's whole New York book
             * and reported it as an unmatched branch — a plausible-looking
             * failure with 173 real jobs behind it. The franchise is the thing
             * a job belongs to, so it is the thing to match on; the label is
             * kept as a second key rather than replaced, because for the other
             * eight they agree.
             *
             * Lower-cased and trimmed: A1's names carry the same stray
             * whitespace Workiz's service areas do.
             */
            $connections = collect();

            foreach (SocialConnection::query()->where('provider', SocialProvider::Workiz->value)->get() as $c) {
                $franchise = Franchise::query()->find($c->scope_id);

                foreach ([$franchise?->name, $c->display_name] as $key) {
                    $key = mb_strtolower(trim((string) $key));

                    if ($key !== '' && ! $connections->has($key)) {
                        $connections->put($key, $c);
                    }
                }

                // Jose, 2026-09-23, on A1 Tools' "NY" account: *"NY is clearly
                // New York"*. Recorded as an alias rather than inferred, so the
                // next abbreviation is a decision somebody makes rather than a
                // string comparison that happens to work.
                foreach (self::ALIASES as $alias => $branch) {
                    if (mb_strtolower(trim((string) $franchise?->name)) === $branch && ! $connections->has($alias)) {
                        $connections->put($alias, $c);
                    }
                }
            }

            $landed = [];
            $unmatched = [];
            $skipped = 0;

            foreach ($rows as $row) {
                $branch = mb_strtolower(trim((string) ($row['branch'] ?? '')));
                $payload = $row['payload'] ?? null;

                if (! is_array($payload)) {
                    $skipped++;

                    continue;
                }

                $connection = $connections->get($branch);

                if ($connection === null) {
                    // By NAME and with a count, so a whole branch missing is a
                    // sentence somebody can act on rather than a silent gap in
                    // every number built on this table.
                    $unmatched[$row['branch'] ?? '(none)'] = ($unmatched[$row['branch'] ?? '(none)'] ?? 0) + 1;

                    continue;
                }

                $attributes = WorkizJobAttributes::fromPayload($payload);

                if ($attributes['remote_id'] === '') {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    WorkizJob::query()->updateOrCreate(
                        [
                            'franchise_id' => $connection->scope_id,
                            'remote_id' => $attributes['remote_id'],
                        ],
                        $attributes + [
                            'connection_id' => $connection->getKey(),
                            'synced_at' => now(),
                        ],
                    );
                }

                $name = (string) $connection->display_name;
                $landed[$name] = ($landed[$name] ?? 0) + 1;
            }

            ksort($landed);

            $this->info(($dryRun ? '[dry run] ' : '').array_sum($landed).' jobs from '.count($landed).' branches:');

            foreach ($landed as $branch => $count) {
                $this->line(sprintf('  %-16s %d', $branch, $count));
            }

            if ($skipped > 0) {
                $this->warn($skipped.' rows had no usable payload or id and were skipped.');
            }

            foreach ($unmatched as $branch => $count) {
                $this->warn('No EzBizi Workiz connection named "'.$branch.'" — '.$count.' jobs NOT imported.');
            }

            return self::SUCCESS;
        });
    }
}
