<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\ContractSignature;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\OnboardingDocument;
use App\Models\Role;
use App\Models\User;
use App\Support\Privacy\Anonymiser;
use App\Support\Privacy\DeclaringModels;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\PersonalDataEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
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
                'customer_id'  => $customer->id,
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

        $this->assertSame('Removed account', $fresh->name);
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
            $this->assertSame('Removed account', $event->actor_label);
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
        $beta  = $this->provisionTenant('Beta');

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

        $name  = $employee->name;
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
                    $this->assertTrue(
                        Schema::hasColumn($table, $column),
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

    // ── helpers ──────────────────────────────────────────────────

    private function purge(Model $subject): array
    {
        return app(Anonymiser::class)->purge($subject);
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
        $role  = $this->roleFor($alpha);

        $invitation = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => 'newstarter@example.test',
            'first_name' => 'Marta',
            'last_name'  => 'Ruiz',
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
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
            'name'   => 'Driver',
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
            'contract_id'   => $contractId,
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
            'field_key'     => $field,
            'affirmation'   => $name,
            'consent'       => true,
            'document_hash' => $hash,
        ])->assertCreated();
    }

    private function roleFor(array $tenant): Role
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name'            => 'Technician',
                'is_system'       => false,
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
            'token'         => $token,
            'password'      => 'a-real-password',
            'phone'         => '+34 600 000 000',
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
