<?php

namespace App\Console\Commands;

use App\Models\InspectionDefinition;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Models\Organization;
use App\Models\User;
use App\Support\Inspection\BoundAnswers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * File a few inspections on the demonstration tenant, so the module has
 * something to show.
 *
 * ── Why this exists ─────────────────────────────────────────────
 *
 * `review:seed-organization` gives the demo tenant a PUBLISHED definition, so
 * the Builder and New Inspection both open onto something real. Nothing has
 * ever been submitted against it, so Generated Reports opens onto an empty
 * list — which is correct behaviour and looks exactly like a broken screen. An
 * App Store frame captured there on 2026-09-04 was almost entirely white space
 * and was rejected for it.
 *
 * ── Why it is a separate command ────────────────────────────────
 *
 * `inspection:seed-demo` refuses any database not named `*testing*`, and that
 * guard is not squeamishness: on 2026-08-06 a `--env=testing` wipe fell through
 * to `.env` for want of a `.env.testing`. Widening it so it could reach the
 * demo tenant would mean widening it for every organization on the production
 * database, A1-Group's real customers included. It stays exactly as strict as
 * it is.
 *
 * `review:seed-organization` is the other candidate and refuses to run at all
 * once the organization exists, by design — re-running it must never change a
 * password a reviewer is holding.
 *
 * ── Why it takes no organization argument ───────────────────────
 *
 * The authorisation for this was specific: the demo organization, never
 * A1-Group. An argument would put the wrong tenant one typo away, on the one
 * database where that is not recoverable. The name is a constant, so the blast
 * radius is a property of the code rather than of what somebody typed.
 *
 * Idempotent: it does nothing at all if any report already exists.
 */
class InspectionSeedDemoReports extends Command
{
    protected $signature = 'inspection:seed-demo-reports
                            {--live : Allow the production database}
                            {--force : Skip the confirmation}';

    protected $description = 'File a few inspection reports on the demonstration tenant';

    /** The only organization this command can ever write to. */
    private const ORG_NAME = 'EzBizi Demo';

    /**
     * The same organization, by id, checked on production only.
     *
     * A name is editable from Settings and is not unique across the platform,
     * so on the one database where a wrong tenant is unrecoverable the name is
     * not enough on its own.
     *
     * Measured by the Apple session against production on 2026-09-04 —
     * `Organization::all(['id','name'])` as www-data returned exactly two rows,
     * A1-Group and this one — and written into `docs/demo-tenant-gaps.md` at
     * c576b5e8, which is where I read it. I have not queried production myself.
     *
     * That is tolerable here for a reason that does NOT generalise: this check
     * is FAIL-CLOSED. A wrong id refuses the run; it cannot redirect a write.
     * A gate that instead PROCEEDS on a matching id has to be measured
     * first-hand, because there a wrong constant sends writes somewhere.
     *
     * Not checked outside production — a test tenant has its own id, and a
     * constant that only holds on one machine would make this untestable.
     */
    private const PRODUCTION_ORG_ID = '01a03e4f-f9a1-7012-9515-96bc9260d2f8';

    private const HOW_MANY = 3;

    /**
     * Plausible answers for the demo definition's own field keys.
     *
     * Looked up by key rather than positional: the definition is editable from
     * the Builder like any other, and a positional list would quietly write a
     * creosote reading into a flashing field the first time somebody reordered
     * a screen. Anything not named here gets the generic line.
     */
    private const SAID = [
        'crown' => 'Sound. Minor hairline cracking at the north-west corner, monitored.',
        'cap' => 'Fitted, secure, mesh intact.',
        'flashing' => 'Sealed and watertight.',
        'liner' => 'Clay tile, continuous, no spalling visible from the top.',
        'creosote' => 'Stage 1, light and flaky. Within normal range for the season.',
        'notes' => 'No defects requiring immediate attention. Recommend a sweep before next heating season.',
    ];

    public function handle(TenantContext $tenant): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($database === 'ezbizi' && ! $this->option('live')) {
            $this->error('Refusing to touch production without --live.');

            return self::FAILURE;
        }

        $organization = Organization::query()->where('name', self::ORG_NAME)->first();

        if ($organization === null) {
            $this->error('No organization named '.self::ORG_NAME.' on ['.$database.'].');
            $this->line('Run review:seed-organization first — this only adds reports to it.');

            return self::FAILURE;
        }

        /*
         * The semantically correct question, and the only one of the three
         * that is about what the tenant IS rather than what it is called or
         * where it sits. `organizations.is_demo` has existed since the
         * foundation migration and `ProvisionOrganization` sets it; both this
         * command and the Apple session's had reinvented it, one with a name
         * and one with a hard-coded id, before either of us looked.
         *
         * Checked on every database, not only production. A command that fills
         * "the demo tenant" has no business writing to a tenant that says it is
         * not one, wherever it is running.
         */
        if ($organization->is_demo !== true) {
            $this->error(self::ORG_NAME.' is not flagged as a demo organization (is_demo is not true).');
            $this->line('Refusing. This command only ever fills a tenant that says it is a demonstration one.');

            return self::FAILURE;
        }

        if ($database === 'ezbizi' && $organization->id !== self::PRODUCTION_ORG_ID) {
            $this->error('An organization named '.self::ORG_NAME.' is on production with an id this command does not know.');
            $this->line('Expected '.self::PRODUCTION_ORG_ID.', found '.$organization->id.'.');
            $this->line('Re-measure before changing the constant — two tenants sharing a name is the case this refuses.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'File '.self::HOW_MANY.' inspection reports on "'.self::ORG_NAME.'" in database ['.$database.']?'
        )) {
            return self::FAILURE;
        }

        return $tenant->run($organization, fn () => $this->fileThem($organization->id));
    }

    private function fileThem(string $organizationId): int
    {
        if (InspectionReport::query()->exists()) {
            $this->warn(self::ORG_NAME.' already has reports. Nothing to do.');

            return self::SUCCESS;
        }

        $definition = InspectionDefinition::query()
            ->whereNotNull('published_at')
            ->whereNotNull('current_version_id')
            ->with('currentVersion')
            ->first();

        if ($definition === null || $definition->currentVersion === null) {
            $this->error('No published inspection on '.self::ORG_NAME.'. Nothing to submit against.');

            return self::FAILURE;
        }

        /*
         * Through a job, because that is how the module is actually used, and
         * because the franchise an automation event is scoped to comes from the
         * job before it falls back to the customer. Reports filed with no job
         * would demonstrate the weaker path.
         */
        $jobs = Job::query()->with('customer')->whereNotNull('customer_id')->take(self::HOW_MANY)->get();

        if ($jobs->isEmpty()) {
            $this->error('No jobs with a customer on '.self::ORG_NAME.'.');

            return self::FAILURE;
        }

        $submitter = User::query()->value('id');

        if ($submitter === null) {
            $this->error('No user on '.self::ORG_NAME.' to submit as.');

            return self::FAILURE;
        }

        $version = $definition->currentVersion;
        $screens = is_array($version->screens) ? $version->screens : [];

        /*
         * All of them or none of them.
         *
         * The Apple session pointed out that "return early if any report
         * exists" is not the same property as a per-row firstOrCreate: it makes
         * a second full run a no-op, which is what was wanted, but it also
         * makes a second run unable to FINISH a first one that died halfway.
         * Three reports written one at a time, failing on the second, would
         * leave the tenant holding one forever, with the early return refusing
         * to complete it and nothing saying so.
         *
         * A transaction is the answer rather than firstOrCreate because it
         * removes the state instead of healing it — a failed run leaves zero
         * reports, so the early return is telling the truth again and the fix
         * is to run it a second time. firstOrCreate would also need a natural
         * key per report, and there isn't an honest one: a tenant may legibly
         * have two inspections of the same job.
         */
        DB::transaction(function () use ($jobs, $definition, $version, $screens, $submitter, $organizationId) {
            foreach ($jobs as $index => $job) {
                InspectionReport::create([
                    'organization_id' => $organizationId,
                    'customer_id' => $job->customer_id,
                    'job_id' => $job->id,
                    'inspection_definition_id' => $definition->id,
                    'inspection_definition_version_id' => $version->id,
                    'submitted_by' => $submitter,
                    // Spread across recent weeks, so the list is ordered by
                    // something rather than three rows sharing one timestamp.
                    'submitted_at' => now()->subDays(($index + 1) * 9),
                    'answers' => BoundAnswers::freeze(
                        $job->customer,
                        $job,
                        $screens,
                        $this->answersFor($screens),
                    ),
                    'layout_preset' => $version->layout_preset ?? 'step_strip',
                    'definition_name' => $definition->name,
                ]);
            }
        });

        $this->info('Filed '.$jobs->count().' reports on '.self::ORG_NAME.'.');

        return self::SUCCESS;
    }

    /**
     * An answer for every field the published version actually carries.
     *
     * Read off the version rather than written as a literal, so a definition
     * edited in the Builder still produces a complete report instead of one
     * with blanks where the new questions are.
     *
     * @param  list<array<string, mixed>>  $screens
     * @return array<string, string>
     */
    private function answersFor(array $screens): array
    {
        $answers = [];

        foreach ($screens as $screen) {
            foreach ((is_array($screen['fields'] ?? null) ? $screen['fields'] : []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $key = (string) ($field['key'] ?? '');

                if ($key === '') {
                    continue;
                }

                $answers[$key] = self::SAID[$key] ?? 'Checked, no defects noted.';
            }
        }

        return $answers;
    }
}
