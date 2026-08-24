<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryVendor;
use App\Models\PlatformStaff;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The §9 data inventory — what personal data, in which table, why.
 *
 * §9 puts it in the "build now" list and gives the reason: "Impossible to
 * reconstruct later; trivial to maintain as you go." The HR plan sharpens it —
 * once a tenant defines their own fields, an erasure request cannot be answered
 * without one, because nobody but the tenant knows what `acct` holds.
 *
 * The most valuable test here is the last one, which fails when somebody adds a
 * table of personal data and does not declare it.
 */
class DataInventoryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_lists_the_tables_holding_personal_data_and_why(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $body = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/exports/inventory')->assertOk()->json();

        $tables = array_column($body['holdings'], 'table');

        // The obvious ones, and the ones that get forgotten. §9 names audit logs
        // and notification history among the places personal data hides, and an
        // inventory listing only `users` and `customers` would be worse than
        // none — because it would be believed.
        foreach (['users', 'customers', 'invitations', 'attachments',
            'audit_events', 'notifications'] as $table) {
            $this->assertContains($table, $tables, "The inventory does not mention {$table}.");
        }

        $users = collect($body['holdings'])->firstWhere('table', 'users');

        // A purpose per table and a purpose per field. "We hold a phone number"
        // is readable off a schema; "we hold it to call ahead on the day" is the
        // part that cannot be reconstructed later, and is what a rights request
        // actually needs.
        $this->assertNotEmpty($users['purpose']);
        $this->assertNotEmpty($users['retention']);

        foreach ($users['fields'] as $field) {
            $this->assertNotEmpty($field['purpose'], "{$field['field']} is listed with no reason.");
            $this->assertNotEmpty($field['category']);
        }
    }

    /**
     * Counts, and counts that belong to the right organization.
     *
     * "We may hold audit entries about you" and "we hold 41,208 of them" are
     * different answers. A count that quietly included another customer's rows
     * would be both a wrong answer and a tenant leak.
     */
    public function test_the_counts_are_this_organizations_only(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $this->inTenant($beta['org'], fn () => Customer::create([
            'owning_franchise_id' => $beta['franchise']->id,
            'number' => 'C-beta-1', 'name' => 'Somebody Else',
        ]));

        $this->inTenant($alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $alpha['franchise']->id,
            'number' => 'C-alpha-1', 'name' => 'Ours',
        ]));

        $holdings = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/exports/inventory')->assertOk()->json('holdings');

        $customers = collect($holdings)->firstWhere('table', 'customers');

        $this->assertSame(1, $customers['rows']);

        // The audit log is the model with no tenant SCOPE — it is written in
        // platform scope too — so its count is the one that needs the predicate
        // spelt out, and the one that would silently have been every
        // organization's.
        $events = collect($holdings)->firstWhere('table', 'audit_events');

        $this->assertGreaterThan(0, $events['rows']);
        $this->assertLessThan(
            $this->inTenant($alpha['org'], fn () => AuditEvent::count()) + 1,
            $events['rows'],
            'The audit count looks like it spans organizations.',
        );
    }

    /**
     * A listing that says what it cannot see.
     *
     * The failure mode of an inventory is being trusted as complete. Backups
     * cannot be rewritten and web server logs are outside the application; both
     * are named in §9, and both are invisible to anything that reads models.
     */
    public function test_it_publishes_what_it_cannot_account_for(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $caveats = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/exports/inventory')->assertOk()->json('caveats');

        $this->assertNotEmpty($caveats);

        $joined = strtolower(implode(' ', $caveats));

        $this->assertStringContainsString('backup', $joined);
    }

    /**
     * Tenant-defined data is named as such.
     *
     * This is the entry that makes the inventory worth having once Contracts
     * lands: an uploaded file's contents cannot be described by the schema,
     * because the person uploading decided what it was. Saying so is the honest
     * answer; omitting it because it cannot be quantified is the misleading one.
     */
    public function test_data_the_schema_cannot_describe_is_flagged_rather_than_omitted(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $holdings = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/exports/inventory')->assertOk()->json('holdings');

        $attachments = collect($holdings)->firstWhere('table', 'attachments');

        $this->assertTrue(
            collect($attachments['fields'])->contains(fn (array $f) => $f['tenant_defined']),
            'Uploaded file contents are personal data the schema cannot describe, and the '
            .'inventory has to say so rather than leave it out.',
        );
    }

    /**
     * The inventory takes every resource's VIEW permission — and deliberately
     * not its export permission, which is what `exports/bundle` now takes.
     *
     * This test was called `..._the_same_permissions_the_bundle_needs`, and the
     * name became the false claim the moment export got its own action: the
     * bundle needs four keys, this needs two. The name is corrected rather than
     * the code, because the split is right. The inventory emits table names,
     * column names, purposes, retention and COUNTS — `DataInventory` has no
     * path that reads a row. Withholding it takes away a §9 answer without
     * withholding one person's name, from somebody who may still read every one
     * of those people individually.
     */
    public function test_it_is_gated_by_every_resources_view_permission(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // It describes every resource, so reading it means being allowed to read
        // all of them.
        //
        // Both roles used to be granted `data.view` as well, from when Import &
        // export was a module with a tab of its own. That module was removed on
        // 2026-08-12 and the key dropped silently on the way in, so the grant
        // proved nothing and implied something untrue: this endpoint has never
        // consulted it.
        $partial = $this->userWithRole($alpha, 'Partial', ['customers.view']);

        $this->signedInAs($partial)->getJson('/api/v1/exports/inventory')->assertForbidden();

        $full = $this->userWithRole($alpha, 'Full', ['customers.view', 'jobs.view']);

        $this->signedInAs($full)->getJson('/api/v1/exports/inventory')->assertOk();
    }

    /**
     * The test that earns its place.
     *
     * A hand-kept inventory has one failure mode and it is fatal to the purpose:
     * a table of personal data is added, nobody thinks about the inventory, and
     * the document that is supposed to be the complete answer confidently omits
     * it. DataInventory discovers implementors rather than reading a list, so
     * the only way to be missing is to not implement the interface — and this is
     * what makes that a decision rather than an oversight.
     *
     * DERIVED, not a list somebody keeps.
     *
     * This was a hand-written array of six model classes — and on the very day
     * it was written, `ContractSignature` shipped in the same changeset holding
     * a person's name, their typed affirmation, their IP address and their user
     * agent, and was on neither the interface nor the list. Nothing failed.
     *
     * That is verbatim the failure `DataInventory`'s docblock says discovery
     * prevents: "the only way to be missing from here is to not implement the
     * interface". True, and circular — discovery finds implementors and cannot
     * find the model that never declared itself. A hand-kept guard against a
     * hand-kept list is the same maintenance burden twice.
     *
     * So the check is derived instead: any model with a column whose NAME says
     * it is about a person must declare itself, or name itself here with the
     * reason it does not. Adding a table of personal data now fails the build
     * the day it lands rather than the day somebody audits it.
     */
    public function test_every_model_holding_a_person_shaped_column_declares_it(): void
    {
        $tells = self::PERSON_SHAPED_COLUMNS;

        $exempt = [
            /*
             * A COMPANY IS NOT A PERSON. §3 makes it the legal-ish entity whose
             * name and address go on an invoice, so `phone` and `address_line1`
             * are the business's, not an individual's. The honest caveat: for a
             * sole trader those are the same, and if EzBizi ever markets at
             * one-person businesses this belongs back in the inventory.
             */
            Company::class,

            /*
             * A VENDOR IS NOT A PERSON. Inventory spec §8.3: who the organization
             * buys from. `phone` and `email` are the supplier's contact, the same
             * way Company is exempt. W-9 and terms hang off the row as files;
             * Attachment already states those.
             */
            InventoryVendor::class,

            /*
             * EzBizi's own staff (§4.2), in a separate control plane. The
             * inventory is answered PER ORGANIZATION — "what do you hold about
             * me" asked by a tenant's employee — and a platform operator's
             * address is not in that answer. It is our own obligation and a
             * different register.
             */
            PlatformStaff::class,
        ];

        $missing = [];

        foreach ((new Finder)->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $found = array_values(array_intersect($tells, $columns));

            if ($found === [] || in_array($class, $exempt, true)) {
                continue;
            }

            if (! is_subclass_of($class, HoldsPersonalData::class)) {
                $missing[] = $class.' ('.implode(', ', $found).')';
            }
        }

        sort($missing);

        $this->assertSame([], $missing, implode(PHP_EOL, [
            'These hold columns that describe a person and do not implement',
            'HoldsPersonalData, so the §9 inventory does not list them and an',
            'erasure request cannot be answered for what is in them:',
            '',
            '  '.implode(PHP_EOL.'  ', $missing),
            '',
            'Declare purpose, retention and the fields — or add the class to the',
            'exempt list in this test, WITH the reason.',
        ]));
    }

    /**
     * Columns whose presence means a row is about a human being.
     *
     * Narrow on purpose: a false positive here is a nuisance, and a false
     * negative is the whole problem. One home, because the two tests below read
     * it in opposite directions and a second copy would be the copy that drifts.
     */
    private const PERSON_SHAPED_COLUMNS = [
        'email', 'phone', 'first_name', 'last_name', 'middle_name',
        'date_of_birth', 'gender', 'ip_address', 'user_agent',
        'signer_label', 'affirmation', 'address_line1',
    ];

    /**
     * The other direction, and the one that was missing.
     *
     * The test above runs SCHEMA → INTERFACE: a table with a person in it must
     * declare itself. It says nothing about whether the declaration is COMPLETE,
     * and the Anonymiser's docblock used to claim
     * `test_every_erasable_entry_names_columns_that_exist` covered that. It does
     * not — that one runs DECLARED → SCHEMA and is structurally incapable of
     * finding a column nobody declared. So the argument the whole "derived, not
     * a list" design rests on ("somebody adds a personal-data column and updates
     * the inventory because the interface makes them") had no test behind it:
     * the interface forces the method to exist, not to stay complete.
     *
     * This is that test. A model already implementing HoldsPersonalData must name
     * every person-shaped column of its own table in some entry — and adding a
     * column to a table already in the inventory is exactly the edit that skips
     * the other test entirely, because the class already implements the interface.
     *
     * ── What it cannot do, said plainly ──────────────────────────
     *
     * It knows the columns whose NAME says a person is in them, and nothing
     * else. The example this paragraph used to give was `provider_evidence` — a
     * signing vendor's certificate, contents decided by the vendor — undeclared
     * for exactly that reason and found by reading rather than by a check. That
     * column has since been dropped, because there is no vendor and EzBizi
     * builds its own signing, so the example is gone. The limit is not:
     * `attachments` declares "the file contents" and `onboarding_answers`
     * declares `value`, both of them personal data whose shape a tenant decided
     * and neither of them reachable by a column name. Both are declared by hand
     * and flagged `tenantDefined`. A heuristic that catches the common case and
     * admits its edge is worth more than a docblock claiming a proof that does
     * not exist.
     */
    public function test_every_declared_table_names_its_person_shaped_columns(): void
    {
        $undeclared = [];

        foreach (glob(app_path('Models').'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! is_subclass_of($class, HoldsPersonalData::class)
                || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $present = array_intersect(
                self::PERSON_SHAPED_COLUMNS,
                Schema::getColumnListing($table),
            );

            $declared = [];

            foreach ($class::personalDataHeld() as $entry) {
                $declared = array_merge($declared, $entry->columns());
            }

            foreach (array_diff($present, $declared) as $column) {
                $undeclared[] = "{$table}.{$column}";
            }
        }

        sort($undeclared);

        $this->assertSame([], $undeclared, sprintf(
            'These columns describe a person and are not named by any entry on the model '
            ."that owns them:\n  %s\nThe table is already in the §9 inventory, so nothing "
            .'else will notice: the answer to "what do you hold about me" omits the column '
            .'and the purge, which reads the same declarations as its instructions, walks '
            .'straight past it.',
            implode("\n  ", $undeclared),
        ));
    }

    /**
     * The same claim in reverse: nothing declared has since disappeared.
     *
     * The test above runs SCHEMA → DECLARED — a person-shaped column must be
     * named by an entry. This one runs DECLARED → SCHEMA, and the failure it
     * catches is the opposite one: a column is dropped, its entry is left
     * behind, and `/exports/inventory` goes on telling the people it describes
     * that this system holds a thing about them — with a purpose, a category
     * and, where the disposition is `keep`, a stated promise to retain it. An
     * inventory that over-claims is not the smaller error. It is the document
     * handed to somebody exercising a right, and it is false.
     *
     * `provider_evidence` is the case that prompted it: declared IDENTITY, kept
     * forever, never erased, for a signing vendor that will not exist. When the
     * column was dropped, nothing in the suite would have noticed the entry
     * staying behind, and the answer to "what do you hold about me" would have
     * kept naming a vendor's identity evidence that no table has room for.
     *
     * ── Why it is not the check PersonalDataPurgeTest already has ─
     *
     * That one — test_every_erasable_entry_names_columns_that_exist — asks the
     * same question of ERASE entries only, because its concern is a purge
     * issuing an UPDATE against nothing. `keep` and `drop` fell outside it, and
     * `keep` is exactly where a permanent-retention promise lives. This asks it
     * of every disposition, so the narrower one is now contained in it.
     *
     * Tenant-defined entries are skipped, and that is the documented meaning of
     * the flag rather than a convenience: PersonalDataEntry defines `field` as
     * the columns "or — for tenant-defined data — how the fields are named", and
     * `attachments` declares "the file contents", which is prose on purpose
     * because there is no column to name. The one tenant-defined entry that IS a
     * real column, `onboarding_answers.value`, erases — so the purge test's
     * narrower check still covers the case this one steps around.
     *
     * The other shape is a key inside a JSON column, and it is checked as far as
     * a schema can be asked: see the comment on the loop.
     */
    public function test_no_declaration_names_a_column_that_has_been_dropped(): void
    {
        $vanished = [];

        foreach (glob(app_path('Models').'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! is_subclass_of($class, HoldsPersonalData::class)
                || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);

            foreach ($class::personalDataHeld() as $entry) {
                if ($entry->tenantDefined) {
                    continue;
                }

                foreach ($entry->columns() as $name) {
                    /*
                     * A dotted name is a key INSIDE a JSON column —
                     * `audit_events.metadata.ip` is the ip inside `metadata`,
                     * listed on its own because somebody asking "do you keep my
                     * IP address" will not find it under "metadata". What has to
                     * exist there is the column holding the JSON; the key inside
                     * it is data, and a schema cannot be asked about it.
                     *
                     * PersonalDataEntry only permits this shape on an entry the
                     * purge does not act on, and says so: for an erasable entry
                     * `field` is "a comma-separated list of REAL columns and
                     * nothing else", because that string is what the Anonymiser
                     * issues an UPDATE against.
                     */
                    $column = strstr($name, '.', true) ?: $name;

                    if (! in_array($column, $columns, true)) {
                        $vanished[] = "{$table}.{$name}";
                    }
                }
            }
        }

        sort($vanished);

        $this->assertSame([], $vanished, sprintf(
            "The §9 inventory declares these columns and the schema has no such column:\n"
            ."  %s\nEach one is published to the person it is about — a purpose, a category, "
            .'and where the disposition is `keep`, how long it is held for. Dropping a column '
            .'without removing its entry leaves the answer to "what do you hold about me" '
            .'naming something nobody holds.',
            implode("\n  ", $vanished),
        ));
    }

    /** Every declared entry is usable — a category and a purpose, not a stub. */
    public function test_every_declaration_is_answerable_rather_than_a_placeholder(): void
    {
        $categories = [
            PersonalDataEntry::IDENTITY, PersonalDataEntry::CONTACT,
            PersonalDataEntry::FINANCIAL, PersonalDataEntry::ACTIVITY,
            PersonalDataEntry::TECHNICAL, PersonalDataEntry::CONTENT,
        ];

        foreach (glob(app_path('Models').'/*.php') as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! is_subclass_of($class, HoldsPersonalData::class)
                || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $this->assertNotEmpty($class::personalDataPurpose(), "{$class} states no purpose.");
            // Retention moved to StatesRetention, which HoldsPersonalData now
            // extends: the same sentence, composed from a policy something can
            // act on rather than typed as prose nothing can read. See
            // RetentionTest for the enforcement half.
            $this->assertNotEmpty($class::retention()->sentence(), "{$class} states no retention.");
            $this->assertNotEmpty($class::personalDataHeld(), "{$class} lists no fields.");

            foreach ($class::personalDataHeld() as $entry) {
                $this->assertContains($entry->category, $categories, sprintf(
                    '%s::%s uses a category nothing else recognises.',
                    class_basename($class), $entry->field,
                ));

                // A purpose is a sentence somebody reads in an answer to a rights
                // request. Three words is a placeholder wearing a purpose's
                // clothes, and it is the field that cannot be reconstructed later.
                $this->assertGreaterThan(30, strlen($entry->purpose), sprintf(
                    '%s::%s is listed with a purpose too short to answer anything.',
                    class_basename($class), $entry->field,
                ));

                /*
                 * And what erasure does to it. §9's purge is derived from these
                 * declarations rather than from a list of its own, so an entry
                 * without a disposition is a column the purge would miss while
                 * reporting success. `keep` and `drop` additionally have to say
                 * why, since those are the two that get challenged.
                 */
                $this->assertContains($entry->erasure->disposition, [
                    Erasure::ERASE,
                    Erasure::KEEP,
                    Erasure::DROP,
                ]);

                if ($entry->erasure->disposition !== Erasure::ERASE) {
                    $this->assertGreaterThan(30, strlen((string) $entry->erasure->because), sprintf(
                        '%s::%s survives or removes a row without a reason anybody could read.',
                        class_basename($class), $entry->field,
                    ));
                }
            }
        }
    }
}
