<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Jobs\EarnedStatuses;
use App\Support\Jobs\JobServiceArea;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\StateCode;
use App\Support\Workiz\WorkizTwins;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        {--dry-run : Report what would be created and write nothing}
        {--announce-limit=200 : More recent status moves than this in one run is a backfill, and none is announced}';

    protected $description = 'Project mirrored Workiz clients and jobs into EzBizi customers and jobs';

    /**
     * Dependencies arrive here, not in a constructor — Laravel resolves every
     * discovered command at boot, and constructor injection builds the
     * container before the test environment's database exists. See
     * SyncWorkizJobs for the full account.
     */
    /**
     * ONE RUN AT A TIME, WHOEVER STARTS IT.
     *
     * On 2026-09-23 at 20:37 the scheduled run (limit 500) and a manual run
     * (--limit=10000, projecting the history import) overlapped. The
     * schedule's withoutOverlapping() guards only SCHEDULED invocations, and
     * this command claimed rows with no lock of its own: both read the same
     * unprojected rows, both created a job for each, and whichever link was
     * written last won. 432 duplicate jobs and 500 duplicate customers, found by
     * the Jobs session as twins sharing a customer and an appointment; the two
     * `customer.imported` audit rows two seconds apart named the two runs.
     *
     * So the lock lives here, in the command, where a manual run cannot step
     * around it. On production the cache store is `database`, so it is atomic
     * across processes. It also keeps a Workiz status change from being
     * announced twice — which, once Jose's rule exists, would be two texts to
     * the same customer.
     */
    public const LOCK = 'ezbizi:project-workiz';

    /**
     * Long enough that no real run outlives it — projecting the whole history
     * import took about two minutes — and short enough that a run killed
     * mid-way does not hold the projection off for more than an hour.
     */
    private const LOCK_SECONDS = 3600;

    /**
     * When the last completed pass BEGAN. The next pass derives clients only
     * from mirror rows written since then: see deriveClients().
     */
    private const DERIVED_SINCE = 'ezbizi:project-workiz:clients-derived-since';

    public function handle(TenantContext $tenant, RecordNumberGenerator $numbers, AuditLogger $audit, EventEmitter $events): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->info('Another Workiz projection is already running, so this one did nothing.');

            return self::SUCCESS;
        }

        // A run killed mid-way (a deploy restarting the scheduler) frees the
        // lock at once instead of holding projection off for its full hour
        // (11-10: 30 minutes of bookings waited). ponytail: SIGKILL cannot be
        // trapped; that case still waits out LOCK_SECONDS.
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT, SIGHUP], function () use ($lock) {
                $lock->release();

                exit(1);
            });
        }

        try {
            return $this->project($tenant, $numbers, $audit, $events);
        } finally {
            $lock->release();
        }
    }

    private function project(TenantContext $tenant, RecordNumberGenerator $numbers, AuditLogger $audit, EventEmitter $events): int
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

        // The watermark is the moment this pass BEGAN, stored only once the
        // whole pass is done: a row written while it runs is read again next
        // time, and a pass that dies advances nothing. None read means a full
        // pass, as after a cache flush -- slower once, never wrong.
        $since = Cache::get(self::DERIVED_SINCE);
        $began = now()->toDateTimeString();

        foreach ($organizations as $organization) {
            [$c, $cu, $j] = $tenant->run($organization, function () use ($numbers, $audit, $events, $limit, $dryRun, $since) {
                $clients = $this->deriveClients($dryRun, $since);
                $customers = $this->projectCustomers($numbers, $audit, $limit, $dryRun);
                $jobs = $this->projectJobs($numbers, $limit, $dryRun);
                $this->followWorkiz($events, $dryRun);

                return [$clients, $customers, $jobs];
            });

            $clients += $c;
            $customers += $cu;
            $jobs += $j;
        }

        if (! $dryRun) {
            Cache::forever(self::DERIVED_SINCE, $began);
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
     * The contact fields refresh whenever a job lands. That is correct: this table is a
     * mirror. Nothing a person edits lives here — their edits live on the
     * `customers` row, which this never touches after creating it.
     */
    private function deriveClients(bool $dryRun, ?string $since): int
    {
        $touched = 0;

        // No orderBy here, ever: chunkById pages on `id > last id`, so ordering
        // by anything else skips rows. orderBy('booked_at') did, on production:
        // 828 of 8,054 rows were never read, and 148 jobs never got a client.
        //
        // And only rows LANDED since the last pass began: nothing else can have
        // changed a client. `synced_at`, because both writers of a payload stamp
        // it (WorkizJob::land and the history import) and this command never
        // does -- `updated_at` moves on every link and follow here, and would
        // re-read what this pass just wrote. Every minute needs it: the full
        // walk of ~8,000 rows took most of an 80-second pass.
        WorkizJob::query()
            ->whereNotNull('client_id')
            ->when($since !== null, fn ($q) => $q->where('synced_at', '>=', $since))
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

                    $client = WorkizClient::query()->firstOrNew(
                        ['franchise_id' => $job->franchise_id, 'client_id' => $job->client_id],
                    );

                    // The most recent job wins, decided by its date rather than
                    // by the order rows are read in: the history import landed
                    // old jobs AFTER newer ones. An undated job never overwrites
                    // a dated one.
                    if ($client->exists && $client->last_seen_at !== null
                        && ($job->booked_at === null || $job->booked_at->lt($client->last_seen_at))) {
                        continue;
                    }

                    $client->fill($attributes)->save();

                    $touched++;
                }
            });

        return $touched;
    }

    private function projectCustomers(RecordNumberGenerator $numbers, AuditLogger $audit, int $limit, bool $dryRun): int
    {
        $created = 0;
        $adopted = [];
        $several = [];
        $unreadable = [];

        $pending = WorkizClient::query()
            ->whereNull('customer_id')
            ->limit($limit)
            ->get();

        foreach ($pending as $client) {
            $franchise = Franchise::query()->find($client->franchise_id);

            if ($franchise === null) {
                continue;
            }

            /*
             * A PERSON EZBIZI ALREADY HOLDS IS ADOPTED, NOT CREATED AGAIN.
             *
             * One person served by two branches is one customer (plan §5.2).
             * A-1 gave Massachusetts its own Workiz account on 2026-10-05; its
             * clients are new numbers for ~800 people EzBizi holds through
             * Nationwide's, and creating them would have made ~800 twins. The
             * rule is the one Customers set for folding twins (WorkizTwins),
             * applied before the twin exists. Ownership is not touched: moving a
             * customer to another branch is Customers' step, and audited there.
             */
            $same = $this->samePerson($client);

            if ($same['customer'] !== null) {
                if (! $dryRun) {
                    $client->customer_id = $same['customer']->getKey();
                    $client->save();
                }

                $adopted[] = ['workiz_client_id' => $client->client_id, 'franchise_id' => $client->franchise_id, 'customer_id' => $same['customer']->getKey()];

                continue;
            }

            if ($same['several'] !== []) {
                $several[] = ['workiz_client_id' => $client->client_id, 'franchise_id' => $client->franchise_id, 'customers' => $same['several']];
            }

            if ($same['zip_unreadable'] !== []) {
                $unreadable[] = ['workiz_client_id' => $client->client_id, 'franchise_id' => $client->franchise_id, 'postal_code' => $client->postal_code, 'customers' => $same['zip_unreadable']];
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
                    // A placeholder typed to satisfy a required box ("no@gmail.com",
                    // a relay line on dozens of strangers) is nobody's contact:
                    // Customers' lists, so one never becomes an address or a number.
                    'email' => Customer::realEmail($client->email),
                    // Workiz sends bare digits and this column holds E.164 --
                    // the API, the database and Twilio all key on it, so a bare
                    // ten is the wrong value for calling somebody, not just an
                    // unformatted one.
                    'phone' => Customer::realPhone(Lead::e164($client->phone)),
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
                    // When they became a customer, as near as Workiz can say:
                    // the day their earliest job was booked. Without it every
                    // customer a catch-up makes counts as new that day (the
                    // dashboard reads this, b4606536). Set once, like the rest.
                    'customer_since' => $this->firstBooked($client),
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

        // Every adoption listed, so a wrong one can be found and undone; and,
        // created as before for Customers to look at, the clients that matched
        // several people or whose zip could not be read.
        if (($adopted !== [] || $several !== [] || $unreadable !== []) && ! $dryRun) {
            $audit->log('customer.workiz_clients_adopted', metadata: [
                'source' => 'workiz',
                'rule' => 'same phone, name and five-digit zip',
                'adopted' => $adopted,
                'matched_several' => $several,
                'zip_unreadable' => $unreadable,
            ]);
        }

        if ($adopted !== []) {
            $this->info(($dryRun ? '[dry run] ' : '').count($adopted).' Workiz clients adopted by the customer they already are.');
        }

        return $created;
    }

    /**
     * The live customer this Workiz client already is: the same phone, name
     * and five-digit zip, in any branch -- the rule Customers set for twins,
     * with their three points of 2026-10-05:
     *
     *  - the phone compared as ten national digits, through Customer's own
     *    realPhone(), so a placeholder line added there later matches nobody
     *    here either; `customers.phone` holds E.164 and, on 12 legacy rows,
     *    bare ten digits (measured that day), so both shapes are asked for;
     *  - the zip as its first five digits, so ZIP+4 is the same place; one
     *    that reads shorter -- every Massachusetts zip starts with a 0 a
     *    spreadsheet can drop -- is listed, never padded into a guess;
     *  - several matches: the one in the client's own branch when exactly one
     *    is, otherwise none. Never the newest or the first: that fallback sent
     *    the Memberships offer to a stranger's house.
     *
     * A placeholder street matches nobody: staff and test records share it.
     *
     * @return array{customer: ?Customer, several: list<string>, zip_unreadable: list<string>}
     */
    private function samePerson(WorkizClient $client): array
    {
        $none = ['customer' => null, 'several' => [], 'zip_unreadable' => []];
        $phone = Customer::realPhone(Lead::e164($client->phone));
        $name = $client->name ?: $client->company;
        $norm = fn (?string $value) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)));
        $zip = fn (?string $value) => substr(preg_replace('/\D+/', '', (string) $value), 0, 5);
        // The org's list (Customers, 11-04: no constants), in the same street form as the match.
        $streets = array_map(fn (string $s) => WorkizTwins::street($s), Customer::placeholders('customers.placeholder_streets'));
        $placeholder = fn (?string $street) => in_array(WorkizTwins::street($street), $streets, true);

        if ($phone === null || preg_match('/^\+1\d{10}$/', $phone) !== 1 || ($name ?? '') === '' || $placeholder($client->address)) {
            return $none;
        }

        $people = Customer::query()
            ->whereIn('phone', [$phone, substr($phone, 2)])
            ->get()
            ->filter(fn (Customer $c) => $norm($c->name) === $norm($name) && ! $placeholder($c->address_line1))
            ->values();

        if (strlen($zip($client->postal_code)) < 5) {
            return ['zip_unreadable' => $people->modelKeys()] + $none;
        }

        $matches = $people->filter(fn (Customer $c) => $zip($c->postal_code) === $zip($client->postal_code))->values();

        if ($matches->count() > 1) {
            $own = $matches->where('owning_franchise_id', $client->franchise_id)->values();
            $matches = $own->count() === 1 ? $own : $matches;
        }

        return $matches->count() === 1
            ? ['customer' => $matches->first()] + $none
            : ['several' => $matches->count() > 1 ? $matches->modelKeys() : []] + $none;
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

                    // Workiz's labels, through Job's setters, which tidy the words.
                    'job_type' => $this->label($mirrored, 'JobType'),
                    'sub_status' => $this->label($mirrored, 'SubStatus'),
                    'tags' => is_array($mirrored->payload['Tags'] ?? null) ? $mirrored->payload['Tags'] : null,

                    // The naive 'Y-m-d H:i:s' string, handed straight to
                    // FranchiseWallTime's cast. Workiz sends no zone — `Timezone`
                    // is absent from every real payload — so building a Carbon
                    // first would arrive pre-shifted and the cast could not tell.
                    'scheduled_for' => $mirrored->scheduled_for?->format('Y-m-d H:i:s'),

                    'amount_minor' => $this->minor($mirrored->payload['JobTotalPrice'] ?? null),
                    'currency' => strtoupper($franchise->currency ?: 'USD'),

                    // The Jobs session's rule, called rather than copied:
                    // Workiz's area name if it is one of this franchise's areas,
                    // else the JOB's own zip (not the customer's, which is the
                    // client's latest address), else none. Set here; a later
                    // change in Workiz follows through areaInputs() below.
                    'service_area_id' => JobServiceArea::resolve($franchise, $mirrored->service_area, $mirrored->postal_code),
                ]);

                // Arriving already Done is a move INTO an earned status, and it
                // is stamped at Workiz's own moment — the same moment the Jobs
                // session's one-time backfill uses, so the two paths agree.
                // Nothing is stamped while the organization names no earned
                // status, which is correct: it only acts on the way in. With
                // no Workiz moment it stays UNSTAMPED: markEarnedIfDue would
                // read null as now and file old work under this month.
                $moment = $this->workizMoment($mirrored, $franchise->timezone);

                if ($moment !== null) {
                    $job->markEarnedIfDue($moment);
                }

                // Born when and by whom Workiz says (Jobs, 2026-10-27; the same
                // rule as BackfillWorkizJobCreated). CreatedDate is the branch's
                // wall clock; the person is a Workiz team member of this branch
                // linked to an EzBizi user, matched by name.
                $by = trim((string) ($mirrored->payload['CreatedBy'] ?? ''));
                $born = $mirrored->payload['CreatedDate'] ?? null;
                if (is_string($born) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $born)) {
                    $job->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $born, $franchise->timezone ?: 'America/New_York')->utc();
                }
                if ($by !== '') {
                    $job->created_by_label = $by;
                    $job->created_by_user_id = WorkizTeamMember::query()->where('franchise_id', $franchise->getKey())
                        ->whereNotNull('user_id')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($by)])->value('user_id');
                }

                $job->save();

                $mirrored->job_id = $job->getKey();
                $mirrored->projected = $this->followedValues($job) + ['team' => $this->team($job, $mirrored)];
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
    private const FOLLOWED = ['status', 'scheduled_for', 'amount_minor', 'performed_by_user_id', 'job_type', 'sub_status', 'tags', 'description'];

    /** Followed since 2026-11-02 (Jobs), description since 11-10; a row projected before has none recorded. */
    private const LABELS = ['job_type', 'sub_status', 'tags', 'description'];

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
    private function followWorkiz(EventEmitter $events, bool $dryRun): int
    {
        $followed = 0;
        $announce = [];
        $tagged = [];

        WorkizJob::query()
            ->select('workiz_jobs.*')
            ->join('jobs', 'jobs.id', '=', 'workiz_jobs.job_id')
            // Workiz's first technician, through the link to a user: performer()
            // below, in SQL. (franchise, remote_id) is unique, so one row at most.
            ->leftJoin('workiz_team_members', function ($j) {
                $j->on('workiz_team_members.franchise_id', '=', 'workiz_jobs.franchise_id')
                    ->whereRaw("workiz_team_members.remote_id = JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.Team[0].id'))");
            })
            ->where(function ($q) {
                $q->whereColumn('workiz_jobs.status', '<>', 'jobs.status')
                    ->orWhereRaw('NOT (workiz_jobs.scheduled_for <=> jobs.scheduled_for)')
                    ->orWhereRaw("NOT (ROUND(JSON_EXTRACT(workiz_jobs.payload, '$.JobTotalPrice') * 100) <=> jobs.amount_minor)")
                    // Workiz's area inputs moved since they were recorded, or
                    // were never recorded: areaInputs() below, in SQL. Measured
                    // to agree with PHP's strict test, null against '' included.
                    ->orWhereRaw("NOT (JSON_EXTRACT(workiz_jobs.projected, '$.area_inputs') <=> JSON_ARRAY(workiz_jobs.service_area, workiz_jobs.postal_code))")
                    ->orWhereRaw('NOT (workiz_team_members.user_id <=> jobs.performed_by_user_id)')
                    // ponytail: untidy Workiz words (stray spaces) re-load each run; the decision below is tidy.
                    ->orWhereRaw("NOT (JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.JobType')) <=> jobs.job_type)")
                    ->orWhereRaw("NOT (JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.SubStatus')) <=> jobs.sub_status)")
                    ->orWhereRaw("NOT (JSON_EXTRACT(workiz_jobs.payload, '$.Tags') <=> CAST(jobs.tags AS JSON))")
                    ->orWhereRaw("NOT (TRIM(JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.JobNotes'))) <=> jobs.description)")
                    // Workiz's team moved since it was handed to Scheduling: team() below.
                    ->orWhereRaw("NOT (JSON_EXTRACT(workiz_jobs.projected, '$.team') <=> JSON_EXTRACT(workiz_jobs.payload, '$.Team[*].id'))");
            })
            ->chunkById(500, function ($mirrored) use (&$followed, &$announce, &$tagged, $dryRun) {
                $jobs = Job::query()->whereIn('id', $mirrored->pluck('job_id'))->get()->keyBy('id');

                foreach ($mirrored as $row) {
                    $job = $jobs->get($row->job_id);

                    if ($job === null) {
                        continue;
                    }

                    // A row projected before the performer was followed has none
                    // recorded as ours: a performer it holds was put there by a
                    // person, and a null one is free to fill.
                    $last = ($row->projected ?? $this->followedValues($job)) + ['performed_by_user_id' => null];
                    $now = $this->followedValues($job);
                    $workiz = [
                        'status' => $row->status,
                        'scheduled_for' => $row->scheduled_for?->format('Y-m-d H:i:s'),
                        'amount_minor' => $this->minor($row->payload['JobTotalPrice'] ?? null),
                        'performed_by_user_id' => $this->performer($row),
                        'description' => $this->text($row->payload['JobNotes'] ?? null),
                    ] + $this->labels($row);

                    // No label recorded as ours yet: the job's own is ours only
                    // when it already agrees with Workiz (the labels backfill);
                    // otherwise a person's, or free to fill when empty.
                    foreach (self::LABELS as $field) {
                        if (! array_key_exists($field, $last)) {
                            // Workiz's notes grow after a job is copied (Jobs, 11-10:
                            // JOB-57727 held the first three lines): a description that
                            // is the start of Workiz's notes is still ours.
                            $grew = $field === 'description' && $now[$field] !== null && $workiz[$field] !== null
                                && str_starts_with($workiz[$field], $now[$field]);
                            $last[$field] = $grew || $this->same($now[$field], $workiz[$field]) ? $now[$field] : null;
                        }
                    }

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

                    /*
                     * THE SERVICE AREA FOLLOWS WORKIZ'S INPUTS, not a resolved id:
                     * re-resolving every row every run would be thousands of
                     * queries, and an id cannot be compared in SQL. So the inputs
                     * last seen are kept in `projected`, and only a change in them
                     * re-resolves, through the Jobs session's rule, called.
                     *
                     * Never over a person: `service_area_chosen_at` is set when
                     * somebody picks the area, and then Workiz no longer moves it.
                     *
                     * A row with no inputs recorded ONLY records them. Its area may
                     * be the Jobs session's backfill, which folded retired names
                     * (East Connecticut) that resolve() never will; re-resolving it
                     * would put it back to none.
                     */
                    $inputs = $this->areaInputs($row);
                    $seen = $last['area_inputs'] ?? null;
                    $moveArea = $seen !== null && $seen !== $inputs && $job->service_area_chosen_at === null;

                    if ($dryRun) {
                        $followed += ($changes === [] && ! $moveArea) ? 0 : 1;

                        continue;
                    }

                    DB::transaction(function () use ($row, $job, $changes, $moveArea, $inputs, $last, $now, &$announce, &$tagged) {
                        if ($moveArea) {
                            $job->service_area_id = JobServiceArea::resolve($job->franchise, $row->service_area, $row->postal_code);
                        }

                        if ($changes !== []) {
                            $job->forceFill(array_key_exists('tags', $changes)
                                ? ['tags' => $changes['tags'] === null ? null : json_decode($changes['tags'], true)] + $changes : $changes);

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
                        }

                        // Writes nothing when nothing moved.
                        $job->save();

                        if (array_key_exists('status', $changes)
                            && $this->happenedRecently($row, $job->franchise?->timezone)) {
                            $announce[] = [$job, $now['status'], $changes['status']];
                        }

                        // Automations, 2026-11-03: the same guard as status, so a backfill texts nobody.
                        if (array_key_exists('tags', $changes)
                            && $this->happenedRecently($row, $job->franchise?->timezone)) {
                            $tagged[] = [$job, json_decode((string) ($now['tags'] ?? 'null'), true) ?? [], json_decode((string) ($changes['tags'] ?? 'null'), true) ?? []];
                        }

                        // What we have written, field by field. A field a
                        // person changed is NOT recorded as ours: `last` keeps
                        // our old write, so their value goes on not matching and
                        // stays theirs. The area inputs are always the latest
                        // seen: they are what the next change is measured from.
                        $row->projected = array_merge($last, array_intersect_key($changes, $last), ['area_inputs' => $inputs],
                            ['team' => $this->team($job, $row)]);
                        $row->save();
                    });

                    $followed += ($changes === [] && ! $moveArea) ? 0 : 1;
                }
            }, 'workiz_jobs.id', 'id');

        if ($followed > 0) {
            $this->info($followed.' projected jobs followed a change in Workiz.');
        }

        $this->announce($events, $announce);
        $this->announceTags($events, $tagged);

        return $followed;
    }

    /**
     * How recently Workiz must have changed a status for it to be announced.
     *
     * The catch-up after 0.0.0264 follows about 1,300 past jobs whose status
     * changed weeks or months ago. Announcing those would text customers about
     * work finished in August. So what decides is WHEN WORKIZ SAYS it happened,
     * not when this pass noticed — a property of the row, not a flag somebody
     * can forget to set.
     *
     * Two hours must comfortably exceed the time a real change takes to arrive:
     * an upcoming job is swept every minute and followed within five, and a
     * past one waits in the re-read queue, which rotates in well under an hour
     * once the backlog is cleared. A change older than that is history.
     */
    private const ANNOUNCE_WITHIN_HOURS = 2;

    /**
     * Raise `job.status_changed` for the moves this pass made — or none at all.
     *
     * ── WHY IT IS RAISED HERE ───────────────────────────────────
     *
     * A-1 works in Workiz, so almost every real "went to Done" reaches EzBizi
     * through this pass, and a write through the model emits nothing: every
     * other `job.*` emit lives in JobsController. Without this, Jose's rule —
     * "when a job changes status to Done, send a text" — would never fire for
     * the work A-1 actually does.
     *
     * ── WHO IT IS FOR IS NOT IN THE EVENT ───────────────────────
     *
     * The payload is exactly JobsController's, `{before, after}`, and the Job
     * is the subject. A rule reaches the customer through the Automations
     * session's "The customer it is about" recipient, resolved at run time via
     * the job's customer — NOT through a phone number carried in the event,
     * because `automation_events` is declared as holding no personal data and
     * erasure never visits it. Their ruling, 2026-09-24; an earlier draft of
     * this pass did carry the number and was taken back out.
     *
     * ── ALL OR NOTHING ──────────────────────────────────────────
     *
     * The Automations session's ruling: a pass that finds more recent moves
     * than any real hour produces is a backfill, and announces NONE rather than
     * a subset. A partial flood is worse than none, because nobody can tell
     * which customers were texted. Decided after the pass, before a single
     * event exists, because an event cannot be taken back.
     *
     * @param  list<array{0: Job, 1: ?string, 2: string}>  $moves
     */
    private function announce(EventEmitter $events, array $moves): void
    {
        if ($moves === []) {
            return;
        }

        $limit = max(0, (int) $this->option('announce-limit'));

        if (count($moves) > $limit) {
            $this->warn(count($moves).' jobs changed status in Workiz in one pass — more than '.$limit
                .', so this is treated as a backfill and no automation is told.');

            return;
        }

        foreach ($moves as [$job, $before, $after]) {
            // Plain emit, not emitAfterCommit: this runs after the follow pass,
            // when every row's transaction has already committed, so there is
            // no rolled-back write for a rule to fire on.
            $events->emit('job.status_changed', $job, [
                'before' => ['status' => $before],
                'after' => ['status' => $after],
            ]);

            // JobsController's companion, under its exact condition: entering an
            // earned status from one that was not. Templates such as "Book next
            // year's visit" listen on job.completed, so leaving it out would make
            // Workiz jobs silently skip them. Silent today by construction —
            // A1-Group names no earned status yet.
            if (EarnedStatuses::isEarned($after, $job->franchise)
                && ! EarnedStatuses::isEarned((string) ($before ?? ''), $job->franchise)) {
                $events->emit('job.completed', $job, [
                    'before' => ['status' => $before],
                    'after' => ['status' => $after],
                ]);
            }
        }
    }

    /**
     * `job.tags_changed` for Workiz's tag moves, under announce()'s rules: only
     * recent moves reach here, and more than the limit in one pass is a
     * backfill that announces none.
     *
     * @param  list<array{0: Job, 1: list<string>, 2: list<string>}>  $moves
     */
    private function announceTags(EventEmitter $events, array $moves): void
    {
        if ($moves === [] || count($moves) > max(0, (int) $this->option('announce-limit'))) {
            return;
        }

        foreach ($moves as [$job, $before, $after]) {
            $events->emit('job.tags_changed', $job, ['before' => ['tags' => $before], 'after' => ['tags' => $after]]);
        }
    }

    /**
     * Whether Workiz stamped this row's last change recently enough to announce.
     * No stamp, no announcement: a text that should have gone and did not is
     * recoverable; one about a months-old job is not.
     */
    private function happenedRecently(WorkizJob $row, ?string $timezone): bool
    {
        $moment = $this->workizMoment($row, $timezone);

        return $moment !== null
            && $moment->greaterThanOrEqualTo(now()->subHours(self::ANNOUNCE_WITHIN_HOURS));
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
     * right for a change this pass has only just seen, wrong for a job that
     * ARRIVES already earned, so creation does not stamp on null.
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
     * Who does the job, in EzBizi's terms: Workiz's FIRST team member, through
     * the technician link (Scheduling > Settings > Schedule, or the team sync's
     * email match), in the job's own branch. Nobody when that member is not
     * linked to a user. The Field session reads it for arrival, routes and
     * geocoding.
     */
    private function performer(WorkizJob $row): ?string
    {
        $id = $row->payload['Team'][0]['id'] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        return WorkizTeamMember::query()
            ->where('franchise_id', $row->franchise_id)
            ->where('remote_id', (string) $id)
            ->value('user_id');
    }

    /**
     * What Workiz says that places a job in a service area: its area name and
     * the job's own zip. Compared strictly, like the SQL in followWorkiz().
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function areaInputs(WorkizJob $row): array
    {
        return [$row->service_area, $row->postal_code];
    }

    /**
     * The followed fields as the `jobs` row holds them, in the same shapes the
     * projection writes — the raw wall-clock string for the schedule, never a
     * Carbon, because the cast stores naive time. WorkizJobWriter::create()
     * records the same when it links a job EzBizi made in Workiz.
     *
     * @return array{status: ?string, scheduled_for: ?string, amount_minor: ?int}
     */
    /**
     * Workiz's technicians on the job, handed to Scheduling's native roster
     * (JobTechnicians::fromWorkiz keeps only its source=workiz rows and leaves
     * a job alone once EzBizi assigned anyone; Scheduling, 2026-10-27).
     * Answers the ids handed over, as the payload holds them, or null for none,
     * so the follow pass can tell when the team moved.
     */
    private function team(Job $job, WorkizJob $row): ?array
    {
        $team = array_values(array_filter((array) ($row->payload['Team'] ?? []), fn ($t) => is_array($t) && isset($t['id'])));
        $ids = $team === [] ? null : array_column($team, 'id');

        // ponytail: guarded until Scheduling's roster (ce7313008) is on main beside this.
        if ($ids !== ($row->projected['team'] ?? null) && class_exists(\App\Support\Scheduling\JobTechnicians::class)) {
            \App\Support\Scheduling\JobTechnicians::fromWorkiz($job, $team);
        }

        return $ids;
    }

    public static function followedValues(Job $job): array
    {
        return [
            'status' => $job->status,
            'scheduled_for' => $job->getRawOriginal('scheduled_for'),
            'amount_minor' => $job->amount_minor,
            'performed_by_user_id' => $job->performed_by_user_id,
            'job_type' => $job->job_type,
            'sub_status' => $job->sub_status,
            // One JSON spelling, so same() compares it as a string (MySQL re-spaces a JSON column).
            'tags' => $job->tags === null ? null : json_encode($job->tags, JSON_UNESCAPED_UNICODE),
            'description' => $job->description,
        ];
    }

    /** Workiz's labels as Job's setters would store them. */
    private function labels(WorkizJob $row): array
    {
        $tidy = new Job;
        $tidy->tags = is_array($row->payload['Tags'] ?? null) ? $row->payload['Tags'] : null;

        return ['job_type' => $this->label($row, 'JobType'), 'sub_status' => $this->label($row, 'SubStatus'),
            'tags' => $tidy->tags === null ? null : json_encode($tidy->tags, JSON_UNESCAPED_UNICODE)];
    }

    private function label(WorkizJob $row, string $key): ?string
    {
        $value = $row->payload[$key] ?? null;

        return is_string($value) ? (new Job(['job_type' => $value]))->job_type : null;
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

    /**
     * The day this client's earliest Workiz job was booked. `booked_at` is
     * Workiz's CreatedDate as the account wrote it, its own wall clock, so its
     * date part is the branch's local day.
     */
    private function firstBooked(WorkizClient $client): ?string
    {
        $first = WorkizJob::query()
            ->where('franchise_id', $client->franchise_id)
            ->where('client_id', $client->client_id)
            ->min('booked_at');

        return $first === null ? null : substr((string) $first, 0, 10);
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
