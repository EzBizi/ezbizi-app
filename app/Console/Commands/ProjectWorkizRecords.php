<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\PhoneE164;
use App\Support\Workiz\StateCode;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    public function handle(TenantContext $tenant, RecordNumberGenerator $numbers, AuditLogger $audit): int
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
            [$c, $cu, $j] = $tenant->run($organization, function () use ($numbers, $audit, $limit, $dryRun) {
                $clients = $this->deriveClients($dryRun);
                $customers = $this->projectCustomers($numbers, $audit, $limit, $dryRun);
                $jobs = $this->projectJobs($numbers, $limit, $dryRun);
                $this->followWorkiz($dryRun);

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

    private function projectCustomers(RecordNumberGenerator $numbers, AuditLogger $audit, int $limit, bool $dryRun): int
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

            /*
             * THE CREATE AND THE LINK ARE ONE ACT.
             *
             * Customers' finding, and it is the idempotency guarantee itself:
             * if the customer is written and the link is not, the next run sees
             * a client naming no customer and projects a second one. Over
             * 7,225 rows a deadlock or a dropped connection is not exotic, and
             * the failure is SILENT - the only symptom is a duplicate the
             * duplicate sweep later reports as though two people had created it.
             */
            DB::transaction(function () use ($client, $franchise, $numbers) {
                $customer = Customer::create([
                    // Set ONCE, on creation, and never re-derived. The owning
                    // franchise is reassignable and audited, so a sweep that
                    // re-derived it would silently undo somebody's reassignment —
                    // the Customers session's finding.
                    'owning_franchise_id' => $franchise->getKey(),
                    'number' => $numbers->next(RecordNumberGenerator::CUSTOMERS, $franchise),
                    'name' => $client->name ?: ($client->company ?: 'Workiz client '.$client->client_id),
                    'email' => $client->email,
                    // Workiz sends bare digits and this column holds E.164 --
                    // the API, the database and Twilio all key on it, so a bare
                    // ten is the wrong value for calling somebody, not just an
                    // unformatted one. See PhoneE164 for the production census.
                    'phone' => PhoneE164::from($client->phone),
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
            });

            $created++;
        }

        /*
         * ONE AUDIT ROW PER RUN, and that is Customers' ruling rather than a
         * shortcut.
         *
         * An automation event and an audit row are two different things, and
         * the docblock above only argued the first. Without this, thousands of
         * customers arrive with no record of where they came from.
         *
         * But one row per customer would bury every human action in the log for
         * the rest of the day and say less than this does. The question an
         * audit log is actually asked here is "where did all these people come
         * from", and one row answers it.
         */
        if ($created > 0 && ! $dryRun) {
            $audit->log('customer.imported', metadata: [
                'source' => 'workiz',
                'created' => $created,
                'limit' => $limit,
            ]);
        }

        return $created;
    }

    private function projectJobs(RecordNumberGenerator $numbers, int $limit, bool $dryRun): int
    {
        $created = 0;
        $skipped = 0;

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

            /*
             * NO INVENTED STATUS. Jobs' point, and it is right: every one of
             * the 633 mirrored jobs carries a status, so a `?: 'new'` fallback
             * could only ever fire on a payload shape nobody has seen — and it
             * would mint a seventh status that is in no organization's list.
             * A job with no status is skipped and counted, not guessed at.
             */
            if (($mirrored->status ?? '') === '') {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $created++;

                continue;
            }

            /*
             * THE JOB AND ITS LINK ARE ONE ACT, for the reason Customers gave
             * about the customer and its link: if the job is written and the
             * link is not, the next run sees a mirror row naming no job and
             * projects a second one — silently.
             */
            DB::transaction(function () use ($mirrored, $franchise, $client, $numbers) {
                $job = new Job([
                    'franchise_id' => $franchise->getKey(),
                    'customer_id' => $client->customer_id,
                    'number' => $numbers->next(RecordNumberGenerator::JOBS, $franchise),
                    'title' => $this->title($mirrored),
                    'description' => $this->text($mirrored->payload['JobNotes'] ?? null),

                    // Workiz's own word. Job statuses are tenant-configured rather
                    // than an enum, so A-1's vocabulary lands as A-1's list and
                    // needs no mapping table.
                    'status' => $mirrored->status,

                    // The naive 'Y-m-d H:i:s' string, handed straight to
                    // FranchiseWallTime's cast. Workiz sends no zone — `Timezone`
                    // is absent from every real payload — so building a Carbon
                    // first would arrive pre-shifted and the cast could not tell.
                    'scheduled_for' => $mirrored->scheduled_for?->format('Y-m-d H:i:s'),

                    'amount_minor' => $this->minor($mirrored->payload['JobTotalPrice'] ?? null),
                    'currency' => strtoupper($franchise->currency ?: 'USD'),
                ]);

                // Arriving already Done is a move INTO an earned status, and it
                // is stamped at Workiz's own moment — the same moment the Jobs
                // session's one-time backfill uses, so the two paths agree.
                // Nothing is stamped while the organization names no earned
                // status, which is correct: it only acts on the way in.
                $job->markEarnedIfDue($this->workizMoment($mirrored, $franchise->timezone));
                $job->save();

                $mirrored->job_id = $job->getKey();
                $mirrored->projected = $this->followedValues($job);
                $mirrored->save();
            });

            $created++;
        }

        if ($skipped > 0) {
            $this->warn($skipped.' mirrored jobs had no status and were not projected.');
        }

        return $created;
    }

    /**
     * The fields that change over a job's life in Workiz, and so must keep
     * following it after the job has been projected.
     *
     * Status is the one Jose reported. Schedule and price are the same freeze:
     * a job gets rescheduled, and Workiz fills the price in when it is
     * invoiced — most imported jobs read USD 0.00 because A1 Tools last saw
     * them before they were priced.
     */
    private const FOLLOWED = ['status', 'scheduled_for', 'amount_minor'];

    /**
     * Keep projected jobs following Workiz — per field, and only where nobody
     * in EzBizi has changed that field since the projection last wrote it.
     *
     * ── WHY NOT SIMPLY OVERWRITE ────────────────────────────────
     *
     * `docs/DEVELOPMENT-RULES.md`: a recurring sweep never overwrites a field a
     * person has touched. Insert-only honoured that by never following Workiz
     * at all, which froze every job at the status it was imported with — the
     * bug. Overwriting would honour Workiz by reverting people. The two are
     * told apart by remembering what was written: if the job still holds it,
     * nobody chose otherwise and Workiz's new value may follow; if it does not,
     * a person decided, and that field stops following.
     *
     * ── CANDIDATES BY VALUE, NOT BY CLOCK ───────────────────────
     *
     * The database is asked for rows where the mirror and the job DISAGREE on
     * a followed field; only those are loaded. Comparing `updated_at` against a
     * last-followed timestamp would have been shorter and wrong: DATETIME has
     * one-second precision, so a change landing in the same second as the last
     * run compares equal and is skipped — for a past job, possibly for ever.
     * The SQL is only a pre-filter; the decision is made in PHP below.
     *
     * ── IT RAISES NO AUTOMATION EVENT ───────────────────────────
     *
     * Deliberately, for the reason in the class docblock: the first run after
     * this ships follows well over a thousand jobs at once, and an emit here
     * would turn that into as many notifications. `job.status_changed` for a
     * Workiz job needs a backfill guard carried with it, and belongs to the
     * Jobs and Automations sessions.
     */
    private function followWorkiz(bool $dryRun): int
    {
        $followed = 0;

        WorkizJob::query()
            ->select('workiz_jobs.*')
            ->join('jobs', 'jobs.id', '=', 'workiz_jobs.job_id')
            ->where(function ($q) {
                $q->whereColumn('workiz_jobs.status', '<>', 'jobs.status')
                    ->orWhereRaw('NOT (workiz_jobs.scheduled_for <=> jobs.scheduled_for)')
                    ->orWhereRaw("NOT (ROUND(JSON_EXTRACT(workiz_jobs.payload, '$.JobTotalPrice') * 100) <=> jobs.amount_minor)");
            })
            ->chunkById(500, function ($mirrored) use (&$followed, $dryRun) {
                $jobs = Job::query()->whereIn('id', $mirrored->pluck('job_id'))->get()->keyBy('id');

                foreach ($mirrored as $row) {
                    $job = $jobs->get($row->job_id);

                    if ($job === null) {
                        continue;
                    }

                    $last = $row->projected ?? $this->followedValues($job);
                    $now = $this->followedValues($job);
                    $workiz = [
                        'status' => $row->status,
                        'scheduled_for' => $row->scheduled_for?->format('Y-m-d H:i:s'),
                        'amount_minor' => $this->minor($row->payload['JobTotalPrice'] ?? null),
                    ];

                    $changes = [];

                    foreach (self::FOLLOWED as $field) {
                        $untouched = $this->same($now[$field], $last[$field]);
                        $moved = ! $this->same($workiz[$field], $now[$field]);

                        // A job with no status is not a job: never follow Workiz
                        // into an empty one, for the same reason creation skips it.
                        if ($field === 'status' && ($workiz['status'] ?? '') === '') {
                            continue;
                        }

                        if ($untouched && $moved) {
                            $changes[$field] = $workiz[$field];
                        }
                    }

                    if ($dryRun) {
                        $followed += $changes === [] ? 0 : 1;

                        continue;
                    }

                    DB::transaction(function () use ($row, $job, $changes, $last) {
                        if ($changes !== []) {
                            $job->forceFill($changes);

                            /*
                             * WORKIZ-COMPLETED WORK MUST REACH REVENUE.
                             *
                             * `earned_at` had one writer, JobsController, which
                             * fires only through the API — so a job this pass
                             * moved to Done got no stamp, and revenue, which
                             * reads `whereNotNull('earned_at')`, never saw it.
                             * The Jobs session's `markEarnedIfDue` is the ONE
                             * rule for that, called rather than copied.
                             *
                             * Only when the status actually moved. A job already
                             * sitting at Done is not re-stamped by a sync, or a
                             * year of revenue would land in this month.
                             */
                            if (array_key_exists('status', $changes)) {
                                $job->markEarnedIfDue($this->workizMoment($row, $job->franchise?->timezone));
                            }

                            $job->save();
                        }

                        // What we have written, field by field. A field a
                        // person changed is NOT recorded as ours: `last` keeps
                        // our old write, so their value goes on not matching and
                        // stays theirs.
                        $row->projected = array_merge($last, array_intersect_key($changes, $last));
                        $row->save();
                    });

                    $followed += $changes === [] ? 0 : 1;
                }
            }, 'workiz_jobs.id', 'id');

        if ($followed > 0) {
            $this->info($followed.' projected jobs followed a change in Workiz.');
        }

        return $followed;
    }

    /**
     * When Workiz says the status last changed, as an instant.
     *
     * `LastStatusUpdate` is wall time with no zone — `Timezone` is absent from
     * every real payload — so it is read in the branch's own zone. Reading it
     * as UTC would move the stamp four or five hours and, at a month end, into
     * the wrong month. No zone on the franchise falls back to UTC, the same
     * fallback `FranchiseWallTime` uses, so the stamp and the schedule agree.
     *
     * Returned in UTC, and that is load-bearing rather than tidy: Laravel stores
     * a date in whatever zone the Carbon is holding, so handing over a New York
     * instant writes its New York wall clock into a UTC column and moves the
     * stamp four hours. Found by the test, not by reading.
     *
     * Null when Workiz gave no moment, which `markEarnedIfDue` reads as now —
     * right for a change this pass has only just seen.
     */
    private function workizMoment(WorkizJob $row, ?string $timezone): ?CarbonInterface
    {
        $raw = $row->getRawOriginal('last_status_update');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return Carbon::parse($raw, ($timezone ?? '') !== '' ? $timezone : 'UTC')->utc();
    }

    /**
     * The followed fields as the `jobs` row holds them, in the same shapes the
     * projection writes — the raw wall-clock string for the schedule, never a
     * Carbon, because the cast stores naive time.
     *
     * @return array{status: ?string, scheduled_for: ?string, amount_minor: ?int}
     */
    private function followedValues(Job $job): array
    {
        return [
            'status' => $job->status,
            'scheduled_for' => $job->getRawOriginal('scheduled_for'),
            'amount_minor' => $job->amount_minor,
        ];
    }

    /**
     * Equal as stored, whatever PHP type each side arrived as — JSON hands back
     * ints where the model holds strings and the other way round.
     */
    private function same(mixed $a, mixed $b): bool
    {
        return (string) ($a ?? '') === (string) ($b ?? '');
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
