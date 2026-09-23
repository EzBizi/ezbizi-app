<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\StateCode;
use Illuminate\Console\Command;

/**
 * Turn the Workiz mirror into EzBizi customers and jobs.
 *
 * The mirror lands in `workiz_jobs`, which no screen reads. This is the step
 * that puts A-1's work on the Customers and Jobs screens.
 *
 * ── IT CREATES AND THEN STOPS ───────────────────────────────────
 *
 * The house rule (`docs/DEVELOPMENT-RULES.md`): a recurring sweep never
 * overwrites a field a person has touched. Four sessions derived it separately
 * on 2026-09-23, and this command is the one most able to break it — it runs
 * repeatedly over rows people are editing.
 *
 * So the only writes here are INSERTS. A Workiz client that already names a
 * customer is skipped; a Workiz job that already names a job is skipped. The
 * mirror goes on refreshing what Workiz says, so a disagreement is always
 * inspectable, and nothing a person corrected in EzBizi is ever reverted.
 *
 * That also makes it idempotent without a watermark: the link IS the record of
 * what has been projected.
 *
 * ── CLIENTS BEFORE JOBS, BECAUSE `customer_id` IS NOT NULL ──────
 *
 * The Jobs session measured the seven columns `jobs` refuses to insert without,
 * and `customer_id` is a dependency rather than a mapping. So the client pass
 * runs first and a job whose client has not projected is skipped rather than
 * forced.
 *
 * ── NO AUTOMATION EVENT IS RAISED, AND THAT IS DELIBERATE ───────
 *
 * Writing a `jobs` row through the model emits nothing — every `job.*` emit in
 * this codebase lives in a controller, which the Automations session measured
 * and warned about. That is what makes this first run safe: A-1 has one
 * existing "tell the office when a job changes" rule, and 626 mirrored jobs
 * would otherwise be 626 notifications. Whoever adds an emit to this path
 * inherits that problem in the same change and must carry a watermark with it.
 */
class ProjectWorkizRecords extends Command
{
    protected $signature = 'ezbizi:project-workiz
        {--limit=500 : Most records of each kind to project in one run}
        {--dry-run : Report what would be created and write nothing}';

    protected $description = 'Project mirrored Workiz clients and jobs into EzBizi customers and jobs';

    /**
     * Dependencies arrive here, not in a constructor — Laravel resolves every
     * discovered command at boot, and constructor injection builds the
     * container before the test environment's database exists. See
     * SyncWorkizJobs for the full account.
     */
    public function handle(TenantContext $tenant, RecordNumberGenerator $numbers): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $organizations = Organization::query()
            ->withoutGlobalScopes()
            ->whereIn('id', WorkizJob::query()->withoutGlobalScopes()->distinct()->pluck('organization_id'))
            ->get();

        if ($organizations->isEmpty()) {
            $this->info('Nothing mirrored yet — run ezbizi:sync-workiz first.');

            return self::SUCCESS;
        }

        $clients = 0;
        $customers = 0;
        $jobs = 0;

        foreach ($organizations as $organization) {
            [$c, $cu, $j] = $tenant->run($organization, function () use ($numbers, $limit, $dryRun) {
                $clients = $this->deriveClients($dryRun);
                $customers = $this->projectCustomers($numbers, $limit, $dryRun);
                $jobs = $this->projectJobs($numbers, $limit, $dryRun);

                return [$clients, $customers, $jobs];
            });

            $clients += $c;
            $customers += $cu;
            $jobs += $j;
        }

        $this->info(($dryRun ? '[dry run] ' : '')
            .$clients.' Workiz clients derived, '
            .$customers.' customers created, '
            .$jobs.' jobs created.');

        return self::SUCCESS;
    }

    /**
     * Rebuild `workiz_clients` from the mirror.
     *
     * Workiz has no client endpoint, so the client IS the contact block on a
     * job. The most recent job wins, which is why `from_remote_job_id` records
     * which one it was — "latest wins" is otherwise an assertion nobody can
     * check.
     *
     * The contact fields refresh every run. That is correct: this table is a
     * mirror. Nothing a person edits lives here — their edits live on the
     * `customers` row, which this never touches after creating it.
     */
    private function deriveClients(bool $dryRun): int
    {
        $touched = 0;

        WorkizJob::query()
            ->whereNotNull('client_id')
            ->orderBy('booked_at')
            ->chunkById(500, function ($mirrored) use (&$touched, $dryRun) {
                foreach ($mirrored as $job) {
                    $payload = $job->payload ?? [];

                    $attributes = [
                        'connection_id' => $job->connection_id,
                        'name' => $this->personName($payload),
                        'company' => $this->text($payload['Company'] ?? null),
                        'email' => $this->text($payload['Email'] ?? null),
                        'phone' => $this->text($payload['Phone'] ?? null),
                        'phone_digits' => preg_replace('/\D+/', '', (string) ($payload['Phone'] ?? '')) ?: null,
                        'address' => $job->address,
                        'unit' => $job->unit,
                        'city' => $job->city,
                        'state' => $job->state,
                        'postal_code' => $job->postal_code,
                        'country' => $job->country,
                        'from_remote_job_id' => $job->remote_id,
                        'last_seen_at' => $job->booked_at,
                        'synced_at' => now(),
                    ];

                    if ($dryRun) {
                        $touched++;

                        continue;
                    }

                    // Ordered oldest first, so the LAST write wins and that is
                    // the most recent job — which is the rule stated in the
                    // migration rather than an accident of iteration.
                    WorkizClient::query()->updateOrCreate(
                        ['franchise_id' => $job->franchise_id, 'client_id' => $job->client_id],
                        $attributes,
                    );

                    $touched++;
                }
            });

        return $touched;
    }

    private function projectCustomers(RecordNumberGenerator $numbers, int $limit, bool $dryRun): int
    {
        $created = 0;

        $pending = WorkizClient::query()
            ->whereNull('customer_id')
            ->limit($limit)
            ->get();

        foreach ($pending as $client) {
            $franchise = Franchise::query()->find($client->franchise_id);

            if ($franchise === null) {
                continue;
            }

            if ($dryRun) {
                $created++;

                continue;
            }

            $customer = Customer::create([
                // Set ONCE, on creation, and never re-derived. The owning
                // franchise is reassignable and audited, so a sweep that
                // re-derived it would silently undo somebody's reassignment —
                // the Customers session's finding.
                'owning_franchise_id' => $franchise->getKey(),
                'number' => $numbers->next(RecordNumberGenerator::CUSTOMERS, $franchise),
                'name' => $client->name ?: ($client->company ?: 'Workiz client '.$client->client_id),
                'email' => $client->email,
                'phone' => $client->phone,
                'address_line1' => $client->address,
                'address_line2' => $client->unit,
                'city' => $client->city,

                // `customers.state` is two characters and Workiz sends a NAME
                // ("Ohio", "Massachusetts"). Unrecognised becomes null rather
                // than a truncation: "Oh" is wrong in a way that looks right,
                // and the rest of the address survives either way.
                'state' => StateCode::from($client->state),
                'postal_code' => $client->postal_code,
                'is_active' => true,
            ]);

            $client->customer_id = $customer->getKey();
            $client->save();

            $created++;
        }

        return $created;
    }

    private function projectJobs(RecordNumberGenerator $numbers, int $limit, bool $dryRun): int
    {
        $created = 0;

        $pending = WorkizJob::query()
            ->whereNull('job_id')
            ->whereNotNull('client_id')
            ->orderBy('booked_at')
            ->limit($limit)
            ->get();

        foreach ($pending as $mirrored) {
            $client = WorkizClient::query()
                ->where('franchise_id', $mirrored->franchise_id)
                ->where('client_id', $mirrored->client_id)
                ->whereNotNull('customer_id')
                ->first();

            // A job whose client has not projected is SKIPPED, not forced.
            // `jobs.customer_id` is NOT NULL and inventing one would attach
            // real work to the wrong person.
            if ($client === null) {
                continue;
            }

            $franchise = Franchise::query()->find($mirrored->franchise_id);

            if ($franchise === null) {
                continue;
            }

            if ($dryRun) {
                $created++;

                continue;
            }

            $job = Job::create([
                'franchise_id' => $franchise->getKey(),
                'customer_id' => $client->customer_id,
                'number' => $numbers->next(RecordNumberGenerator::JOBS, $franchise),
                'title' => $this->title($mirrored),
                'description' => $this->text($mirrored->payload['JobNotes'] ?? null),

                // Workiz's own word. Job statuses are tenant-configured rather
                // than an enum, so A-1's vocabulary lands as A-1's list and
                // needs no mapping table.
                'status' => $mirrored->status ?: 'new',

                // The naive 'Y-m-d H:i:s' string, handed straight to
                // FranchiseWallTime's cast. Workiz sends no zone — `Timezone`
                // is absent from every real payload — so building a Carbon
                // first would arrive pre-shifted and the cast could not tell.
                'scheduled_for' => $mirrored->scheduled_for?->format('Y-m-d H:i:s'),

                'amount_minor' => $this->minor($mirrored->payload['JobTotalPrice'] ?? null),
                'currency' => strtoupper($franchise->currency ?: 'USD'),
            ]);

            $mirrored->job_id = $job->getKey();
            $mirrored->save();

            $created++;
        }

        return $created;
    }

    /**
     * `jobs.title` is NOT NULL and Workiz sends no title.
     *
     * `JobType` is the closest thing and it is what people already recognise —
     * "Free Estimate", "Chimney Sweep". Stated as a decision rather than
     * written at the insert, because this is what the list shows and what
     * somebody quotes on the phone. `JobName` is present on real payloads but
     * empty on 247 of 400, so it cannot carry it alone.
     */
    private function title(WorkizJob $mirrored): string
    {
        return $this->text($mirrored->payload['JobName'] ?? null)
            ?: ($mirrored->job_type ?: 'Workiz job '.$mirrored->remote_id);
    }

    private function personName(array $payload): ?string
    {
        $name = trim(implode(' ', array_filter([
            $this->text($payload['FirstName'] ?? null),
            $this->text($payload['LastName'] ?? null),
        ])));

        return $name !== '' ? $name : $this->text($payload['Company'] ?? null);
    }

    /**
     * Money arrives as integer|double dollars. Rounded rather than cast,
     * because (int)(1.15 * 100) is 114 in binary floating point.
     */
    private function minor(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round(((float) $value) * 100) : null;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
