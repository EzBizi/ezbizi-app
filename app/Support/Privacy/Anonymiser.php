<?php

namespace App\Support\Privacy;

use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Erase one person, everywhere, without erasing the records that refer to them
 * (§9's "purge personal data — anonymise in place").
 *
 * ── What it is derived from, and why that matters ────────────────
 *
 * Nothing in this class names a table or a column. It walks the models that
 * implement HoldsPersonalData, reads the Erasure on each declared field, and
 * does what it says. That is the whole design, and the alternative is the reason
 * for it: a purge holding its own list of tables and columns is a second copy of
 * the data inventory, kept by hand, and it goes stale in the one way nobody
 * notices — somebody adds a personal-data column, updates the inventory because
 * the interface makes them, and does not update the purge because nothing does.
 * The purge then reports success while leaving the new column untouched, which
 * is worse than not having one.
 *
 * So the rule is: if it is not declared, it is not erased, and a column that
 * exists without a declaration is caught by
 * PersonalDataPurgeTest::test_every_erasable_entry_names_columns_that_exist
 * rather than discovered during a rights request.
 *
 * ── Anonymise in place, not delete ───────────────────────────────
 *
 * §9 resolves the soft-delete tension by keeping the record and blanking the
 * person: "keep the invoice and its amounts, blank the name, email, phone and
 * address". So the default is an UPDATE, every foreign key still points where it
 * pointed, and a job history does not develop holes. Rows are removed only where
 * a declaration says `Erasure::drop` — a session, a notification — because those
 * rows are nothing but the person and blanking them leaves litter rather than an
 * anonymous record.
 *
 * ── What it deliberately does NOT do ─────────────────────────────
 *
 * It is not an endpoint. §16 defers the legal question of who may make a rights
 * request and how their identity is verified, and an unverified erase button is
 * a denial-of-service on somebody else's records — the mechanism exists (§9's
 * requirement), the request-handling around it is the deferred part. It is
 * reached through `ezbizi:purge-personal-data`, which is audited like everything
 * else, and the day §16 is answered the answer plugs into this.
 *
 * ── The audit entry must not undo the purge ──────────────────────
 *
 * AuditLogger captures `target_label` from the record it is given, which for a
 * user is their name and email. Logging the purge that way would write the
 * erased name into an append-only table three seconds after removing it, and
 * §9 names audit logs as a place personal data hides. So the entry is written
 * with no target model and identifies the subject by type and id — which is
 * enough to answer "was this person purged, by whom, when" and holds nothing
 * that erasure was supposed to take away.
 */
class Anonymiser
{
    /** @var array<string, array<string, bool>> table => column => nullable */
    private array $nullability = [];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly FileStore $files,
        private readonly DeclaringModels $models,
    ) {}

    /**
     * Erase everything declared about this person.
     *
     * `$dryRun` walks and reports without writing, which is what somebody
     * answering a rights request should look at first: the report says which
     * tables hold rows about them, what would be blanked, what would be removed
     * and — the part worth reading before pressing on — what will SURVIVE and
     * why.
     */
    public function purge(Model $subject, bool $dryRun = false): array
    {
        $organization = $this->organizationOf($subject);

        return $this->tenant->run($organization, function () use ($subject, $dryRun, $organization) {
            $tables = [];

            foreach ($this->models->holdingPersonalData() as $class) {
                $tables[] = $this->purgeOne($class, $subject, $dryRun);
            }

            $report = [
                'subject' => [
                    // Type and id only. See the class docblock: naming the person
                    // in the record of their own erasure defeats the erasure.
                    'type' => class_basename($subject),
                    'id'   => $subject->getKey(),
                ],
                'organization' => ['id' => $organization->getKey(), 'name' => $organization->name],
                'dry_run'  => $dryRun,
                'tables'   => $tables,
                'caveats'  => $this->caveats(),
                'purged_at' => now()->toIso8601String(),
            ];

            if (! $dryRun) {
                /*
                 * §9: "and log the purge itself". The counts go in and the values
                 * do not — "we blanked customers.name" is the accountable fact,
                 * and what the name WAS is precisely what was being removed.
                 */
                $this->audit->log('personal_data.purged', metadata: [
                    'subject_type' => class_basename($subject),
                    'subject_id'   => $subject->getKey(),
                    'tables'       => array_map(
                        fn (array $t) => array_intersect_key($t, array_flip(
                            ['table', 'rows', 'columns_erased', 'rows_dropped', 'files_removed', 'refusals'],
                        )),
                        array_values(array_filter($tables, fn ($t) => $t['applies'])),
                    ),
                ]);
            }

            return $report;
        });
    }

    /**
     * One table's worth.
     *
     * @param  class-string<Model&HoldsPersonalData>  $class
     */
    private function purgeOne(string $class, Model $subject, bool $dryRun): array
    {
        $table = (new $class)->getTable();

        $line = [
            'model'          => class_basename($class),
            'table'          => $table,
            'applies'        => false,
            'rows'           => 0,
            'columns_erased' => [],
            'rows_dropped'   => 0,
            'files_removed'  => 0,
            'refusals'       => [],
            'kept'           => $this->keptEntries($class),
        ];

        $query = $class::personalDataAbout($subject);

        if ($query === null) {
            // Not "none found" — this table never holds anything about this kind
            // of person at all. A different answer, printed differently.
            return $line;
        }

        $line['applies'] = true;

        $entries = $class::personalDataHeld();
        $drops   = array_values(array_filter(
            $entries, fn (PersonalDataEntry $e) => $e->erasure->disposition === Erasure::DROP,
        ));

        $rows = $query->get();
        $line['rows'] = $rows->count();

        if ($rows->isEmpty()) {
            return $line;
        }

        return $drops !== []
            ? $this->dropRows($line, $rows, $dryRun)
            : $this->eraseColumns($line, $rows, $entries, $table, $dryRun);
    }

    /**
     * The row itself goes, and its bytes with it.
     *
     * Deletes are done one at a time through the model rather than as one
     * `->delete()` on the query, because a model may refuse. Attachment does:
     * a file a signature fingerprints cannot be removed, and the subject holding
     * it is the only thing that knows which of its files those are. That refusal
     * is REPORTED rather than caught and ignored — "we could not erase this and
     * here is the reason" is an answer somebody can act on, and a purge that
     * swallowed it would report a completeness it had not achieved.
     *
     * ── No `attachment.removed` entry, deliberately ──────────────
     *
     * AttachmentsController writes one on its own delete path, carrying
     * `original_name` and the checksum, because after a manual deletion there is
     * nothing left to look at. Reaching for the same entry here would write
     * "marta-licence.pdf" into an append-only table as part of erasing Marta —
     * §9's warning about audit logs, produced by the purge itself. The purge's
     * own entry records the counts per table instead, which is the accountable
     * fact without being the thing being erased.
     */
    private function dropRows(array $line, $rows, bool $dryRun): array
    {
        foreach ($rows as $row) {
            $files = $this->filesOf($row);

            if ($dryRun) {
                $line['rows_dropped']++;
                $line['files_removed'] += count($files);

                continue;
            }

            try {
                $row->delete();
            } catch (RuntimeException $e) {
                $line['refusals'][] = [
                    'id'      => $row->getKey(),
                    'because' => $e->getMessage(),
                ];

                continue;
            }

            $line['rows_dropped']++;

            // Only after the row is gone. Bytes deleted first and a delete that
            // then failed would leave a row pointing at nothing, which reads to
            // every later caller as corruption rather than as erasure.
            foreach ($files as $file) {
                $this->files->delete($file['path'], $file['disk']);
                $line['files_removed']++;
            }
        }

        return $line;
    }

    /**
     * The row stays; the declared columns are blanked.
     *
     * Written through the model with `saveQuietly()`, deliberately:
     *
     *   - the CASTS apply, which is the only reason an encrypted column can be
     *     erased at all. A query-builder update would put the stand-in into
     *     `onboarding_answers.value` as plaintext, and the next read would throw
     *     DecryptException on a row nobody could then repair.
     *
     *   - the EVENTS do not, which is what lets `audit_events` be anonymised.
     *     That model refuses `updating` because history must not be editable by
     *     application code — and its own retention docblock states the exception
     *     this relies on: "erasing somebody from here means anonymising the actor
     *     label rather than deleting rows". This is the one writer permitted to
     *     do it, in the same way ExpireAuditEvents is the one writer permitted to
     *     delete.
     *
     * That exception is narrow because it is bounded by the declarations, not by
     * this class: every genuinely immutable field — a signature's signer_label, a
     * rendered document's hash — is declared `Erasure::keep`, so this code never
     * reaches it.
     * PersonalDataPurgeTest::test_the_evidentiary_tables_keep_everything is what
     * holds that boundary in place.
     */
    private function eraseColumns(array $line, $rows, array $entries, string $table, bool $dryRun): array
    {
        $erasable = array_values(array_filter(
            $entries, fn (PersonalDataEntry $e) => $e->erasure->disposition === Erasure::ERASE,
        ));

        if ($erasable === []) {
            return $line;
        }

        /*
         * What each column BECOMES, worked out once from the schema rather than
         * per row: it is the same answer every time, and reporting it from the
         * last row processed would make an empty result print an empty plan.
         *
         * The stand-in shown here is the template, `{ref}` and all. Rendering one
         * row's resolved value would put a row key into the report as if it were
         * the policy.
         */
        $plan = [];

        foreach ($erasable as $entry) {
            foreach ($entry->columns() as $column) {
                /*
                 * A stand-in the model stated wins over the schema; otherwise the
                 * schema decides. See Erasure: `audit_events.actor_label` is
                 * nullable and must still read "Removed account", because an empty
                 * label in a log that writes 'system' for an unattributed action
                 * is ambiguous rather than erased.
                 */
                $plan[$column] = $entry->erasure->hasStatedStandIn()
                    || ! $this->isNullable($table, $column)
                        ? $entry->erasure
                        : null;
            }
        }

        // The template, `{ref}` and all — not one row's resolved value, which
        // would put a row key into the report as if it were the policy.
        $line['columns_erased'] = array_map(
            fn (?Erasure $e) => $e === null ? 'null' : ($e->standIn ?? Erasure::DEFAULT_STAND_IN),
            $plan,
        );

        foreach ($rows as $row) {
            $values = array_map(
                fn (?Erasure $e) => $e?->standInFor((string) $row->getKey()),
                $plan,
            );

            $files = $this->filesOf($row);

            if ($dryRun) {
                $line['files_removed'] += count($files);

                continue;
            }

            $row->forceFill($values)->saveQuietly();

            foreach ($files as $file) {
                $this->files->delete($file['path'], $file['disk']);
                $line['files_removed']++;
            }
        }

        return $line;
    }

    /**
     * Bytes this row is the index to, which go when its personal data does.
     *
     * Optional, and asked for with `method_exists` in the same way DataInventory
     * asks for `narrowToOrganization`: most tables have no files, and making
     * every model implement a method that returns `[]` would be ceremony that
     * teaches the next reader nothing.
     *
     * It exists because erasing `users.avatar_path` removes the POINTER to a
     * photograph of somebody's face and leaves the photograph on disk — §9 lists
     * "uploaded files" among the places a purge misses, and this is that miss in
     * its most literal form.
     *
     * @return array<array{disk: ?string, path: string}>
     */
    private function filesOf(Model $row): array
    {
        if (! method_exists($row, 'personalDataFiles')) {
            return [];
        }

        return array_values(array_filter(
            $row->personalDataFiles(),
            fn (array $f) => ($f['path'] ?? null) !== null && $f['path'] !== '',
        ));
    }

    /** What survives here, and the reason each entry gave. */
    private function keptEntries(string $class): array
    {
        $kept = [];

        foreach ($class::personalDataHeld() as $entry) {
            if ($entry->erasure->disposition === Erasure::KEEP) {
                $kept[] = ['field' => $entry->field, 'because' => $entry->erasure->because];
            }
        }

        return $kept;
    }

    /**
     * Does the schema permit NULL here?
     *
     * Read from the database rather than declared on the model. Nullability is a
     * fact the schema already holds, and a second copy of it in PHP would be a
     * copy that survives the migration making a column nullable — after which the
     * purge would keep writing "Removed" into a column that could simply have
     * been emptied, which is retained data dressed as erased data.
     */
    private function isNullable(string $table, string $column): bool
    {
        if (! isset($this->nullability[$table])) {
            $this->nullability[$table] = collect(Schema::getColumns($table))
                ->mapWithKeys(fn (array $c) => [$c['name'] => (bool) $c['nullable']])
                ->all();
        }

        if (! array_key_exists($column, $this->nullability[$table])) {
            throw new RuntimeException(sprintf(
                "%s declares personal data in a column '%s' that does not exist. The purge "
                .'reads the inventory as its instructions, so an entry naming prose instead '
                .'of a column is an entry that erases nothing while appearing to.',
                $table, $column,
            ));
        }

        return $this->nullability[$table][$column];
    }

    /**
     * Which organization this person belongs to.
     *
     * Read off the subject rather than taken from the caller, so a purge cannot
     * be run against a tenant the subject is not in — the counts and the writes
     * would then be somebody else's, which is the highest-severity bug class in
     * this system (§5.1).
     */
    private function organizationOf(Model $subject): Organization
    {
        $id = $subject->getAttribute('organization_id');

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'Cannot purge a %s: it carries no organization, and every table the purge '
                .'walks is scoped to one. A person with no tenant is not a record this '
                .'system holds.',
                class_basename($subject),
            ));
        }

        return Organization::query()->findOrFail($id);
    }

    /**
     * Where this purge does not reach.
     *
     * Published with the report for the same reason DataInventory publishes its
     * own: the failure mode of an erasure is being believed to be total. Each of
     * these is a real gap and is stated rather than left for somebody to discover
     * while answering a regulator.
     *
     * @return string[]
     */
    private function caveats(): array
    {
        return [
            'Backups hold whatever was live when they were taken and cannot be rewritten. '
                .'A restore has to re-apply every purge made since it was taken.',
            'Free text written by other people — a note on another customer, the body of a '
                .'message — can name this person without any column saying so. Nothing can '
                .'find that, and no purge should claim to.',
            'Audit entries keep what CHANGED, and an old value inside `changes` may be a '
                .'phone number being corrected. Those are not blanked, because the entry '
                .'exists to record the change; they expire instead, under the §10 retention '
                .'the audit log states.',
            'Anything already exported to somebody\'s own machine has left this system.',
        ];
    }

    /**
     * Everything about this person, without touching any of it.
     *
     * The same walk as `purge`, which is the point — a preview built by different
     * code is a preview of a different operation.
     */
    public function preview(Model $subject): array
    {
        return $this->purge($subject, dryRun: true);
    }

}
