<?php

namespace Tests\Feature\Api;

use App\Models\ContractSignature;
use App\Models\Role;
use App\Models\User;
use App\Support\Privacy\DeclaringModels;
use App\Support\Privacy\RecordsConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Consent (§9) — read out of where it was actually taken.
 *
 * The audit said consent was absent. It was not absent; it was unlisted. ESIGN's
 * agreement to transact electronically has been captured correctly since the
 * signature table was built — verbatim words, its own timestamp, on the row that
 * evidences the act — and what was missing was any way to ask "what has this
 * person agreed to".
 *
 * So these tests are as much about what was NOT built. There is no consents
 * table, nothing is copied, and the ledger's second job is to say plainly that
 * most of what this product does is not consented to and must not be described
 * as though it were.
 */
class ConsentTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * The one real consent, with the words that were on screen.
     *
     * The verbatim statement is the load-bearing part, and it is the same
     * argument the signature migration already made: a boolean "records that
     * somebody ticked something; it cannot say what". A purpose code would have
     * the same defect — revise the wording and every historical row silently
     * starts claiming agreement to text its signer never read.
     */
    public function test_it_lists_the_electronic_transaction_consent_verbatim(): void
    {
        [$alpha, $employee] = $this->aSignedOnboarding();

        $body = $this->as($alpha['admin'])
            ->getJson("/api/v1/hr/people/{$employee->getKey()}/consents")
            ->assertOk()->json();

        $this->assertCount(1, $body['consents'],
            'One consent is the honest answer for this product today. Two means something '
            .'started calling processing a choice.');

        $consent = $body['consents'][0];

        $this->assertSame('electronic_transaction', $consent['kind']);
        $this->assertNotEmpty($consent['given_at']);
        $this->assertSame('contract_signature', $consent['evidence']['type']);

        // The exact sentence off the row, not one re-composed from Ceremony.
        $stored = $this->inTenant($alpha['org'], fn () => ContractSignature::query()
            ->where('signer_user_id', $employee->getKey())->firstOrFail());

        $this->assertSame($stored->consent_statement, $consent['statement']);
        $this->assertStringContainsString($alpha['org']->name, $consent['statement']);

        // And the act — which is the signature itself, because ESIGN's consent
        // and the signing were one motion. A ledger claiming a separate gesture
        // would be describing a ceremony this software does not run.
        $this->assertStringContainsString($employee->name, $consent['act']);
    }

    /**
     * Revising the wording changes nothing about what anybody already agreed to.
     *
     * The statement comes off the row, so the ledger inherits the property the
     * text columns were chosen for. Reading it back through Ceremony would have
     * silently rewritten history the first time the sentence was improved.
     */
    public function test_the_statement_is_the_one_that_was_shown_not_the_current_wording(): void
    {
        [$alpha, $employee] = $this->aSignedOnboarding();

        $this->inTenant($alpha['org'], function () use ($employee) {
            // Simulating an older record: the wording as it was two versions ago.
            // Written at the query-builder level because a signature is immutable
            // through every ordinary path, which is the point.
            DB::table('contract_signatures')
                ->where('signer_user_id', $employee->getKey())
                ->update(['consent_statement' => 'An older sentence, since revised.']);
        });

        $consent = $this->as($alpha['admin'])
            ->getJson("/api/v1/hr/people/{$employee->getKey()}/consents")
            ->assertOk()->json('consents.0');

        $this->assertSame('An older sentence, since revised.', $consent['statement']);
    }

    /**
     * The part that stops the ledger being misread.
     *
     * The failure mode of a consent list is a reader concluding that everything
     * absent from it was never processed — or that everything the product does
     * was consented to and can therefore be withdrawn. Neither is true, and
     * saying so is cheaper than unsaying it later.
     */
    public function test_it_publishes_what_is_not_consent(): void
    {
        [$alpha, $employee] = $this->aSignedOnboarding();

        $body = $this->as($alpha['admin'])
            ->getJson("/api/v1/hr/people/{$employee->getKey()}/consents")
            ->assertOk()->json();

        $this->assertNotEmpty($body['not_consent']);

        $joined = strtolower(implode(' ', $body['not_consent']));

        $this->assertStringContainsString('employment', $joined);
        $this->assertStringContainsString('audit', $joined);
    }

    /**
     * Withdrawal is answered honestly rather than implied.
     *
     * A consent record that did not say this would imply a toggle exists.
     * Withdrawing consent to have signed something electronically does not unsign
     * it — the consent governs the medium and the agreement has been reached.
     */
    public function test_each_consent_says_whether_it_can_be_withdrawn(): void
    {
        [$alpha, $employee] = $this->aSignedOnboarding();

        $consent = $this->as($alpha['admin'])
            ->getJson("/api/v1/hr/people/{$employee->getKey()}/consents")
            ->assertOk()->json('consents.0');

        $this->assertNotEmpty($consent['withdrawal']);
        $this->assertStringContainsString('paper', strtolower($consent['withdrawal']),
            'The consent statement itself promises a paper copy on request, so the answer '
            .'about withdrawal has to mention the thing the person can actually ask for.');
    }

    public function test_somebody_who_may_not_see_the_person_may_not_see_their_consents(): void
    {
        [$alpha, $employee] = $this->aSignedOnboarding();

        $outsider = $this->userWithRole($alpha, 'Outsider', ['customers.view']);

        $this->as($outsider)
            ->getJson("/api/v1/hr/people/{$employee->getKey()}/consents")
            ->assertForbidden();
    }

    /**
     * Nothing was built that stores consent a second time.
     *
     * The obvious build was a `consents` table. It would have been two homes for
     * one fact with no way to say which was authoritative — and, worse, it would
     * have invited every processing purpose to acquire a row and imply a
     * withdrawal that does nothing.
     */
    public function test_consent_is_read_from_the_evidence_rather_than_copied(): void
    {
        $this->assertFalse(
            Schema::hasTable('consents'),
            'A consents table appeared. Consent is recorded where it was taken — see '
            .'App\Support\Privacy\ConsentRecord for why a generic table is the wrong shape '
            .'for this product.',
        );

        $recorders = app(DeclaringModels::class)->recordingConsent();

        $this->assertSame([ContractSignature::class], $recorders,
            'The list of things this product asks consent for changed. That is a decision '
            .'worth making deliberately: see RecordsConsent on what implementing it claims.');

        $this->assertTrue(is_subclass_of(ContractSignature::class, RecordsConsent::class));
    }

    // ── helpers ──────────────────────────────────────────────────

    private function aSignedOnboarding(): array
    {
        $alpha = $this->provisionTenant('Alpha');

        $role = $this->inTenant($alpha['org'], function () use ($alpha) {
            $role = Role::create([
                'organization_id' => $alpha['org']->id,
                'name' => 'Technician', 'is_system' => false,
            ]);
            $role->syncPermissionKeys(['customers.view']);

            return $role;
        });

        $invitation = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email' => 'newstarter@example.test',
            'first_name' => 'Marta', 'last_name' => 'Ruiz',
            'role_id' => $role->id,
            'scope_type' => 'franchise', 'scope_id' => $alpha['franchise']->id,
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        parse_str((string) parse_url($invitation->json('data.accept_url'), PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/invitations/accept', [
            'token' => $query['token'],
            'password' => 'a-real-password',
            'phone' => '+34 600 000 000',
            'date_of_birth' => '1990-04-03',
        ])->assertCreated();

        $employee = $this->inTenant(
            $alpha['org'],
            fn () => User::where('email', 'newstarter@example.test')->firstOrFail(),
        );

        $contractId = $this->as($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => 'Driver',
            'fields' => [
                ['key' => 'wage', 'label' => 'Hourly wage', 'type' => 'number',
                    'filled_by' => 'employer', 'required' => true],
                ['key' => 'employer_sign', 'label' => 'For the employer', 'type' => 'signature',
                    'filled_by' => 'employer', 'required' => true],
                ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature',
                    'filled_by' => 'employee', 'required' => true],
            ],
        ])->assertCreated()->json('data.id');

        $id = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitation->json('data.id'),
            'contract_id'   => $contractId,
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50'],
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
            'field_key' => $field, 'affirmation' => $name,
            'consent' => true, 'document_hash' => $hash,
        ])->assertCreated();
    }

    private function as(User $actor): static
    {
        $this->app->forgetScopedInstances();

        return $this->signedInAs($actor);
    }
}
