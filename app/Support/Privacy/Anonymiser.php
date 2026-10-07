<?php

namespace App\Support\Privacy;

use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Database\WrittenOnceRefusal;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
 * So the rule is: if it is not declared, it is not erased. Two tests hold the
 * declarations to that, and it is worth being exact about which direction each
 * one runs, because the pair used to be described as one net that did not exist:
 *
 *   - PersonalDataPurgeTest::test_every_erasable_entry_names_columns_that_exist
 *     runs DECLARED → SCHEMA. An entry naming prose where a column should be
 *     erases nothing while appearing to, and this catches that.
 *
 *   - DataInventoryTest::test_every_declared_table_names_its_person_shaped_columns
 *     runs SCHEMA → DECLARED, over the same list of person-shaped column names
 *     the inventory's own coverage test uses. This is the one that catches a
 *     column added to a table that already implements the interface.
 *
 * The honest limit, because the second test is a heuristic and not a proof: it
 * knows the columns whose NAME says a row is about a human being. A column whose
 * name says nothing — a free-text note, a vendor's evidence blob — is invisible
 * to it, and stays a judgement somebody has to make while adding the column. The
 * interface forces the method to exist; nothing can force it to stay complete.
 *
 * ── Anonymise in place, not delete ───────────────────────────────
 *
 * §9 resolves the soft-delete tension by keeping the record and blanking the
 * person: "keep the invoice and its amounts, blank the name, email, phone and
 * address". So the default is an UPDATE, every foreign key still points where it
 * pointed, and a job history does not develop holes. Rows are removed only where
 * a declaration says `Erasure::drop`, and there are TWO reasons one does:
 *
 *   - the row is nothing but the person, so blanking it leaves litter rather
 *     than an anonymous record. A session (ApiToken), a spent password reset, a
 *     notification's `user_id` — which is NOT NULL and is what the list is
 *     queried by, so an anonymised one is a row nobody could read or delete.
 *
 *   - there is nothing to blank, because the personal data is not in a column
 *     with a shape. A notification's title and body are free text and nothing
 *     can tell which words in them are a name; an Attachment's data is inside a
 *     PDF or a JPEG this software has never parsed. There is no third option
 *     between keeping a scan of somebody's passport and deleting it.
 *
 * This paragraph used to give only the first reason and only rows fitting it,
 * which read as the whole rule — and the case it left out is the one carrying
 * the least predictable personal data in the system. Attachment::deleting is
 * what keeps that second kind safe rather than reckless: a file a signature
 * fingerprints refuses to go, and the refusal is reported rather than swallowed.
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
     *
     * ── What "will survive" covers, and the one case it cannot ───
     *
     * That last promise was written unconditionally and is only true of part of
     * the walk, so here is the exact shape of it. A row survives for one of
     * three reasons, and a dry run can see two:
     *
     *   - it is DECLARED to survive, with `Erasure::keep`. Read off the model,
     *     so the preview and the purge print an identical `kept` list.
     *
     *   - it refuses to be DROPPED. Asked with `deletionRefusal()` before
     *     anything is attempted, on both paths — see `dropRows`, which is where
     *     that was fixed and why.
     *
     *   - it refuses to be ERASED. Nothing in this system declares such a
     *     refusal, so the only way one can arise is a guard that fails the
     *     write, and a dry run performs no write. The preview therefore cannot
     *     report it.
     *
     * The third is a real gap, stated rather than quietly left. Asking
     * `deletionRefusal()` on the erase path would close it in appearance only:
     * that method answers "may this ROW be removed", which is a different
     * question from "may these columns be blanked" — Attachment, its one
     * implementor today, protects a file a signature fingerprints and lives on
     * the drop path. Wiring it into `eraseColumns` would make the preview
     * announce refusals the purge does not make, which is the same lie pointing
     * the other way.
     * PersonalDataPurgeTest::test_an_erase_a_model_refuses_is_reported_and_the_preview_cannot_know
     * holds the gap to that size and fails if it grows.
     *
     * ── All of it, or none of it ─────────────────────────────────
     *
     * The database work runs in one transaction. It did not, and the walk is
     * alphabetical across a dozen tables, so anything raising part-way through —
     * a constraint, a model guard, a declaration somebody changed to `erase` on
     * an append-only table — left a person half-erased with nothing recording
     * where it stopped. `{ref}` exists because that already happened once, on
     * the unique index over `users.email`; the collision was fixed and the
     * exposure it demonstrated was not.
     *
     * The BYTES are deleted after the commit and never inside it, because a
     * filesystem does not roll back. Deleting a file inside a transaction that
     * then aborts leaves a row pointing at nothing, which reads to every later
     * caller as corruption rather than as erasure — so the walk collects what is
     * doomed and the deletion happens once the rows that indexed those files are
     * certainly gone. AttachmentsController::destroy orders it the same way for
     * the same reason.
     */
    public function purge(Model $subject, bool $dryRun = false): array
    {
        $organization = $this->organizationOf($subject);

        return $this->tenant->run($organization, function () use ($subject, $dryRun, $organization) {
            /** @var array<array{disk: ?string, path: string}> */
            $doomed = [];

            $walk = function () use ($subject, $dryRun, &$doomed): array {
                $tables = [];

                foreach ($this->models->holdingPersonalData() as $class) {
                    $tables[] = $this->purgeOne($class, $subject, $dryRun, $doomed);
                }

                return $tables;
            };

            if ($dryRun) {
                $tables = $walk();
            } else {
                $tables = DB::transaction(function () use ($walk, $subject) {
                    $tables = $walk();

                    /*
                     * §9: "and log the purge itself". The counts go in and the
                     * values do not — "we blanked customers.name" is the
                     * accountable fact, and what the name WAS is precisely what
                     * was being removed.
                     *
                     * Inside the transaction: an entry saying somebody was purged
                     * must not outlive a walk that rolled back.
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

                    return $tables;
                });

                foreach ($doomed as $file) {
                    $this->files->delete($file['path'], $file['disk']);
                }
            }

            return [
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
        });
    }

    /**
     * One table's worth.
     *
     * @param  class-string<Model&HoldsPersonalData>  $class
     * @param  array<array{disk: ?string, path: string}>  $doomed  files to delete once the transaction commits
     */
    private function purgeOne(string $class, Model $subject, bool $dryRun, array &$doomed): array
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

        if ($drops === []) {
            return $this->eraseColumns($line, $rows, $entries, $table, $subject, $dryRun, $doomed);
        }

        // A dropping model whose row merely NAMES this person — an automation
        // event they triggered, about somebody else — answers
        // `personalDataToErase` with the columns that name them. Those rows are
        // erased; the rows ABOUT the person (an empty answer) are dropped.
        if (method_exists($class, 'personalDataToErase')) {
            [$named, $rows] = $rows->partition(fn (Model $row) => $row->personalDataToErase($subject) !== []);
            $line = $this->eraseColumns($line, $named, $entries, $table, $subject, $dryRun, $doomed);
        }

        return $this->dropRows($line, $rows, $dryRun, $doomed);
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
     * ── The refusal is ASKED FOR, not caught ─────────────────────
     *
     * It used to be discovered only by catching what `->delete()` threw, which
     * meant the dry run could not discover it at all: it never called `delete()`,
     * so it counted every refused row as dropped and every protected file as
     * removed. Measured against a signed onboarding, the preview said
     * `rows_dropped: 1, refusals: []` about the very row the real run answered
     * `rows_dropped: 0, refusals: 1` for. The preview promised to destroy the
     * signed contract; the purge refused to. That is the worst way for a privacy
     * tool to be wrong, because the preview is the artefact somebody quotes back
     * to the person asking.
     *
     * So a row is asked `deletionRefusal()` first, on both paths, and the answer
     * is the same sentence either way. `method_exists`, in the same way
     * `personalDataFiles` is asked for: most rows cannot refuse anything, and
     * making every droppable model implement a method returning null would be
     * ceremony. `refusalRaisedBy` stays as the backstop for a refusal nobody
     * declared — a guard on some other model's `deleting` — which is a bug to
     * report rather than a case to plan for, and which is not the same thing as
     * the database saying no. That distinction is the whole of that method.
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
    private function dropRows(array $line, $rows, bool $dryRun, array &$doomed): array
    {
        foreach ($rows as $row) {
            $files   = $this->filesOf($row);
            $refusal = $this->refusalFor($row);

            if ($refusal !== null) {
                $line['refusals'][] = ['id' => $row->getKey(), 'because' => $refusal];

                continue;
            }

            if ($dryRun) {
                $line['rows_dropped']++;
                $line['files_removed'] += count($files);

                continue;
            }

            $raised = $this->refusalRaisedBy(fn () => $row->delete());

            if ($raised !== null) {
                $line['refusals'][] = ['id' => $row->getKey(), 'because' => $raised];

                continue;
            }

            $line['rows_dropped']++;

            // Counted here, deleted after the commit. See purge(): the bytes go
            // once the rows that index them are certainly gone, because a file
            // removed inside a transaction that then aborts leaves a row
            // pointing at nothing.
            foreach ($files as $file) {
                $doomed[] = $file;
                $line['files_removed']++;
            }
        }

        return $line;
    }

    /**
     * Why this row will not go, asked before anything is attempted.
     *
     * Optional, like `personalDataFiles`. A model that can refuse its own
     * deletion says so with a `deletionRefusal()` returning the sentence a person
     * should read; everything else has nothing to declare. Attachment is the one
     * implementor today, and it forwards the question to the record the file
     * hangs off, because only that record knows which of its files are evidence.
     */
    private function refusalFor(Model $row): ?string
    {
        return method_exists($row, 'deletionRefusal') ? $row->deletionRefusal() : null;
    }

    /**
     * Perform one row's write, and turn a model's refusal into a line of report.
     *
     * ── Why this is not simply `catch (RuntimeException)` ────────
     *
     * It was, on both paths, and it caught the one thing it must never catch.
     * `Illuminate\Database\QueryException` extends `PDOException`, which extends
     * `RuntimeException` — so a deadlock (MySQL 1213) or a lock-wait timeout
     * (1205) arrived looking exactly like a model guard and was written into the
     * report as a considered refusal, with the driver's message as the reason a
     * person's data had been kept.
     *
     * That is not merely an ugly report. MySQL resolves both of those by rolling
     * back the WHOLE transaction, so from that row on the walk was writing with
     * no transaction around it at all — `purge()`'s "all of it, or none of it"
     * quietly stopped being true, later tables committed on their own, and the
     * report described writes that no longer existed. A privacy tool that files
     * a deadlock as a decision is lying about what it did.
     *
     * Re-throwing `PDOException` closed that, and left the same hole one step
     * narrower. Laravel's `EncryptException`, `DecryptException` and
     * `JsonEncodingException` all extend `RuntimeException` too, and none of
     * them is a database error — so they stayed caught. That is reachable, not
     * theoretical: this walk erases `onboarding_answers.value`, which is written
     * through an `encrypted` cast. A cipher failure there would have been filed
     * as a considered refusal, the walk would have carried on, and the
     * transaction would have COMMITTED — somebody's answers surviving an erasure
     * request, with a framework message as the published reason they were kept.
     *
     * A catch cannot be narrowed by listing what to let past; the list is
     * open-ended and every framework release lengthens it. So the guard is
     * TYPED. [WrittenOnceRefusal] is thrown by `WriteOnceBuilder` and by nothing
     * else, and it is the only thing caught here. Everything else — the
     * database, the encrypter, a bug in a `deleting` hook — reaches the top and
     * `purge()`'s transaction does what it is for. The report can then only ever
     * carry a refusal somebody declared, which is what it always claimed to
     * carry. Both paths ask through here, rather than each keeping its own copy
     * of a distinction this fiddly.
     *
     * @param  callable():mixed  $write
     * @return string|null  the refusal, or null where the write went through
     */
    private function refusalRaisedBy(callable $write): ?string
    {
        try {
            $write();
        } catch (WrittenOnceRefusal $e) {
            return $e->getMessage();
        }

        return null;
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
     *
     * A write that is nonetheless refused is reported the way `dropRows` reports
     * one, rather than thrown. This path used to catch nothing at all, so a
     * single guarded row aborted the whole walk part-way down an alphabetical
     * list of tables; now the refusal is a line in the report and the transaction
     * around the walk is what keeps everything else consistent.
     *
     * That last clause is only true because a database error is NOT treated as a
     * refusal — `refusalRaisedBy` sends those back up so the transaction can roll
     * back. A catch that swallowed a deadlock would have made this sentence
     * describe a transaction that MySQL had already discarded.
     *
     * Nothing declares an erase-time refusal, so nothing here is ASKED the way
     * `dropRows` asks `deletionRefusal()`. `purge()`'s docblock states what that
     * costs the dry run, and why asking the deletion question here would be the
     * wrong answer rather than a cheap one.
     */
    private function eraseColumns(array $line, $rows, array $entries, string $table, Model $subject, bool $dryRun, array &$doomed): array
    {
        $erasable = array_values(array_filter(
            $entries, fn (PersonalDataEntry $e) => $e->erasure->disposition === Erasure::ERASE,
        ));

        if ($erasable === []) {
            return $line;
        }

        foreach ($rows as $row) {
            $selected = method_exists($row, 'personalDataToErase')
                ? array_flip($row->personalDataToErase($subject))
                : null;
            $plan = [];

            foreach ($erasable as $entry) {
                foreach ($entry->columns() as $column) {
                    if ($selected !== null && ! isset($selected[$column])) {
                        continue;
                    }

                    /*
                     * A stand-in the model stated wins over the schema; otherwise
                     * the schema decides. See Erasure for the nullable distinction.
                     */
                    $plan[$column] = $entry->erasure->hasStatedStandIn()
                        || ! $this->isNullable($table, $column)
                            ? $entry->erasure
                            : null;
                }
            }

            if ($plan === []) {
                continue;
            }

            // Report the union of columns erased across matching row roles. The
            // template, `{ref}` and all, is policy rather than one row's value.
            $line['columns_erased'] = array_replace(
                $line['columns_erased'],
                array_map(
                    fn (?Erasure $e) => $e === null ? 'null' : ($e->standIn ?? Erasure::DEFAULT_STAND_IN),
                    $plan,
                ),
            );

            $values = array_map(
                fn (?Erasure $e) => $e?->standInFor((string) $row->getKey()),
                $plan,
            );

            $files = $this->filesOf($row);

            if ($dryRun) {
                $line['files_removed'] += count($files);

                continue;
            }

            $raised = $this->refusalRaisedBy(
                fn () => $row->forceFill($values)->saveQuietly(),
            );

            if ($raised !== null) {
                $line['refusals'][] = ['id' => $row->getKey(), 'because' => $raised];

                continue;
            }

            // Counted now, deleted after the commit — see dropRows and purge().
            foreach ($files as $file) {
                $doomed[] = $file;
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
        // A key inside a JSON column, `payload->Phone`, written through
        // Eloquent's JSON attributes: the column has to exist, and the key can
        // always hold null.
        if (str_contains($column, '->')) {
            $this->isNullable($table, strstr($column, '->', true));

            return true;
        }

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
            'Anything already exported to somebody\'s own computer has left this system.',
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
