<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\ContractSignature;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\OnboardingDocument;
use App\Models\Organization;
use App\Models\PasswordReset;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\PasswordResets;
use App\Support\Privacy\Anonymiser;
use App\Support\Privacy\DeclaringModels;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\PersonalDataEntry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\Fixtures\Privacy\CustomerRowsDroppedOutright;
use Tests\Fixtures\Privacy\DeclaresTheseModels;
use Tests\Fixtures\Privacy\SignatureThatRefusesTheErase;
use Tests\Fixtures\Privacy\UsersErasedToOneAddress;
use Tests\TestCase;

/**
 * §9's second build-now mechanism: purge personal data, anonymise in place.
 *
 * The audit found it absent. What makes it worth testing rather than merely
 * running is that a purge has two ways to be wrong and only one of them is
 * visible: it can erase too little, which nobody notices until a regulator
 * reads the schema, and it can erase too much, which destroys the invoice §9
 * insists on keeping. The tests below are paired on that axis throughout.
 */
class PersonalDataPurgeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    // ── 1. anonymise in place, which is the whole design ─────────

    /**
     * §9's own worked example, in one test.
     *
     * "Keep the invoice and its amounts, blank the name, email, phone and
     * address." The job is the invoice here, and the assertion that matters is
     * not that the name went — it is that the job did NOT, still points at the
     * same customer, and still carries its own number and title.
     */
    public function test_a_purged_customer_keeps_every_record_that_refers_to_them(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        [$customer, $job] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create([
                'owning_franchise_id' => $alpha['franchise']->id,
                'number' => 'C-00042', 'name' => 'Marta Ruiz',
                'email' => 'marta@example.test', 'phone' => '+34 600 111 222',
                'address_line1' => '14 Calle Mayor', 'city' => 'Madrid',
                'state' => 'MD', 'postal_code' => '28013',
                'notes' => 'Prefers mornings. Dog in the garden.',
            ]);

            $job = Job::create([
                'franchise_id' => $alpha['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-00007', 'title' => 'Annual service',
                'status' => 'scheduled',
            ]);

            return [$customer, $job];
        });

        $this->purge($customer);

        $this->inTenant($alpha['org'], function () use ($customer, $job) {
            $fresh = Customer::withTrashed()->findOrFail($customer->getKey());

            // The person is gone.
            $this->assertSame('Removed customer', $fresh->name);
            $this->assertNull($fresh->email);
            $this->assertNull($fresh->phone);
            $this->assertNull($fresh->address_line1);
            $this->assertNull($fresh->postal_code);
            $this->assertNull($fresh->notes,
                'Free-text notes are the field with no schema and the one that ends up '
                .'holding whatever somebody wrote about a person.');

            // §9 warns that blanking a name is not anonymisation while a unique
            // address remains — so city and state go too, not just the street.
            $this->assertNull($fresh->city);
            $this->assertNull($fresh->state);

            // And the record survives, intact, still joined up.
            $this->assertNotNull(Job::find($job->getKey()), 'The job was deleted with the customer.');
            $this->assertSame($customer->getKey(), Job::find($job->getKey())->customer_id);
            $this->assertSame('J-00007', Job::find($job->getKey())->number);

            // The customer NUMBER identifies the record, not the person, and the
            // invoices §9 insists on keeping are found by it.
            $this->assertSame('C-00042', $fresh->number);
        });
    }

    /**
     * The row somebody most expects to have gone already.
     *
     * §9's tension in one sentence: "soft-deleted personal data is still
     * retained personal data". A purge that skipped trashed rows would leave
     * exactly the customer the organization believes they deleted last year.
     */
    public function test_a_soft_deleted_customer_is_purged_too(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $customer = $this->inTenant($alpha['org'], function () use ($alpha) {
            $c = Customer::create([
                'owning_franchise_id' => $alpha['franchise']->id,
                'number' => 'C-1', 'name' => 'Already Deleted',
                'email' => 'gone@example.test',
            ]);
            $c->delete();

            return $c;
        });

        $this->purge($customer);

        $this->inTenant($alpha['org'], function () use ($customer) {
            $fresh = Customer::withTrashed()->findOrFail($customer->getKey());

            $this->assertNull($fresh->email);
            $this->assertNotNull($fresh->deleted_at, 'The purge un-deleted the row.');
        });
    }

    /**
     * A trashed job must not hide a customer's files from their own purge.
     *
     * `Attachment::personalDataAbout` reaches a customer's photographs through
     * their jobs, because that is where photographs get attached. `Job`
     * soft-deletes, so the sub-builder compiled with `deleted_at is null` and
     * the files on a deleted-but-restorable visit were never found — the purge
     * reported a clean sweep of a table it had not fully looked in.
     *
     * It is the same miss `test_a_soft_deleted_customer_is_purged_too` covers
     * one join further out, and HoldsPersonalData::personalDataAbout states the
     * rule both obey: soft-deleted rows must be INCLUDED, because a restorable
     * record is retained personal data.
     */
    public function test_files_on_a_soft_deleted_job_are_still_the_customers(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        [$customer, $attachmentId] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create([
                'owning_franchise_id' => $alpha['franchise']->id,
                'number' => 'C-1', 'name' => 'Marta Ruiz', 'email' => 'marta@example.test',
            ]);

            $job = Job::create([
                'franchise_id' => $alpha['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-1', 'title' => 'Annual service', 'status' => 'scheduled',
            ]);

            $attachment = new Attachment;

            $attachment->forceFill([
                'organization_id' => $alpha['org']->id,
                'attachable_type' => $job->getMorphClass(),
                'attachable_id' => $job->getKey(),
                'original_name' => 'marta-front-door.jpg',
                'mime' => 'image/jpeg',
                'disk' => 'local',
                'path' => 'attachments/marta-front-door.jpg',
                'size_bytes' => 12,
                'checksum' => hash('sha256', 'x'),
            ])->save();

            Storage::disk('local')->put('attachments/marta-front-door.jpg', 'xxxxxxxxxxxx');

            // The visit is deleted, not purged. It can still be restored, so its
            // photographs are still held about this person.
            $job->delete();

            return [$customer, $attachment->getKey()];
        });

        $report = $this->purge($customer);

        $line = $this->tableLine($report, 'attachments');

        $this->assertSame(1, $line['rows'],
            'The purge never looked at the photographs on a trashed job, so it reported '
            .'a completeness it had not achieved.');

        $this->assertSame(1, $line['rows_dropped']);

        $this->inTenant($alpha['org'], fn () => $this->assertNull(Attachment::find($attachmentId)));

        Storage::disk('local')->assertMissing('attachments/marta-front-door.jpg');
    }

    // ── 2. the employee, and everything hanging off them ─────────

    public function test_purging_an_employee_blanks_the_account_and_ends_their_sessions(): void
    {
        [$alpha, $employee] = $this->anEmployee();

        $this->inTenant($alpha['org'], fn () => Notification::create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $employee->getKey(),
            'title' => 'Marta Ruiz was assigned a job',
            'body' => 'Ring the customer on 600 111 222 before arriving.',
            'created_at' => now(),
        ]));

        $this->assertGreaterThan(0, ApiToken::where('tokenable_id', $employee->getKey())->count());

        $this->purge($employee);

        $fresh = User::withoutGlobalScopes()->findOrFail($employee->getKey());

        $this->assertSame('Former staff', $fresh->name);
        $this->assertNull($fresh->first_name);
        $this->assertNull($fresh->last_name);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->date_of_birth);

        // NOT NULL and uniquely indexed, so a stand-in rather than a null — and
        // unroutable, because other code is entitled to send mail to this column.
        $this->assertStringStartsWith('removed-', $fresh->email);
        $this->assertStringEndsWith('@invalid', $fresh->email);

        // The account still exists and is still referenced. §5: deactivate is
        // not delete, and everything they did stays attributed to them.
        $this->assertNotNull($fresh->role_id);

        $this->assertSame(0, ApiToken::where('tokenable_id', $employee->getKey())->count(),
            'A purged account still had live sessions — an authenticated browser open on '
            .'an account whose owner has just been told their data is gone.');

        $this->assertSame(0, $this->inTenant($alpha['org'],
            fn () => Notification::where('user_id', $employee->getKey())->count()));
    }

    /**
     * A table written by a query builder is still a table the purge must reach.
     *
     * `password_resets` had no Eloquent model, and every §9 mechanism in this
     * system is derived by walking `app/Models` — so the inventory did not list
     * it, the sweep did not expire it and a purge left the row exactly as it
     * was: the person's id, and the IP address they asked from. Not exempt from
     * three mechanisms; invisible to all three, which is why nothing reported
     * it. The model App\Models\PasswordReset exists for that and nothing else;
     * the service still writes with `DB::table`.
     */
    public function test_a_password_reset_request_is_purged_with_the_person(): void
    {
        [$alpha, $employee] = $this->anEmployee();

        app(PasswordResets::class)->issue($employee, '203.0.113.9');

        $this->assertSame(1, PasswordReset::query()
            ->where('subject_id', $employee->getKey())->count());

        $report = $this->purge($employee);

        $this->assertSame(1, $this->tableLine($report, 'password_resets')['rows_dropped']);

        $this->assertSame(0, PasswordReset::query()
            ->where('subject_id', $employee->getKey())->count(),
            'The reset request survived the purge, and with it the address the person '
            .'asked from.');
    }

    /**
     * Two people erased on the same day must not collide.
     *
     * `users.email` is NOT NULL and uniquely indexed per organization. A constant
     * stand-in would throw on the second purge — and it would throw AFTER the
     * first tables had already been written, which is the worst place to
     * discover it. `{ref}` exists for exactly this.
     */
    public function test_two_purges_in_one_organization_do_not_collide(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $one = $this->userWithRole($alpha, 'One', ['customers.view']);
        $two = $this->userWithRole($alpha, 'Two', ['customers.view']);

        $this->purge($one);
        $this->purge($two);

        $emails = User::withoutGlobalScopes()
            ->whereIn('id', [$one->getKey(), $two->getKey()])
            ->pluck('email');

        $this->assertCount(2, $emails->unique(), 'Two erased accounts share one address.');
    }

    // ── 3. what must NOT be erased ───────────────────────────────

    /**
     * The decision this whole feature had to defend.
     *
     * A signature's `signer_label` is captured verbatim precisely so that it
     * survives the account being anonymised — the design anticipated erasure and
     * chose a copy over a join for that reason. A purge that then blanked the
     * copy would be defeating a decision made for its benefit, and would leave
     * the organization holding an unenforceable contract without being told.
     */
    public function test_a_signature_survives_the_signer_being_erased_and_the_report_says_so(): void
    {
        [$alpha, $employee, $onboardingId] = $this->aSignedOnboarding();

        $before = $this->inTenant($alpha['org'], fn () => ContractSignature::query()
            ->where('subject_user_id', $employee->getKey())->get());

        $this->assertNotEmpty($before, 'The scenario produced no signature to test against.');

        $report = $this->purge($employee);

        $after = $this->inTenant($alpha['org'], fn () => ContractSignature::query()
            ->where('subject_user_id', $employee->getKey())->get());

        $this->assertCount($before->count(), $after);

        foreach ($after as $signature) {
            $this->assertNotEmpty($signature->signer_label);
            $this->assertNotEmpty($signature->affirmation);
            $this->assertStringNotContainsString('Removed', (string) $signature->signer_label);
        }

        // And it is REPORTED. Somebody answering a rights request has to be able
        // to say "your name stays on the contract you signed" before they promise
        // anything — not discover it afterwards.
        $line = $this->tableLine($report, 'contract_signatures');

        $this->assertTrue($line['applies']);
        $this->assertNotEmpty($line['kept']);
        $this->assertStringContainsString('ESIGN',
            implode(' ', array_column($line['kept'], 'because')));
    }

    /**
     * The rendered contract cannot be removed, and the purge says why.
     *
     * It contains every answer in the clear because it is the thing that was
     * signed. Onboarding vetoes its removal — a signature fingerprints it — and
     * the Anonymiser's job is to report that refusal rather than swallow it or
     * never look.
     */
    public function test_the_signed_document_refuses_to_be_removed_and_the_refusal_is_reported(): void
    {
        [$alpha, $employee] = $this->aSignedOnboarding();

        $report = $this->purge($employee);

        $line = $this->tableLine($report, 'attachments');

        $this->assertNotEmpty($line['refusals'],
            'The rendered contract is an attachment full of personal data hanging off the '
            .'onboarding. Either it was never looked at, or it was deleted — and it is '
            .'evidence, so it must be neither.');

        $this->assertStringContainsString('signature', strtolower($line['refusals'][0]['because']));

        $this->assertGreaterThan(0, $this->inTenant($alpha['org'],
            fn () => OnboardingDocument::count()), 'The signed document was destroyed.');
    }

    /**
     * The audit trail keeps the act and loses the name.
     *
     * AuditEvent refuses `updating` because history must not be editable by
     * application code, and its own retention sentence states the exception this
     * relies on. What must not change is the number of entries: erasing somebody
     * removes their name from the log, never the record that something happened.
     */
    public function test_the_audit_trail_keeps_its_entries_and_loses_the_name(): void
    {
        [$alpha, $employee] = $this->anEmployee();

        $before = $this->inTenant($alpha['org'], fn () => AuditEvent::query()
            ->where('actor_id', $employee->getKey())->count());

        $this->assertGreaterThan(0, $before, 'The scenario wrote no audit entries to erase.');

        $this->purge($employee);

        $after = $this->inTenant($alpha['org'], fn () => AuditEvent::query()
            ->where('actor_id', $employee->getKey())->get());

        $this->assertCount($before, $after, 'Audit entries were deleted rather than anonymised.');

        foreach ($after as $event) {
            $this->assertSame('Former staff', $event->actor_label);
        }
    }

    /**
     * The tenant-defined answers go, and the form stays.
     *
     * §9's sharpest sentence — "nobody except the tenant knows that the field
     * they called `acct` is a bank account" — turns out to be answerable because
     * whatever they called it, the value is in one column. The row survives so
     * that WHICH questions were asked remains part of the contract record; only
     * what they were answered with goes.
     */
    public function test_onboarding_answers_are_erased_but_the_form_survives(): void
    {
        [$alpha, $employee, $onboardingId] = $this->aSignedOnboarding();

        $before = $this->inTenant($alpha['org'], fn () => OnboardingAnswer::query()
            ->where('onboarding_id', $onboardingId)->count());

        $this->assertGreaterThan(0, $before);

        $this->purge($employee);

        $this->inTenant($alpha['org'], function () use ($onboardingId, $before) {
            $answers = OnboardingAnswer::where('onboarding_id', $onboardingId)->get();

            $this->assertCount($before, $answers, 'The form itself was destroyed.');

            foreach ($answers as $answer) {
                // Decrypts cleanly — which is the assertion that catches a purge
                // writing plaintext through the query builder into an encrypted
                // column, leaving a row nobody could ever read again.
                $this->assertSame('(removed)', $answer->value);
            }

            $this->assertNotNull(Onboarding::find($onboardingId), 'The onboarding was deleted.');
        });
    }

    // ── 4. the boundaries ────────────────────────────────────────

    public function test_a_purge_touches_nothing_in_another_organization(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $theirs = $this->inTenant($beta['org'], fn () => Customer::create([
            'owning_franchise_id' => $beta['franchise']->id,
            'number' => 'C-1', 'name' => 'Somebody Else',
            'email' => 'else@example.test', 'phone' => '+34 600 999 888',
        ]));

        $ours = $this->inTenant($alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $alpha['franchise']->id,
            'number' => 'C-1', 'name' => 'Ours', 'email' => 'ours@example.test',
        ]));

        $this->purge($ours);

        $this->inTenant($beta['org'], function () use ($theirs) {
            $fresh = Customer::findOrFail($theirs->getKey());

            $this->assertSame('Somebody Else', $fresh->name);
            $this->assertSame('else@example.test', $fresh->email);
        });
    }

    /**
     * A dry run is the thing somebody reads before promising anything.
     *
     * It has to describe the same operation — same walk, same declarations — and
     * write nothing at all.
     */
    public function test_a_dry_run_reports_the_whole_plan_and_changes_nothing(): void
    {
        [$alpha, $employee] = $this->anEmployee();

        $report = app(Anonymiser::class)->preview($employee);

        $this->assertTrue($report['dry_run']);

        $users = $this->tableLine($report, 'users');

        $this->assertSame(1, $users['rows']);
        $this->assertArrayHasKey('name', $users['columns_erased']);
        $this->assertArrayHasKey('email', $users['columns_erased']);

        // The plan, not one row's resolved values: `{ref}` is still there.
        $this->assertStringContainsString('{ref}', $users['columns_erased']['email']);

        $this->assertSame(
            $employee->email,
            User::withoutGlobalScopes()->findOrFail($employee->getKey())->email,
            'A dry run wrote to the database.',
        );

        $this->assertNotEmpty($report['caveats']);
    }

    /**
     * The preview and the purge must describe ONE operation.
     *
     * They did not, on the only case anybody would read a preview for. The dry
     * run never called `delete()`, so it never met the refusal an Attachment
     * raises over a signed contract — it counted the row as dropped and the file
     * as removed and printed `refusals: []`. The real run answered
     * `rows_dropped: 0, refusals: 1`. So the artefact somebody reads before
     * telling a person what will happen promised to destroy the signed contract
     * that the purge then, correctly, refused to touch.
     *
     * `anEmployee()` could not catch it: that fixture has no signed onboarding,
     * so there is nothing in it that refuses anything. This one asserts the two
     * reports agree line for line on every table, which is a stronger statement
     * than "the preview mentions the refusal" and does not need editing when a
     * new table joins the walk.
     */
    public function test_the_preview_and_the_purge_agree_on_every_line(): void
    {
        [$alpha, $employee] = $this->aSignedOnboarding();

        $preview = app(Anonymiser::class)->preview($employee);
        $real = $this->purge($employee);

        $comparable = fn (array $report) => collect($report['tables'])
            ->keyBy('table')
            ->map(fn (array $line) => [
                'applies' => $line['applies'],
                'rows' => $line['rows'],
                'rows_dropped' => $line['rows_dropped'],
                'files_removed' => $line['files_removed'],
                'columns_erased' => $line['columns_erased'],
                'refusals' => array_column($line['refusals'], 'because'),
                'kept' => $line['kept'],
            ])
            ->all();

        $this->assertSame($comparable($preview), $comparable($real),
            'The dry run described a different operation from the one that ran. Whatever '
            .'differs here is something somebody was told would happen and did not, or the '
            .'other way round.');

        // And it is the interesting case that is being compared, not an empty walk.
        $this->assertNotEmpty($this->tableLine($preview, 'attachments')['refusals'],
            'The preview did not mention the refusal at all, so the assertion above is '
            .'comparing two reports that both say nothing.');
    }

    /**
     * A refusal must not take the rest of the purge with it, and must not leave
     * a person half-erased either.
     *
     * The signed onboarding produces both in one walk: `attachments` refuses the
     * rendered contract, and every other table still has to be erased. Before
     * the walk was transactional this was the shape that worried — an exception
     * part-way down an alphabetical list, with nothing recording where it
     * stopped.
     */
    public function test_a_refusal_does_not_stop_the_tables_after_it(): void
    {
        [$alpha, $employee, $onboardingId] = $this->aSignedOnboarding();

        $report = $this->purge($employee);

        $this->assertNotEmpty($this->tableLine($report, 'attachments')['refusals']);

        // `users` sorts after `attachments`, `onboarding_answers` after that:
        // both are past the refusal in the walk.
        $fresh = User::withoutGlobalScopes()->findOrFail($employee->getKey());

        $this->assertSame('Former staff', $fresh->name);

        $this->inTenant($alpha['org'], function () use ($onboardingId) {
            foreach (OnboardingAnswer::where('onboarding_id', $onboardingId)->get() as $answer) {
                $this->assertSame('(removed)', $answer->value);
            }
        });
    }

    /**
     * §9: "and log the purge itself" — without undoing it in the same breath.
     *
     * AuditLogger captures a target's label, which for a user is their name and
     * email. Logging the purge that way would write the erased name into an
     * append-only table seconds after removing it, and §9 names audit logs among
     * the places personal data hides.
     */
    public function test_the_purge_is_audited_and_the_entry_holds_no_name(): void
    {
        [$alpha, $employee] = $this->anEmployee();

        $name = $employee->name;
        $email = $employee->email;

        $this->purge($employee);

        $entry = $this->inTenant($alpha['org'], fn () => AuditEvent::query()
            ->where('action', 'personal_data.purged')->latest('occurred_at')->first());

        $this->assertNotNull($entry, 'A purge is not recorded anywhere.');

        $json = json_encode([$entry->actor_label, $entry->target_label, $entry->metadata]);

        $this->assertStringNotContainsString($name, $json);
        $this->assertStringNotContainsString($email, $json);
        $this->assertSame($employee->getKey(), $entry->metadata['subject_id']);
    }

    // ── 5. the declarations the purge is derived from ────────────

    /**
     * The check that keeps the derivation honest.
     *
     * The Anonymiser reads `field` as its instructions, so an entry naming prose
     * where a column should be is an entry that erases nothing while appearing
     * to. That failure is silent in production and loud here.
     */
    public function test_every_erasable_entry_names_columns_that_exist(): void
    {
        foreach (app(DeclaringModels::class)->holdingPersonalData() as $class) {
            $table = (new $class)->getTable();

            foreach ($class::personalDataHeld() as $entry) {
                if ($entry->erasure->disposition !== Erasure::ERASE) {
                    continue;
                }

                foreach ($entry->columns() as $column) {
                    // `payload->Phone` is a key inside the JSON column
                    // `payload`; the column is what the schema can answer for.
                    $this->assertTrue(
                        Schema::hasColumn($table, strstr($column, '->', true) ?: $column),
                        "{$class} says erasure blanks {$table}.{$column}, and there is no "
                        .'such column — so the purge would silently erase nothing.',
                    );
                }
            }
        }
    }

    /**
     * A model must not mix `drop` with `erase`.
     *
     * `drop` removes the row and the Anonymiser checks for it first, so any
     * `erase` beside it is a declaration that is published in the inventory and
     * never executed — a promise made to a reader that the code does not keep.
     */
    public function test_no_model_both_drops_its_rows_and_blanks_their_columns(): void
    {
        foreach (app(DeclaringModels::class)->holdingPersonalData() as $class) {
            // A model that answers per row which applies does both, for
            // different rows. See HoldsPersonalData on personalDataToErase.
            if (method_exists($class, 'personalDataToErase')) {
                continue;
            }

            $dispositions = array_unique(array_map(
                fn (PersonalDataEntry $e) => $e->erasure->disposition,
                $class::personalDataHeld(),
            ));

            $this->assertFalse(
                in_array(Erasure::DROP, $dispositions, true)
                    && in_array(Erasure::ERASE, $dispositions, true),
                "{$class} declares both that its rows are removed and that some of their "
                .'columns are blanked. Only the first happens, so the second is published '
                .'in the inventory and never done.',
            );
        }
    }

    /**
     * Evidence is declared as evidence, and stays that way.
     *
     * The Anonymiser writes with `saveQuietly()`, which bypasses the model event
     * guards on purpose so that `audit_events` can be anonymised. What bounds
     * that exception is not the Anonymiser — it is these declarations. If
     * somebody ever changes one of these to `erase`, a signature becomes
     * silently editable by the purge, and this is the test that stops it.
     */
    public function test_the_evidentiary_tables_keep_everything(): void
    {
        foreach ([ContractSignature::class] as $class) {
            foreach ($class::personalDataHeld() as $entry) {
                $this->assertSame(
                    Erasure::KEEP, $entry->erasure->disposition,
                    "{$class}.{$entry->field} is no longer declared as surviving erasure. "
                    .'That table is evidence and the purge bypasses its immutability guard; '
                    .'the declaration is the only thing holding the line.',
                );

                $this->assertNotEmpty($entry->erasure->because);
            }
        }
    }

    // ── 6. the two ways a write fails, which are not one way ─────

    /**
     * A model refusing an ERASE, which nothing in app/Models does — so nothing
     * covered it.
     *
     * `eraseColumns` reports a refused write instead of throwing, and that catch
     * was untested: delete it, and all thirty tests in this file and RetentionTest
     * stay green. The refusal the suite does provoke — the rendered contract, in
     * `test_the_signed_document_refuses_to_be_removed_and_the_refusal_is_reported`
     * — is an Attachment, which is on the DROP path and was already caught before
     * the erase path was given a catch at all. So the fix for that was reported as
     * proven by a test that never reached it.
     *
     * SignatureThatRefusesTheErase is `contract_signatures` with `signer_label`
     * flipped from `keep` to `erase`. The refusal is the real one: the table uses
     * `WrittenOnce`, and `saveQuietly()` goes through the Eloquent builder, so
     * WriteOnceBuilder answers the write the way it answers every other.
     *
     * ── And the preview cannot know, which is stated on purpose ──
     *
     * The last assertion is not an aspiration. Anonymiser::purge's docblock says
     * the dry run can see a `keep` and can see a row refusing to be DROPPED,
     * because both are ASKED; an erase-time refusal is discovered by the write
     * failing, and a dry run performs no write. That gap is real and is written
     * down rather than papered over — this pins it at exactly one table so it
     * cannot widen without a test going red.
     */
    public function test_an_erase_a_model_refuses_is_reported_and_the_preview_cannot_know(): void
    {
        [$alpha, $employee] = $this->aSignedOnboarding();

        // `users` second, so what happens to it says whether the walk carried on.
        $walk = [SignatureThatRefusesTheErase::class, User::class];

        $preview = $this->purgeWalking($walk, $employee, dryRun: true);
        $report = $this->purgeWalking($walk, $employee);

        $line = $this->tableLine($report, 'contract_signatures');

        $this->assertGreaterThan(0, $line['rows'],
            'The fixture found no signature, so everything below is asserting about an '
            .'empty walk.');

        $this->assertNotEmpty($line['refusals'],
            'A model refused to have its column blanked and the purge did not say so. Either '
            .'the refusal escaped and took the walk with it, or it was swallowed and the '
            .'report claims an erasure that did not happen.');

        $this->assertStringContainsString('cannot be updated', $line['refusals'][0]['because']);

        $this->inTenant($alpha['org'], function () use ($employee) {
            $signatures = ContractSignature::query()
                ->where('subject_user_id', $employee->getKey())->get();

            foreach ($signatures as $signature) {
                $this->assertStringNotContainsString('Removed', (string) $signature->signer_label,
                    'The refusal was reported and the write went through anyway.');
            }
        });

        $this->assertSame('Former staff',
            User::withoutGlobalScopes()->findOrFail($employee->getKey())->name,
            'The table after the refused one was never reached, so one guarded row still '
            .'stops the walk — which is the failure the try/catch was added to prevent.');

        $this->assertSame([], $this->tableLine($preview, 'contract_signatures')['refusals'],
            'The preview reported an erase-time refusal. It cannot have discovered one — it '
            .'performs no write, and nothing declares an erase refusal to be asked about — so '
            .'if this now passes information to the reader, Anonymiser::purge\'s docblock is '
            .'describing a limit that no longer exists and should be rewritten.');
    }

    /**
     * The hazard: a database error is not a decision.
     *
     * `Illuminate\Database\QueryException` extends `PDOException` extends
     * `RuntimeException`, so `catch (RuntimeException)` on both write paths
     * caught the database as well as the model. A deadlock (1213) or a lock-wait
     * timeout (1205) would have been filed in the report as a per-row refusal —
     * and MySQL resolves both by rolling back the WHOLE transaction, so the walk
     * would have carried on writing outside any transaction and reported success
     * over tables whose earlier writes had already been discarded.
     *
     * Neither of those two is reproducible on demand. A duplicate key is, and it
     * arrives through exactly the same class: `users_organization_id_email_unique`
     * is live, and UsersErasedToOneAddress blanks every account in the
     * organization to one constant address — which is the collision `{ref}` was
     * invented to prevent, and the one the Anonymiser's own docblock records
     * happening "AFTER earlier tables had already been written".
     *
     * So this asserts both halves at once: the error goes UP rather than into the
     * report, and the audit rows erased before it are back as they were.
     */
    public function test_a_database_error_on_the_erase_path_is_not_a_refusal_and_the_walk_rolls_back(): void
    {
        [$alpha, $employee] = $this->anEmployee();

        $labels = fn () => $this->inTenant($alpha['org'], fn () => AuditEvent::query()
            ->where('actor_id', $employee->getKey())
            ->orderBy('id')->pluck('actor_label', 'id')->all());

        $before = $labels();

        $this->assertNotEmpty($before,
            'The scenario wrote no audit entries, so there is nothing for a rollback to '
            .'restore and the assertion below would pass on an empty array.');

        $raised = null;

        try {
            $this->purgeWalking([AuditEvent::class, UsersErasedToOneAddress::class], $employee);
        } catch (\Throwable $e) {
            $raised = $e;
        }

        $this->assertInstanceOf(QueryException::class, $raised,
            'A duplicate-key error was not raised to the caller. It was caught as though the '
            .'model had refused the row, so the report would have offered a driver message as '
            .'the reason somebody\'s data was kept — and, for the deadlock this stands in for, '
            .'would have gone on writing with no transaction left around it.');

        $this->assertSame($before, $labels(),
            'The audit labels erased before the failure are still erased, so the walk did not '
            .'roll back and the person is half-anonymised with nothing recording where it '
            .'stopped.');
    }

    /**
     * The same hazard again, one class narrower — and the reason the guard is
     * TYPED rather than merely "not a database error".
     *
     * Re-throwing `PDOException` closed the deadlock case and left this one
     * open: `EncryptException`, `DecryptException` and `JsonEncodingException`
     * all extend `RuntimeException` and none of them is a database error, so a
     * `catch (RuntimeException)` still swallowed them. That is not a shape
     * somebody had to go looking for. This walk erases `onboarding_answers.
     * value`, which is written through an `encrypted` cast, and saving a model
     * compares each cast attribute against its ORIGINAL — so a row whose stored
     * ciphertext cannot be decrypted raises while the erase is being performed.
     *
     * Swallowed, that row would have been reported as a considered refusal: the
     * person's answer surviving an erasure request, with a sentence about a
     * cipher published as the reason it was kept — and, because the report says
     * only that something was kept, no sign that anything had gone wrong at all.
     *
     * The ciphertext is corrupted through the query builder so the cast is
     * bypassed on the way in. That is the state a rotated or truncated
     * `APP_KEY` leaves behind, which is the realistic way this arrives.
     */
    public function test_a_cipher_failure_on_the_erase_path_is_not_a_refusal(): void
    {
        [$alpha, $employee, $onboardingId] = $this->aSignedOnboarding();

        $this->inTenant($alpha['org'], fn () => DB::table('onboarding_answers')
            ->where('onboarding_id', $onboardingId)
            ->update(['value' => 'base64:this-is-not-a-payload-this-key-cannot-read']));

        $raised = null;

        try {
            $this->purge($employee);
        } catch (\Throwable $e) {
            $raised = $e;
        }

        $this->assertInstanceOf(DecryptException::class, $raised,
            'A cipher failure was caught as though the model had refused the row, so the '
            .'purge report would have offered a decryption error as the considered reason '
            .'somebody\'s answers were kept. Only a refusal the code DECLARES — '
            .'WrittenOnceRefusal — may become a line of that report.');
    }

    /**
     * The same hazard on the other path, because the two catches were separate.
     *
     * `jobs.customer_id` is `restrictOnDelete` — a customer with work against
     * them cannot be removed, which is the schema making §9's argument for
     * anonymising in place. CustomerRowsDroppedOutright declares `drop` and does
     * not soft-delete, so the DELETE reaches the database and comes back 1451.
     *
     * The real Customer is why this needs a fixture at all: it soft-deletes, and
     * a soft delete is an UPDATE that touches no foreign key.
     */
    public function test_a_database_error_on_the_drop_path_is_not_a_refusal_either(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $customer = $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create([
                'owning_franchise_id' => $alpha['franchise']->id,
                'number' => 'C-00042', 'name' => 'Marta Ruiz',
                'email' => 'marta@example.test',
            ]);

            Job::create([
                'franchise_id' => $alpha['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-00007', 'title' => 'Annual service',
                'status' => 'scheduled',
            ]);

            return $customer;
        });

        $raised = null;

        try {
            $this->purgeWalking([Customer::class, CustomerRowsDroppedOutright::class], $customer);
        } catch (\Throwable $e) {
            $raised = $e;
        }

        $this->assertInstanceOf(QueryException::class, $raised,
            'A foreign-key violation was filed as a refusal. "we could not erase this and '
            .'here is the reason" is meant to be a sentence somebody can act on; a constraint '
            .'name from the driver is a bug wearing that sentence\'s clothes.');

        $this->inTenant($alpha['org'], function () use ($customer) {
            $this->assertSame('Marta Ruiz',
                Customer::withTrashed()->findOrFail($customer->getKey())->name,
                'The blanking done before the failed delete survived it, so the walk is not '
                .'all-or-nothing after all.');
        });
    }

    // ── helpers ──────────────────────────────────────────────────

    private function purge(Model $subject): array
    {
        return app(Anonymiser::class)->purge($subject);
    }

    /**
     * The same Anonymiser, walking a list this test chose.
     *
     * Nothing is stubbed and no seam was added: the class already takes
     * DeclaringModels through its constructor, so replacing that one answer in
     * the container is enough. Everything the purge then does — the transaction,
     * the declarations, the report — is the production path.
     *
     * @param  array<class-string>  $walk  models, in the order they are visited
     */
    private function purgeWalking(array $walk, Model $subject, bool $dryRun = false): array
    {
        $this->app->instance(DeclaringModels::class, new DeclaresTheseModels($walk));

        $anonymiser = app(Anonymiser::class);

        return $dryRun ? $anonymiser->preview($subject) : $anonymiser->purge($subject);
    }

    private function tableLine(array $report, string $table): array
    {
        foreach ($report['tables'] as $line) {
            if ($line['table'] === $table) {
                return $line;
            }
        }

        $this->fail("The purge report does not mention {$table} at all.");
    }

    /** An employee with a session, an invitation and some audit history. */
    private function anEmployee(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $role = $this->roleFor($alpha);

        $invitation = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email' => 'newstarter@example.test',
            'first_name' => 'Marta',
            'last_name' => 'Ruiz',
            'role_id' => $role->id,
            'scope_type' => 'franchise',
            'scope_id' => $alpha['franchise']->id,
        ])->assertCreated();

        $this->accept($this->tokenFrom($invitation->json('data.accept_url')), $alpha);

        $employee = $this->inTenant(
            $alpha['org'],
            fn () => User::where('email', 'newstarter@example.test')->firstOrFail(),
        );

        return [$alpha, $employee, $invitation->json('data.id')];
    }

    /** The same employee, with a contract signed by both sides. */
    private function aSignedOnboarding(): array
    {
        [$alpha, $employee, $invitationId] = $this->anEmployee();

        $contractId = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => 'Driver',
            'fields' => [
                ['key' => 'wage', 'label' => 'Hourly wage', 'type' => 'number',
                    'filled_by' => 'employer', 'required' => true],
                ['key' => 'iban', 'label' => 'Bank account', 'type' => 'text',
                    'filled_by' => 'either', 'required' => true, 'sensitive' => true],
                ['key' => 'employer_sign', 'label' => 'For the employer', 'type' => 'signature',
                    'filled_by' => 'employer', 'required' => true],
                ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature',
                    'filled_by' => 'employee', 'required' => true],
            ],
        ])->assertCreated()->json('data.id');

        $id = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitationId,
            'contract_id' => $contractId,
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50', 'iban' => 'ES91 2100 0418 4502 0005 1332'],
        ])->assertOk();

        $this->signAs($alpha['admin'], $id, 'employer_sign', $alpha['admin']->name);
        $this->signAs($employee, $id, 'employee_sign', $employee->name);

        return [$alpha, $employee, $id];
    }

    private function signAs(User $actor, string $id, string $field, string $name): TestResponse
    {
        $hash = $this->as($actor)->getJson("/api/v1/hr/onboardings/{$id}/document")
            ->assertOk()->json('data.content_hash');

        return $this->as($actor)->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key' => $field,
            'affirmation' => $name,
            'consent' => true,
            'drawing' => self::SIGNATURE_PNG,
            'document_hash' => $hash,
        ])->assertCreated();
    }

    private function roleFor(array $tenant): Role
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Technician',
                'is_system' => false,
            ]);

            $role->syncPermissionKeys(['customers.view']);

            return $role;
        });
    }

    private function accept(string $token, array $tenant): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        $this->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'password' => 'a-real-password',
            'phone' => '+34 600 000 000',
            'address' => '1 Test Street',
            'date_of_birth' => '1990-04-03',
        ])->assertCreated();
    }

    private function tokenFrom(?string $url): string
    {
        $this->assertIsString($url, 'The invitation response carried no accept_url.');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['token'];
    }

    private function as(User $actor): static
    {
        $this->app->forgetScopedInstances();

        return $this->signedInAs($actor);
    }
}
