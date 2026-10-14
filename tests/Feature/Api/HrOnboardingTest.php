<?php

namespace Tests\Feature\Api;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\ContractSignature;
use App\Models\EmploymentEvent;
use App\Models\Invitation;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\OnboardingDocument;
use App\Models\Role;
use App\Models\User;
use App\Support\Contracts\DocumentFingerprint;
use App\Support\Privacy\SensitiveValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Onboarding — the two-sided form, the signature and the document
 * (EZBIZI-HR-PLAN.md).
 *
 * This is the first REAL user of three mechanisms that had none, so a good half
 * of what is proved here is about them rather than about the form:
 *
 *   HoldsSensitiveValues + MasksSensitiveValues   the answers table is the first
 *          implementor, and the first where sensitivity is a property of the ROW
 *          rather than of the class
 *   attachments / FileStore                       file answers, and the document
 *   ContractSignature + DocumentFingerprint       nothing wrote one before
 *
 * The rest is the two-sided rule, which is only worth anything if the SERVER
 * refuses — a disabled box on a screen is a suggestion.
 */
class HrOnboardingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const IBAN = 'ES91 2100 0418 4502 0005 1332';

    protected function setUp(): void
    {
        parent::setUp();

        // FileStore reads `filesystems.default`, which is `local` under test —
        // so faking that disk fakes the whole store rather than shadowing it.
        Storage::fake('local');
    }

    // ── 1. the two sides ─────────────────────────────────────────

    /**
     * Both directions, because only testing one leaves half the rule unproven.
     *
     * The employer's field and the employee's field are refused to the other
     * side, and the `either` field is written by both — which is the case the
     * owner named: the employer pre-fills a date of birth wrongly and the
     * employee corrects it on receipt.
     */
    public function test_the_two_sided_rule_is_enforced_in_both_directions(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        // The employee reaching for the employer's field.
        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '19.00'],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.wage');

        // And the employer reaching for the employee's.
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['emergency_contact' => 'Ana, 600 111 222'],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.emergency_contact');

        // Each side's own field goes through.
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['emergency_contact' => 'Ana, 600 111 222'],
        ])->assertOk();

        $this->assertSame('18.50', $this->rawAnswer($alpha, $id, 'wage'));
        $this->assertSame('Ana, 600 111 222', $this->rawAnswer($alpha, $id, 'emergency_contact'));
    }

    /**
     * A refused field is not a partially applied save.
     *
     * The brief asks for this on its own, and it is worth its own test for a
     * reason the combined one above cannot show: a save carrying one legitimate
     * field and one forbidden one must write NEITHER. Dropping the forbidden one
     * quietly would leave somebody believing they had set a field the other side
     * owns.
     */
    public function test_a_field_the_employee_may_not_write_is_refused_and_nothing_else_is_saved(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => [
                'emergency_contact' => 'Ana, 600 111 222',   // theirs
                'wage'              => '99.00',              // not theirs
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.wage');

        $this->assertNull($this->rawAnswer($alpha, $id, 'wage'));
        $this->assertNull(
            $this->rawAnswer($alpha, $id, 'emergency_contact'),
            'A save carrying one forbidden field wrote the rest of it anyway.',
        );
    }

    /**
     * `either` means either, and the provenance is what makes that safe.
     *
     * Two people writing one field is the whole reason `set_by_user_id` exists:
     * without it, "the employer typed it" and "I corrected it" are the same row,
     * and for a bank account that difference is the point.
     */
    public function test_either_can_be_written_by_both_and_the_answer_says_who_last_did(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['iban' => 'ES00 WRONG'],
        ])->assertOk();

        $this->assertSame('employer', $this->fieldIn($this->form($alpha['admin'], $id), 'iban')['set_by']['party']);

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['iban' => self::IBAN],
        ])->assertOk();

        $field = $this->fieldIn($this->form($employee, $id), 'iban');

        $this->assertSame('employee', $field['set_by']['party']);
        $this->assertSame($employee->getKey(), $field['set_by']['id']);
        $this->assertSame(self::IBAN, $this->rawAnswer($alpha, $id, 'iban'));
    }

    /**
     * Core identity is the employee's, and the employer is read-only on it.
     *
     * The plan's Records table says so, and the one-home rule is why: the name
     * lives on the profile, and a second door to it here is how two copies of one
     * fact start.
     */
    public function test_core_identity_belongs_to_the_person_once_they_have_an_account(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['first_name' => 'Marta-Renamed'],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.first_name');

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['first_name' => 'Marta', 'phone' => '+34 600 999 888'],
        ])->assertOk();

        // Written THROUGH to the profile, not copied into the answers table.
        $this->assertSame('+34 600 999 888', $employee->fresh()->phone);
        $this->assertNull($this->rawAnswer($alpha, $id, 'phone'));

        // And the display name is recomposed, exactly as "Your account" does it.
        $this->assertSame('Marta Ruiz', $employee->fresh()->name);
    }

    // ── 2. save and resume ───────────────────────────────────────

    /**
     * A half-filled form is the normal state of a form.
     *
     * Somebody waiting on a document they have to go and scan must be able to put
     * it down. So an incomplete save is a 200 and the missing list is reported
     * rather than enforced — and the answers are still there on the next request,
     * from either side.
     */
    public function test_answers_save_and_resume_from_both_sides_without_being_complete(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $first = $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        // Reported, not refused — and it names what is left.
        $this->assertArrayHasKey('emergency_contact', $first->json('data.missing'));
        $this->assertSame('in_progress', $first->json('data.status'));

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['iban' => self::IBAN],
        ])->assertOk();

        // Both sides' work is still there, and each can see the other's.
        $resumed = $this->form($employee, $id);

        $this->assertSame('18.50', $this->fieldIn($resumed, 'wage')['value']);
        $this->assertArrayHasKey('emergency_contact', $this->form($alpha['admin'], $id)['missing']);

        // Clearing an answer removes it rather than storing a blank, so "not
        // answered" and "answered with nothing" cannot both exist.
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => null],
        ])->assertOk();

        $this->assertNull($this->rawAnswer($alpha, $id, 'wage'));
        $this->assertArrayHasKey('wage', $this->form($alpha['admin'], $id)['missing']);
    }

    /** Format is checked on save; it is completeness that waits for submit. */
    public function test_a_value_the_field_cannot_hold_is_refused_on_save(): void
    {
        [$alpha, , $id] = $this->scenario();

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => 'about twenty quid'],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.wage');
    }

    // ── 3. sensitive answers ─────────────────────────────────────

    /**
     * "Unreadable in a database dump" is a property of the bytes, not of the API.
     *
     * So this reads the row with the query builder, past every cast and accessor,
     * and looks for the string. And it checks the WHOLE row, in case a future
     * column starts carrying a copy.
     */
    public function test_a_sensitive_answer_is_unreadable_in_a_dump_of_the_row(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['iban' => self::IBAN],
        ])->assertOk();

        $row = DB::table('onboarding_answers')->where('field_key', 'iban')->first();

        $this->assertNotSame(self::IBAN, $row->value);
        $this->assertStringNotContainsString(self::IBAN, (string) $row->value);
        $this->assertStringNotContainsString(self::IBAN, (string) json_encode($row));

        // Every answer is encrypted, not only the flagged one — nobody can mark
        // a field sensitive after the fact and find the old answers in the clear.
        $plain = DB::table('onboarding_answers')->where('field_key', 'emergency_contact')->first();

        if ($plain !== null) {
            $this->assertStringNotContainsString('Ana', (string) $plain->value);
        }

        // Still readable by the application, or the encryption would be a very
        // secure way of losing data.
        $this->assertSame(self::IBAN, $this->rawAnswer($alpha, $id, 'iban'));
    }

    /**
     * Masked by default for EVERYBODY, revealed only by asking, and the asking is
     * an audit event.
     *
     * The mask has to come out of ordinary serialisation. `SensitiveValues::read`
     * masking is not enough on its own — that mistake has already been made once
     * here — so the assertion is made against the response the screen actually
     * gets.
     */
    public function test_a_sensitive_answer_is_masked_in_the_form_and_revealing_it_is_audited(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['iban' => self::IBAN, 'emergency_contact' => 'Ana'],
        ])->assertOk();

        $body = $this->as($alpha['admin'])->getJson("/api/v1/hr/onboardings/{$id}")->assertOk();

        $this->assertSame(SensitiveValues::MASK, $this->fieldIn($body->json('data'), 'iban')['value']);
        // Not everything nearby — masking is not redaction of the whole form.
        $this->assertSame('Ana', $this->fieldIn($body->json('data'), 'emergency_contact')['value']);
        $this->assertStringNotContainsString('2100 0418', $body->content());

        // Opening the screen is not a disclosure, so nothing is recorded for it.
        $this->assertSame(0, $this->revealEvents($alpha)->count());

        $revealed = $this->as($alpha['admin'])
            ->getJson("/api/v1/hr/onboardings/{$id}/answers/iban")->assertOk();

        $this->assertSame(self::IBAN, $revealed->json('data.value'));
        $this->assertTrue($revealed->json('data.revealed'));

        $event = $this->revealEvents($alpha)->sole();

        $this->assertSame($alpha['admin']->getKey(), $event->actor_id);
        $this->assertSame('value', $event->metadata['field']);
        // The field, never the value: the log is append-only and read by more
        // people than a bank account should be.
        $this->assertStringNotContainsString(self::IBAN, (string) json_encode($event->toArray()));
    }

    /** Reading the tab is not the same authority as reading what is on it. */
    public function test_revealing_needs_more_than_being_able_to_open_the_screen(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['iban' => self::IBAN],
        ])->assertOk();

        $reader = $this->userWithRole($alpha, 'Reader', ['hr.view']);

        $this->as($reader)->getJson("/api/v1/hr/onboardings/{$id}")->assertOk();
        $this->as($reader)->getJson("/api/v1/hr/onboardings/{$id}/answers/iban")->assertForbidden();

        // A refused reveal is not a disclosure, so there is nothing to record —
        // and an entry would be indistinguishable from a successful one.
        $this->assertSame(0, $this->revealEvents($alpha)->count());

        // The person it is ABOUT may always see their own, with no HR permission
        // of any kind.
        $this->as($employee)->getJson("/api/v1/hr/onboardings/{$id}/answers/iban")
            ->assertOk()
            ->assertJsonPath('data.value', self::IBAN);
    }

    // ── 4. file answers ──────────────────────────────────────────

    /**
     * A file answer is an attachment on the onboarding, not a second upload path.
     *
     * Which also means the employee can attach their own licence with no HR
     * permission at all — AttachmentsController authorizes `update` on the
     * subject, and the onboarding's policy knows the thing no `attachments.*`
     * permission ever could: this is their own record.
     */
    public function test_a_file_answer_goes_through_the_attachment_primitive(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $upload = $this->as($employee)->postJson('/api/v1/attachments', [
            'attachable_type' => 'onboardings',
            'attachable_id'   => $id,
            'file'            => UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.4\n%%EOF\n"),
        ])->assertCreated();

        $attachmentId = $upload->json('data.id');

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['licence' => $attachmentId],
        ])->assertOk();

        $this->assertSame($attachmentId, $this->rawAnswer($alpha, $id, 'licence'));

        // The bytes come back through the ordinary file endpoint, authorized
        // against the onboarding rather than against a permission of its own.
        $this->as($employee)->get("/api/v1/attachments/{$attachmentId}")->assertOk();

        // An id belonging to somebody else's record is refused: a contract must
        // not cite a document its own authorization does not cover.
        $other = $this->as($alpha['admin'])->postJson('/api/v1/attachments', [
            'attachable_type' => 'jobs',
            'attachable_id'   => $this->aJob($alpha),
            'file'            => UploadedFile::fake()->createWithContent('other.pdf', "%PDF-1.4\n%%EOF\n"),
        ])->assertCreated()->json('data.id');

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['licence' => $other],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.licence');
    }

    // ── 5. the ceremony ──────────────────────────────────────────

    /** Completeness is asked once, at signing, and not before. */
    public function test_signing_is_refused_while_a_required_answer_is_missing(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $hash = $this->documentHash($alpha['admin'], $id);

        $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key'     => 'employer_sign',
            'affirmation'   => 'Alpha Admin',
            'consent'       => true,
            'drawing' => self::SIGNATURE_PNG,
            'document_hash' => $hash,
        ])->assertStatus(422)->assertJsonValidationErrors('answers');

        // And nothing was frozen or stored by the attempt.
        $this->assertSame('in_progress', $this->form($alpha['admin'], $id)['status']);
        $this->assertSame(0, OnboardingDocument::withoutGlobalScopes()->count());
    }

    /**
     * The whole ceremony, and the one assertion the tamper-evidence reduces to.
     *
     * The signature's fingerprint is of the STORED FILE — not of a string the
     * server held in memory, and not of some other representation of the same
     * document. That is the plan's rule: "a fingerprint over one representation
     * and a document stored in another" proves nothing about the file.
     */
    public function test_signing_produces_a_document_whose_hash_detects_a_change(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin');

        // The employer signs first and the employee's side stays open (Jose,
        // 2026-10-01): still in progress, not submitted.
        $this->assertSame('in_progress', $this->form($alpha['admin'], $id)['status']);

        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz');

        $form = $this->form($employee, $id);

        $this->assertSame('completed', $form['status']);
        $this->assertCount(2, $form['signatures']);

        [$document, $signature] = $this->inTenant($alpha['org'], fn () => [
            OnboardingDocument::firstOrFail(),
            ContractSignature::firstOrFail(),
        ]);

        // The bytes on the disk, read past the application entirely.
        $attachment = $this->inTenant($alpha['org'], fn () => Attachment::findOrFail($document->attachment_id));
        $stored     = Storage::disk($attachment->disk)->get($attachment->path);

        $this->assertSame(DocumentFingerprint::of($stored), $document->content_hash);
        $this->assertSame($document->content_hash, $signature->content_hash);
        $this->assertSame(strlen($stored), $document->content_length);
        $this->assertTrue($signature->stillMatches($stored));

        // One character. A wage of 18.50 becoming 8.50 is exactly the dispute
        // this record exists to settle.
        $this->assertFalse($signature->stillMatches(str_replace('18.50', '8.50', $stored)));
        $this->assertFalse($signature->stillMatches($stored."\n"));

        // Both signatures fingerprint the SAME artefact, which is what makes any
        // of them checkable against the file somebody is holding.
        $hashes = $this->inTenant($alpha['org'], fn () => ContractSignature::pluck('content_hash')->unique());
        $this->assertCount(1, $hashes);

        // The document says what was agreed, in the words the tenant chose.
        $this->assertStringContainsString('Hourly wage', $stored);
        $this->assertStringContainsString('18.50', $stored);
    }

    /**
     * /me names the new starter's own unfinished onboarding, which is how the
     * app draws "Your paperwork" for a role with no HR tab (session.dart
     * onboardingId). The client read the key for weeks and the server never
     * sent it: a new starter had no way to reach their form (2026-09-30).
     */
    public function test_me_names_the_starters_own_unfinished_onboarding_until_it_is_done(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $mine = fn (User $who) => $this->as($who)->getJson('/api/v1/me')->assertOk()->json('data.onboarding');

        $this->assertSame(['id' => $id, 'status' => 'in_progress', 'held' => false], $mine($employee));
        // The control: the employer RUNS it and is not its subject.
        $this->assertNull($mine($alpha['admin']));

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin');
        $this->assertSame($id, $mine($employee)['id'] ?? null, 'Their own signature is still owed.');

        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz');
        $this->assertNull($mine($employee), 'A finished form stayed on their screen.');
    }

    /** The schedule picker's answer reaches the person's schedule as it is saved. */
    public function test_answering_the_work_hours_saves_the_persons_schedule(): void
    {
        [$alpha, $employee, $id] = $this->scenario([
            ['key' => 'work_days', 'label' => 'Work days', 'type' => 'text', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'work_hours', 'label' => 'Work hours', 'type' => 'text', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
        ]);

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => [
            'work_days' => 'Monday to Friday, Sunday',
            'work_hours' => 'Monday to Friday 2:00 PM to 8:00 PM; Sunday 8:00 AM to 8:00 PM',
        ]])->assertOk();

        $days = $employee->fresh()->work_schedule['days'];
        $this->assertEquals(['start' => '14:00', 'end' => '20:00'], $days['monday']);
        $this->assertEquals(['start' => '08:00', 'end' => '20:00'], $days['sunday']);
    }

    /**
     * "Doesn't apply" on an employer's term leaves its clause out of the
     * contract, as A1 Tools' N/A did (Jose, 2026-10-01: "some sections can be
     * entirely voided so they don't appear in the contract").
     */
    public function test_a_term_that_does_not_apply_leaves_its_clause_out(): void
    {
        [$alpha, , $id] = $this->scenario([
            ['key' => 'hourly_rate', 'label' => 'Hourly rate', 'type' => 'number', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'raise_amount', 'label' => 'Raise', 'type' => 'number', 'filled_by' => 'employer', 'required' => false],
            ['key' => 'daily_deduction', 'label' => 'Deduction', 'type' => 'number', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
        ]);
        $this->inTenant($alpha['org'], function () use ($id) {
            DB::table('contract_versions')->where('id', Onboarding::findOrFail($id)->contract_version_id)->update(['body' =>
                "Pay: {{hourly_rate}} an hour.\n\n{{#raise_amount}}Scheduled increases: {{raise_amount}}.{{/raise_amount}}\n\n"
                ."Deduction: {{daily_deduction}} a day.\n\n(f) Absences\n\nAbsence costs {{daily_deduction}}.\n\n5. TERMINATION\n\nThe end."]);
        });

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => [
            'hourly_rate' => '20', 'raise_amount' => 'N/A', 'daily_deduction' => 'N/A',
        ]])->assertOk();

        $form = $this->form($alpha['admin'], $id);
        $this->assertArrayNotHasKey('daily_deduction', $form['missing'], 'Doesn\'t apply did not count as answered.');
        $this->assertTrue($this->fieldIn($form, 'daily_deduction')['voidable']);

        $content = $this->as($alpha['admin'])->getJson("/api/v1/hr/onboardings/{$id}/document")->json('data.content');
        $this->assertStringContainsString('Pay: 20 an hour.', $content);
        $this->assertStringContainsString('The end.', $content);
        $this->assertStringNotContainsString('Scheduled increases', $content);
        $this->assertStringNotContainsString('Deduction', $content);
        $this->assertStringNotContainsString('(f) Absences', $content, 'A heading stayed over a clause that went.');

        // Only the employer's terms: an employee cannot opt out of a question.
        $this->assertFalse($this->fieldIn($form, 'employee_sign')['voidable'] ?? false);
    }

    /**
     * Jose's second pass on the form (2026-10-01): the home address in parts,
     * related terms voided together, the typed answer marked in the contract,
     * and the PDF it will print as.
     */
    /** An employer's amount may be "To be determined" (Jose, 2026-10-06), and it counts as answered. */
    public function test_an_employer_term_can_be_to_be_determined(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => 'To be determined'],
        ])->assertOk();
        $this->assertSame('To be determined', $this->rawAnswer($alpha, $id, 'wage'));
        $this->assertArrayNotHasKey('wage', $this->form($alpha['admin'], $id)['missing']);
    }

    public function test_address_parts_void_groups_highlights_and_the_draft_pdf(): void
    {
        [$alpha, $employee, $id] = $this->scenario([
            ['key' => 'job_title', 'label' => 'Job title', 'type' => 'text', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'raise_amount', 'label' => 'Raise', 'type' => 'number', 'filled_by' => 'employer', 'required' => false],
            ['key' => 'raise_period', 'label' => 'Raise every', 'type' => 'text', 'filled_by' => 'employer', 'required' => false],
            // The contract's own `state`, beside the home address's state
            // part: UI/UX caught a flat part key colliding with it.
            ['key' => 'state', 'label' => 'Governing state', 'type' => 'text', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
        ]);
        $this->inTenant($alpha['org'], fn () => DB::table('contract_versions')
            ->where('id', Onboarding::findOrFail($id)->contract_version_id)
            ->update(['body' => "Job Title: {{job_title}}\n\nLives at {{address}}."]));

        // The address in parts, written to the profile and composed.
        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => [
            'address' => ['address_line1' => '41 Elm St', 'city' => 'Stamford', 'state' => 'CT', 'postal_code' => '06901'],
        ]])->assertOk();
        $this->assertSame('41 Elm St, Stamford, CT 06901', $employee->fresh()->address);
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['state' => 'Florida']])->assertOk();
        $this->assertSame('Florida', $this->rawAnswer($alpha, $id, 'state'));
        $this->assertSame('CT', $employee->fresh()->state, 'The governing state landed on the home address.');
        $address = collect($this->form($employee, $id)['fields'] ?? $this->form($employee, $id)['questions'] ?? [])
            ->firstWhere('key', 'address');
        $this->assertSame('Stamford', $address['parts']['city'] ?? null);

        // Every pay term is its own group now.
        $form = $this->form($alpha['admin'], $id);
        // Each pay term on its own (Jose, 2026-10-06: "she should be able to
        // turn off things individually").
        $this->assertSame('raise_amount', $this->fieldIn($form, 'raise_amount')['void_group']);
        $this->assertSame('raise_period', $this->fieldIn($form, 'raise_period')['void_group']);
        $this->assertSame('job_title', $this->fieldIn($form, 'job_title')['void_group']);

        // The answer being typed is marked in the live contract, and only there.
        $content = $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$id}/preview", [
            'answers' => ['job_title' => 'Dispatcher Master 5000'], 'highlight' => ['job_title'],
        ])->assertOk()->json('data.content');
        $this->assertStringContainsString('<mark data-key="job_title">Dispatcher Master 5000</mark>', $content);
        $this->assertStringNotContainsString('<mark', $this->as($alpha['admin'])
            ->getJson("/api/v1/hr/onboardings/{$id}/document")->json('data.content'));

        // The PDF view of the same draft.
        $pdf = $this->as($alpha['admin'])->post("/api/v1/hr/onboardings/{$id}/preview?format=pdf", [
            'answers' => ['job_title' => 'Dispatcher Master 5000'],
        ]);
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    /**
     * A hand-drawn signature, as A1 Tools had (Jose, 2026-10-01): kept with
     * the signature record, drawn into the printed copy, and reusable as the
     * signer's own saved signature.
     */
    public function test_a_drawn_signature_is_kept_and_a_signer_can_save_one(): void
    {
        // A 1x1 PNG.
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        [$alpha, $employee, $id] = $this->completeForm();

        $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key' => 'employer_sign', 'affirmation' => 'Alpha Admin', 'consent' => true,
            'drawing' => self::SIGNATURE_PNG,
            'document_hash' => $this->documentHash($alpha['admin'], $id), 'drawing' => 'not a picture',
        ])->assertStatus(422)->assertJsonValidationErrors('drawing');

        $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key' => 'employer_sign', 'affirmation' => 'Alpha Admin', 'consent' => true,
            'drawing' => self::SIGNATURE_PNG,
            'document_hash' => $this->documentHash($alpha['admin'], $id), 'drawing' => $png,
        ])->assertCreated();

        $drawing = $this->inTenant($alpha['org'], fn () => Attachment::findOrFail(
            ContractSignature::where('field_key', 'employer_sign')->sole()->drawing_attachment_id));
        $this->assertSame('image/png', $drawing->mime);

        // The printed copy is made with the drawing in its signature block.
        $this->as($alpha['admin'])->get("/api/v1/hr/onboardings/{$id}/document/pdf?revision=1")->assertOk();

        // A signer's own saved signature: theirs, and never in /me.
        $this->as($alpha['admin'])->putJson('/api/v1/me/signature', ['drawing' => $png])->assertOk();
        $this->assertSame($png, $this->as($alpha['admin'])->getJson('/api/v1/me/signature')->json('data.drawing'));
        $this->assertNull($this->as($employee)->getJson('/api/v1/me/signature')->json('data.drawing'));
        $this->assertStringNotContainsString('iVBORw0KGgo', $this->as($alpha['admin'])->getJson('/api/v1/me')->getContent());
        $this->as($alpha['admin'])->deleteJson('/api/v1/me/signature')->assertOk();
        $this->assertNull($this->as($alpha['admin'])->getJson('/api/v1/me/signature')->json('data.drawing'));
    }

    /**
     * The employer may mark one of the employee's questions as not applying,
     * a uniform photo for a call-center hire (Jose, 2026-10-01), and take it
     * back. The employee may not void their own.
     */
    public function test_the_employer_can_void_an_employee_question_and_the_employee_cannot(): void
    {
        [$alpha, $employee, $id] = $this->scenario([
            ['key' => 'uniform_photo', 'label' => 'Photo in uniform', 'type' => 'file', 'filled_by' => 'employee', 'required' => true],
            ['key' => 'bank_name', 'label' => 'Bank name', 'type' => 'text', 'filled_by' => 'employee', 'required' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
        ]);

        $this->assertTrue($this->fieldIn($this->form($alpha['admin'], $id), 'uniform_photo')['voidable']);
        $this->assertFalse($this->fieldIn($this->form($employee, $id), 'uniform_photo')['voidable']);

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['bank_name' => 'N/A']])
            ->assertStatus(422)->assertJsonValidationErrors('answers.bank_name');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['uniform_photo' => 'N/A']])->assertOk();
        $this->assertArrayNotHasKey('uniform_photo', $this->form($alpha['admin'], $id)['missing']);

        // Taken back: asked again.
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['uniform_photo' => '']])->assertOk();
        $this->assertArrayHasKey('uniform_photo', $this->form($alpha['admin'], $id)['missing']);

        // Voiding is all the employer may do to the employee's answer.
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['bank_name' => 'Chase']])
            ->assertStatus(422);
    }

    /**
     * The W-4 is filled in and signed in the app, not uploaded (Jose,
     * 2026-10-01: "check how a1tools did it"): the signed form becomes the
     * tax_form answer, and only the new hire can sign it.
     */
    public function test_the_new_hire_fills_and_signs_the_w4_in_the_app(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        [$alpha, $employee, $id] = $this->scenario([
            ['key' => 'tax_id_type', 'label' => 'Tax ID', 'type' => 'choice', 'options' => ['SSN', 'EIN'], 'filled_by' => 'employee', 'required' => true],
            ['key' => 'tax_form', 'label' => 'W-4 or W-9, signed', 'type' => 'file', 'filled_by' => 'employee', 'required' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
        ]);
        $url = "/api/v1/hr/onboardings/{$id}/tax-form";

        // Which form, decided by the server from the tax ID: none until it is answered.
        $kind = fn () => $this->fieldIn($this->form($employee, $id), 'tax_form')['tax_form_kind'];
        $this->assertNull($kind());
        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['tax_id_type' => 'EIN']])->assertOk();
        $this->assertSame('w9', $kind());
        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['tax_id_type' => 'SSN']])->assertOk();
        $this->assertSame('w4', $kind());
        $body = ['kind' => 'w4', 'values' => ['ssn' => '123-45-6789', 'filing_status' => 'single'], 'drawing' => $png];

        // The employer cannot sign the new hire's tax form.
        $this->as($alpha['admin'])->postJson($url, $body)->assertStatus(422);

        // A look before signing, which stores nothing.
        $preview = $this->as($employee)->post($url.'?preview=1', ['kind' => 'w4', 'values' => []]);
        $preview->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertNull($this->rawAnswer($alpha, $id, 'tax_form'));

        // Signing needs the drawing.
        $this->as($employee)->postJson($url, ['kind' => 'w4', 'values' => []])
            ->assertStatus(422)->assertJsonValidationErrors('drawing');

        $this->as($employee)->postJson($url, $body)->assertOk();

        $file = $this->inTenant($alpha['org'], fn () => Attachment::findOrFail($this->rawAnswer($alpha, $id, 'tax_form')));
        // A picture of the signed page, which the contract's PDF prints.
        $this->assertSame('W-4.png', $file->original_name);
        $this->assertSame('image/png', $file->mime);
        $this->assertArrayNotHasKey('tax_form', $this->form($employee, $id)['missing']);
    }

    /**
     * The direct deposit form and the I-9 are filled from the answers and
     * signed in the app; the employer completes the I-9's Section 2 on the
     * signed page (Jose, 2026-10-06: "so it's all filled on ezbizi").
     */
    public function test_the_direct_deposit_form_and_the_i9_are_signed_in_the_app(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        [$alpha, $employee, $id] = $this->scenario([
            ['key' => 'tax_id_type', 'label' => 'Tax ID', 'type' => 'choice', 'options' => ['SSN', 'EIN'], 'filled_by' => 'employee', 'required' => true],
            ['key' => 'bank_routing_number', 'label' => 'Routing', 'type' => 'text', 'filled_by' => 'employee', 'required' => true],
            ['key' => 'direct_deposit_form', 'label' => 'Direct deposit', 'type' => 'file', 'filled_by' => 'employee', 'required' => true],
            ['key' => 'i9_form', 'label' => 'I-9', 'type' => 'file', 'filled_by' => 'employee', 'required' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
        ]);
        $url = "/api/v1/hr/onboardings/{$id}/tax-form";
        $field = fn (string $key) => $this->fieldIn($this->form($employee, $id), $key);
        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => [
            'tax_id_type' => 'SSN', 'bank_routing_number' => '021000021',
        ]])->assertOk();
        $this->assertSame('direct_deposit', $field('direct_deposit_form')['tax_form_kind']);
        $this->assertSame('i9', $field('i9_form')['tax_form_kind']);

        // Each asks its one question before it can be signed.
        $this->as($employee)->postJson($url, ['kind' => 'direct_deposit', 'values' => [], 'drawing' => $png])
            ->assertStatus(422)->assertJsonValidationErrors('values.account_type');
        $this->as($employee)->postJson($url, ['kind' => 'direct_deposit', 'values' => ['account_type' => 'checking'], 'drawing' => $png])->assertOk();
        $this->as($employee)->postJson($url, ['kind' => 'i9', 'values' => [], 'drawing' => $png])
            ->assertStatus(422)->assertJsonValidationErrors('values.status');
        $this->as($employee)->postJson($url, ['kind' => 'i9', 'values' => ['status' => 'citizen', 'ssn' => '123456789'], 'drawing' => $png])->assertOk();

        $name = fn (string $key) => $this->inTenant($alpha['org'], fn () => Attachment::findOrFail($this->rawAnswer($alpha, $id, $key))->original_name);
        $this->assertSame('Direct deposit authorization.png', $name('direct_deposit_form'));
        $this->assertSame('I-9.png', $name('i9_form'));
        $this->assertFalse($this->form($alpha['admin'], $id)['i9']['section2']);
        $this->assertTrue($this->form($alpha['admin'], $id)['i9']['mine']);

        // Section 2 is the employer's, with a document from List A or B and C.
        $section2 = "/api/v1/hr/onboardings/{$id}/i9-section2";
        $this->as($employee)->postJson($section2, ['values' => ['a_title' => 'Passport'], 'drawing' => $png])->assertForbidden();
        $this->as($alpha['admin'])->postJson($section2, ['values' => ['b_title' => 'Driver licence'], 'drawing' => $png])
            ->assertStatus(422)->assertJsonValidationErrors('values');
        $this->as($alpha['admin'])->post($section2.'?preview=1', ['values' => []])
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->as($alpha['admin'])->postJson($section2, ['values' => ['a_title' => 'U.S. Passport', 'title' => 'Manager'], 'drawing' => $png])
            ->assertOk()->assertJsonPath('data.i9.section2', true);
        $this->assertSame(\App\Services\Hr\OnboardingDocuments::I9_COMPLETE, $name('i9_form'));

        // A contractor (W-9) has no I-9 to sign.
        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['tax_id_type' => 'EIN']])->assertOk();
        $this->assertNull($field('i9_form')['tax_form_kind']);
    }

    /**
     * A real drawing fits (production, 2026-10-09: 1406 "Data too long for
     * column 'saved_signature'" — TEXT held 64 KB of what is a 400 KB PNG
     * once encrypted).
     */
    public function test_a_full_size_saved_signature_fits(): void
    {
        [$alpha] = $this->scenario();
        $img = imagecreatetruecolor(400, 150);
        mt_srand(7);
        for ($x = 0; $x < 400; $x++) {
            for ($y = 0; $y < 150; $y++) {
                imagesetpixel($img, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        $this->assertGreaterThan(64 * 1024, strlen($bytes), 'Not big enough to have overflowed TEXT.');
        $png = 'data:image/png;base64,'.base64_encode($bytes);

        $this->as($alpha['admin'])->putJson('/api/v1/me/signature', ['drawing' => $png])->assertOk();
        $this->assertSame($png, $this->as($alpha['admin'])->getJson('/api/v1/me/signature')->json('data.drawing'));
    }

    /** Finished onboardings are their own archive (a Suggestion Jose said to build, 2026-10-01). */
    public function test_finished_onboardings_list_apart_from_those_in_progress(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();
        $ids = fn (string $state) => collect($this->as($alpha['admin'])
            ->getJson("/api/v1/hr/onboardings?state={$state}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$id], $ids('open'));
        $this->assertSame([], $ids('finished'));

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin');
        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz');

        $this->assertSame([], $ids('open'));
        $this->assertSame([$id], $ids('finished'));
    }

    /**
     * Started under the organization or a company: the contract names that
     * company as the employer, the onboarding is filed there and the person is
     * assigned there (Ana's Suggestion; Jose, 2026-10-01: "yes as well as to
     * where the person is assigned").
     */
    public function test_an_onboarding_started_under_a_company_names_it_and_places_the_person(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role = $this->roleFor($alpha);
        $contract = $this->aContract($alpha);
        $company = $this->inTenant($alpha['org'], fn () => \App\Models\Company::create([
            'organization_id' => $alpha['org']->id, 'name' => 'A-1 Chimney', 'is_active' => true,
            'address_line1' => '9 Flue Rd', 'city' => 'Miami', 'state' => 'FL', 'postal_code' => '33101',
        ]));
        $invite = fn (string $email) => $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email' => $email, 'first_name' => 'Gino', 'last_name' => 'Test', 'role_id' => $role->id,
            'scope_type' => 'organization', 'scope_id' => $alpha['org']->id,
        ])->assertCreated();

        // Not yet accepted: the invitation moves to the company.
        $waiting = $invite('waiting@example.test')->json('data.id');
        $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $waiting, 'contract_id' => $contract, 'company_id' => $company->id,
        ])->assertCreated()->assertJsonPath('data.company.name', 'A-1 Chimney');
        $this->assertSame($company->id, $this->inTenant($alpha['org'], fn () => Invitation::findOrFail($waiting)->assigned_company_id));

        // Accepted, assigned at the organization: their assignment moves.
        $accepted = $invite('accepted@example.test');
        $this->accept($this->tokenFrom($accepted->json('data.accept_url')));
        $id = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $accepted->json('data.id'), 'contract_id' => $contract, 'company_id' => $company->id,
        ])->assertCreated()->json('data.id');
        $person = $this->inTenant($alpha['org'], fn () => User::where('email', 'accepted@example.test')->firstOrFail());
        $this->assertSame([$company->id], $this->inTenant($alpha['org'],
            fn () => $person->assignments()->pluck('assigned_company_id')->all()));

        // The contract names the company and its address as the employer.
        $this->inTenant($alpha['org'], fn () => DB::table('contract_versions')
            ->where('id', Onboarding::findOrFail($id)->contract_version_id)
            ->update(['body' => 'Employer: {{organization.name}}, {{organization.address}}.']));
        $content = $this->as($alpha['admin'])->getJson("/api/v1/hr/onboardings/{$id}/document")->json('data.content');
        $this->assertStringContainsString('Employer: A-1 Chimney, 9 Flue Rd, Miami, FL 33101.', $content);
    }

    /** The contract beside the form, with what is being typed, and nothing saved. */
    public function test_the_preview_shows_unsaved_answers_and_keeps_a_saved_secret_masked(): void
    {
        [$alpha, , $id] = $this->scenario([
            ['key' => 'wage', 'label' => 'Hourly wage', 'type' => 'number', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'iban', 'label' => 'Bank account', 'type' => 'text', 'filled_by' => 'employer',
                'required' => true, 'sensitive' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
        ]);
        $this->inTenant($alpha['org'], function () use ($id) {
            $version = Onboarding::findOrFail($id)->contractVersion;
            DB::table('contract_versions')->where('id', $version->id)
                ->update(['body' => 'Paid {{wage}} an hour into {{iban}}.']);
        });
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['iban' => self::IBAN]])->assertOk();

        $content = $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$id}/preview", [
            'answers' => ['wage' => '22.75'],
        ])->assertOk()->json('data.content');

        $this->assertStringContainsString('22.75', $content);
        $this->assertStringNotContainsString('2100 0418', $content, 'A saved secret was printed in a preview.');
        $this->assertNull($this->rawAnswer($alpha, $id, 'wage'), 'A preview saved the answer.');
    }

    /**
     * The employer fills in and signs first; that emails the employee their
     * half, the employer's answers lock, and the employee finishes and signs
     * the finished document (Jose, 2026-10-01).
     */
    public function test_the_employer_signs_first_and_the_employee_is_sent_their_half(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        [$alpha, $employee, $id] = $this->scenario();

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50', 'iban' => self::IBAN],
        ])->assertOk();

        // The employee's question is still empty, and the employer can sign.
        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin')->assertCreated();
        \Illuminate\Support\Facades\Notification::assertSentTo($employee, \App\Notifications\PaperworkReadyToFinish::class);

        // What the employer signed is fixed; the employee's half is not.
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '99.00'],
        ])->assertStatus(422);
        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['emergency_contact' => 'Ana, 600 111 222'],
        ])->assertOk();

        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz')->assertCreated();
        $this->assertSame('completed', $this->form($employee, $id)['status']);

        // The employer's signature is over the document they saw; the
        // employee's over the finished one, which carries their answer.
        [$first, $final] = $this->inTenant($alpha['org'], fn () => [
            ContractSignature::where('field_key', 'employer_sign')->sole(),
            ContractSignature::where('field_key', 'employee_sign')->sole(),
        ]);
        $this->assertNotSame($first->content_hash, $final->content_hash);
        $finished = $this->as($employee)->getJson("/api/v1/hr/onboardings/{$id}/document")->assertOk()->json('data');
        $this->assertSame(2, $finished['revision']);
        $this->assertSame($final->content_hash, $finished['content_hash']);
    }

    /**
     * The last signature writes the contract's terms into the record (Jose,
     * 2026-09-30: "of course"): start date, pay, schedule and what was issued.
     */
    public function test_completing_fills_the_record_from_the_contract(): void
    {
        [$alpha, $employee, $id] = $this->scenario([
            ['key' => 'date_of_hire', 'label' => 'Date of hire', 'type' => 'date', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'pay_basis', 'label' => 'Pay basis', 'type' => 'choice', 'options' => ['Hourly', 'Salary'],
                'filled_by' => 'employer', 'required' => true],
            ['key' => 'hourly_rate', 'label' => 'Hourly rate', 'type' => 'number', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'work_days', 'label' => 'Work days', 'type' => 'text', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'work_hours', 'label' => 'Work hours', 'type' => 'text', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'tools', 'label' => 'Tools issued', 'type' => 'checklist', 'options' => ['Hammer', 'Flashlight'],
                'filled_by' => 'employer', 'required' => false],
            ['key' => 'employer_sign', 'label' => 'For the employer', 'type' => 'signature', 'filled_by' => 'employer', 'required' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
        ]);

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => [
            'date_of_hire' => '2026-10-05', 'pay_basis' => 'Hourly', 'hourly_rate' => '18.50',
            'work_days' => 'Monday - Friday', 'work_hours' => '8:00 AM - 5:00 PM',
            'tools' => [['item' => 'Hammer', 'quantity' => '1', 'received' => '2026-10-05']],
        ]])->assertOk();

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin');

        // Nothing moves while it is only half signed.
        $this->assertFalse($this->inTenant($alpha['org'], fn () => \App\Models\PayRate::where('user_id', $employee->id)->exists()));

        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz');

        $this->inTenant($alpha['org'], function () use ($employee) {
            $this->assertSame('2026-10-05', EmploymentEvent::where('user_id', $employee->id)
                ->where('kind', EmploymentEvent::STARTED)->orderBy('occurred_on')->first()->occurred_on->toDateString(),
                'The start date stayed the day they accepted.');

            $rate = \App\Models\PayRate::where('user_id', $employee->id)->sole();
            $this->assertSame(['hourly', '18.50', 'hour'], [$rate->basis, (string) $rate->amount, $rate->period]);

            $this->assertEquals(['start' => '08:00', 'end' => '17:00'], $employee->fresh()->work_schedule['days']['monday']);
            $this->assertSame('Hammer', \App\Models\HeldEquipment::where('user_id', $employee->id)->sole()->name);
        });
    }

    /**
     * A signature is against what the signer READ, or it is against nothing.
     *
     * The client sends back the fingerprint of the document it was handed. If the
     * document is not that document, the signature is refused — which is the only
     * thing making "fingerprint exactly what was shown" a fact rather than a
     * claim about the server's own intentions.
     */
    public function test_a_signature_is_refused_when_the_document_is_not_the_one_that_was_shown(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key'     => 'employer_sign',
            'affirmation'   => 'Alpha Admin',
            'consent'       => true,
            'drawing' => self::SIGNATURE_PNG,
            'document_hash' => DocumentFingerprint::of('a document nobody rendered'),
        ])->assertStatus(422)->assertJsonValidationErrors('document_hash');

        $this->assertSame('in_progress', $this->form($alpha['admin'], $id)['status']);
    }

    /** One side cannot sign in the other's place. Attribution is the whole point. */
    public function test_a_signature_belongs_to_one_side_only(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $hash = $this->documentHash($employee, $id);

        $this->as($employee)->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key'     => 'employer_sign',
            'affirmation'   => 'Marta Ruiz',
            'consent'       => true,
            'drawing' => self::SIGNATURE_PNG,
            'document_hash' => $hash,
        ])->assertStatus(422)->assertJsonValidationErrors('field_key');

        // And consent is not optional, because it is a separate agreement.
        $this->as($employee)->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key'     => 'employee_sign',
            'affirmation'   => 'Marta Ruiz',
            'consent'       => false,
            'document_hash' => $hash,
        ])->assertStatus(422)->assertJsonValidationErrors('consent');
    }

    /** A signed document that goes on changing is worth nothing. */
    public function test_answers_are_frozen_once_somebody_has_signed(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '25.00'],
        ])->assertStatus(422)->assertJsonValidationErrors('answers');

        $this->assertSame('18.50', $this->rawAnswer($alpha, $id, 'wage'));
    }

    // ── 6. amendment ─────────────────────────────────────────────

    /**
     * "Correct the field, re-render, keep both, record who changed what."
     *
     * The original stays because it is what was signed; the amendment is what is
     * true. Both are on the record and both are readable, which is the only
     * version of "keep both" worth anything.
     */
    public function test_an_amendment_keeps_both_documents_and_says_who_changed_what(): void
    {
        [$alpha, $employee, $id] = $this->completeForm(['iban' => 'ES00 0000 0000 0000 0000 0000']);

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin');
        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz');

        $signedHash = $this->inTenant($alpha['org'], fn () => ContractSignature::firstOrFail()->content_hash);

        $this->as($employee)->postJson("/api/v1/hr/onboardings/{$id}/amendments", [
            'answers' => ['iban' => self::IBAN],
            'reason'  => 'The first two digits of the account number were transposed.',
        ])->assertCreated()
            ->assertJsonPath('document.revision', 2)
            ->assertJsonPath('document.changed_keys', ['iban'])
            ->assertJsonPath('document.is_original', false);

        $documents = $this->as($employee)->getJson("/api/v1/hr/onboardings/{$id}")
            ->assertOk()->json('data.documents');

        $this->assertCount(2, $documents, 'The original was replaced rather than kept.');

        $original = $this->as($employee)
            ->getJson("/api/v1/hr/onboardings/{$id}/document?revision=1")->assertOk()->json('data');
        $amended = $this->as($employee)
            ->getJson("/api/v1/hr/onboardings/{$id}/document?revision=2")->assertOk()->json('data');

        // The original still says what was signed, and still verifies against the
        // signature taken over it.
        $this->assertStringContainsString('ES00 0000', $original['content']);
        $this->assertStringNotContainsString('2100 0418', $original['content']);
        $this->assertSame($signedHash, $original['content_hash']);
        $this->assertTrue($original['intact']);

        // The amendment is what is true.
        $this->assertStringContainsString('2100 0418', $amended['content']);
        $this->assertSame(
            'The first two digits of the account number were transposed.',
            $amended['reason'],
        );

        // Who changed what, on the record and in the audit trail.
        $this->assertSame($employee->name, $amended['created_by']);

        $entry = $this->inTenant(
            $alpha['org'],
            fn () => AuditEvent::where('action', 'onboarding.amended')->sole(),
        );

        $this->assertSame(['iban'], $entry->metadata['fields']);
        // The keys, never the values — the log is append-only and erasure cannot
        // reach into it.
        $this->assertStringNotContainsString(self::IBAN, (string) json_encode($entry->toArray()));
    }

    /**
     * The document is not a file somebody attached, so it cannot be detached.
     *
     * `OnboardingDocument` refuses its own deletion and that was not enough:
     * `onboarding_documents.attachment_id` cascades, so removing the ATTACHMENT
     * through the ordinary file endpoint took the row with it and left the
     * signature fingerprinting bytes nobody could produce. A file answer beside
     * it is still perfectly removable, which is how you can tell the guard is
     * about evidence rather than about onboardings.
     */
    public function test_the_rendered_document_cannot_be_removed_through_the_file_endpoint(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $answerFile = $this->as($employee)->postJson('/api/v1/attachments', [
            'attachable_type' => 'onboardings',
            'attachable_id'   => $id,
            'file'            => UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.4\n%%EOF\n"),
        ])->assertCreated()->json('data.id');

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin');

        // Revision 1 by number: until the employee signs, the plain document
        // endpoint shows the live form they are about to sign.
        $documentAttachment = $this->as($employee)
            ->getJson("/api/v1/hr/onboardings/{$id}/document?revision=1")->assertOk()->json('data.attachment_id');

        $this->as($alpha['admin'])->deleteJson("/api/v1/attachments/{$documentAttachment}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');

        // Still there, still verifying.
        $this->as($employee)->getJson("/api/v1/hr/onboardings/{$id}/document?revision=1")
            ->assertOk()
            ->assertJsonPath('data.intact', true);

        // An ordinary file answer is not protected — the guard is about the one
        // file that is a record rather than about everything on this subject.
        $this->as($alpha['admin'])->deleteJson("/api/v1/attachments/{$answerFile}")->assertOk();
    }

    /** An amendment with nothing in it would be a second document about nothing. */
    public function test_an_amendment_needs_a_reason_and_an_actual_change(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin');

        $this->as($employee)->postJson("/api/v1/hr/onboardings/{$id}/amendments", [
            'answers' => ['iban' => self::IBAN],
        ])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->as($employee)->postJson("/api/v1/hr/onboardings/{$id}/amendments", [
            'answers' => ['iban' => self::IBAN],
            'reason'  => 'No change at all, just checking.',
        ])->assertStatus(422)->assertJsonValidationErrors('answers');
    }

    /** Before anything is signed there is nothing to amend — just save. */
    public function test_amending_an_unsigned_onboarding_is_refused(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $this->as($employee)->postJson("/api/v1/hr/onboardings/{$id}/amendments", [
            'answers' => ['iban' => 'ES11 1111 1111 1111 1111 1111'],
            'reason'  => 'Correcting the account number before anybody signs.',
        ])->assertStatus(422)->assertJsonValidationErrors('answers');
    }

    // ── 7. the signature record refuses nonsense ─────────────────

    /**
     * The row the sceptic wrote, refused this time.
     *
     * `field_key: 'not_a_field_at_all'`, `signer_party: 'martian'`,
     * `content_hash: 'not-a-hash'`, empty statements — every one of them was
     * accepted, and because updates throw, every one of them was permanently
     * uncorrectable. Immutability without validation preserves nonsense.
     */
    public function test_a_nonsense_signature_row_is_impossible(): void
    {
        [$alpha, , $id] = $this->scenario();

        $version = $this->inTenant($alpha['org'], fn () => Onboarding::findOrFail($id)->contract_version_id);
        $subject = $alpha['admin']->getKey();

        $sound = [
            'contract_version_id' => $version,
            'field_key'           => 'employee_sign',
            'subject_user_id'     => $subject,
            'signer_user_id'      => $subject,
            'signer_label'        => 'Alpha Admin <alpha@example.test>',
            'signer_party'        => 'employee',
            'content_hash'        => DocumentFingerprint::of('x'),
            'hash_algorithm'      => DocumentFingerprint::ALGORITHM,
            'content_length'      => 1,
            'consent_statement'   => 'I agree to sign electronically.',
            'consented_at'        => now(),
            'intent_statement'    => 'I am signing this contract.',
            'affirmation'         => 'Alpha Admin',
            'signed_at'           => now(),
        ];

        $nonsense = [
            'a key the version never declared' => ['field_key' => 'not_a_field_at_all'],
            'a key that is not a signature'    => ['field_key' => 'wage'],
            'a party nobody can be'            => ['signer_party' => 'martian'],
            'the other side’s party'           => ['signer_party' => 'employer'],
            'a hash that is not one'           => ['content_hash' => 'not-a-hash'],
            'an algorithm nothing can verify'  => ['hash_algorithm' => 'crc32b'],
            'a document of no length'          => ['content_length' => 0],
            'no consent statement'             => ['consent_statement' => '   '],
            'no intent statement'              => ['intent_statement' => ''],
            'no affirmative act'               => ['affirmation' => ''],
            'nobody it is about'               => ['subject_user_id' => null],
            'nobody who signed it'             => ['signer_user_id' => null],
            /*
             * The case the OLD condition let through, and the reason this line
             * is not a duplicate of the one above it.
             *
             * The guard used to read `provider === PROVIDER_SELF &&
             * signer_user_id === null`. The row above never set `provider`, so
             * it defaulted to `ezbizi` and the old condition already caught it
             * — making the check look tested while the branch that mattered was
             * not. Any other provider was accepted naming nobody, with the
             * attribution supposedly living in `provider_evidence`, a column
             * that no longer exists and has no vendor to bring it back.
             */
            'a signer nobody, under some other provider' => [
                'provider'       => 'some-vendor',
                'signer_user_id' => null,
            ],
            'no name for the signer'           => ['signer_label' => ' '],
        ];

        foreach ($nonsense as $what => $broken) {
            $this->inTenant($alpha['org'], function () use ($sound, $broken, $what) {
                try {
                    ContractSignature::create([...$sound, ...$broken]);
                    $this->fail("A signature record with {$what} was accepted.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('Refusing to write a signature', $e->getMessage());
                }
            });
        }

        // And the sound one goes through, or the guard would be refusing
        // everything and proving nothing.
        $this->inTenant($alpha['org'], fn () => ContractSignature::create($sound));
        $this->assertSame(1, $this->inTenant($alpha['org'], fn () => ContractSignature::count()));
    }

    // ── 8. in_progress finally has a producer ────────────────────

    /**
     * The invitation status that was declared with nothing able to produce it.
     *
     * It is what this module is for: the employer has chosen a contract and the
     * form is open, which is neither "they opened the link" nor "they have an
     * account".
     */
    public function test_an_invitation_reports_in_progress_once_an_onboarding_starts(): void
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

        $this->assertSame('sent', $invitation->json('data.status'));

        $contractId = $this->aContract($alpha);

        $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitation->json('data.id'),
            'contract_id'   => $contractId,
        ])->assertCreated();

        $this->assertSame('in_progress', $this->invitationStatus($alpha));

        // Accepting still wins over it: what happened, happened.
        $this->accept($this->tokenFrom($invitation->json('data.accept_url')));

        $this->assertSame('completed', $this->invitationStatus($alpha));
    }

    // ── 9. who may do what ───────────────────────────────────────

    /**
     * The employee holds no HR permission and must still be able to fill in their
     * own form. Everybody else needs the permission AND the reach.
     */
    public function test_the_employee_needs_no_permission_and_a_stranger_needs_both(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        // Inside the tenant: a permission check reads the role, which is tenant
        // data like everything else.
        $this->assertFalse($this->inTenant(
            $alpha['org'],
            fn () => $employee->fresh()->hasPermission('hr.view'),
        ));
        $this->as($employee)->getJson("/api/v1/hr/onboardings/{$id}")->assertOk();

        // Somebody in the same organization with no HR permission at all.
        $stranger = $this->userWithRole($alpha, 'Office Staff', ['customers.view']);
        $this->as($stranger)->getJson("/api/v1/hr/onboardings/{$id}")->assertForbidden();

        // And their list is empty rather than refused, because their own
        // onboarding — if they had one — is the one thing they may see.
        $this->as($stranger)->getJson('/api/v1/hr/onboardings')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // The employee's own list has exactly theirs in it.
        $this->as($employee)->getJson('/api/v1/hr/onboardings')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id);
    }

    /** The list's search box (Jose, 2026-10-14): by the person's name or email. */
    public function test_the_list_searches_by_the_person(): void
    {
        [$alpha, , $id] = $this->scenario();

        foreach (['Ruiz', 'marta ruiz', 'newstarter@'] as $term) {
            $this->as($alpha['admin'])->getJson('/api/v1/hr/onboardings?search='.urlencode($term))
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        }
        $this->as($alpha['admin'])->getJson('/api/v1/hr/onboardings?search=Okafor')
            ->assertOk()->assertJsonPath('meta.total', 0);

        // Narrows what the reader may reach, never widens it (HR, 2026-10-14).
        $second = $this->inTenant($alpha['org'], fn () => \App\Models\Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Alpha F2',
        ]));
        $elsewhere = $this->userWithRole($alpha, 'Branch Manager', ['hr.view', 'hr.edit'], onlyAt: $second);
        $this->as($elsewhere)->getJson('/api/v1/hr/onboardings?search=Ruiz')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    /** §4 reach: a manager at another franchise is not looking at this. */
    public function test_an_onboarding_outside_a_managers_reach_is_out_of_bounds(): void
    {
        [$alpha, , $id] = $this->scenario();

        $second = $this->inTenant($alpha['org'], fn () => \App\Models\Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Alpha F2',
        ]));

        $elsewhere = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.edit'], onlyAt: $second,
        );

        $this->as($elsewhere)->getJson("/api/v1/hr/onboardings/{$id}")->assertForbidden();
        $this->as($elsewhere)->getJson('/api/v1/hr/onboardings')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    /**
     * And the refusal uses the organization's word for the level (§8).
     *
     * The sentence spliced Assignment's own scope type into itself —
     * "Somebody with authority over that franchise can" — which is the platform's
     * vocabulary rather than the tenant's. An organization that calls them
     * Territories was told about a franchise, a word nothing else on its screens
     * uses. Refusal::labelFor is what stops that happening once per policy.
     */
    public function test_the_reach_refusal_uses_the_organizations_own_word(): void
    {
        [$alpha, , $id] = $this->scenario();

        $alpha['org']->forceFill(['franchise_label' => 'Territory'])->save();

        $second = $this->inTenant($alpha['org'], fn () => \App\Models\Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Alpha F2',
        ]));

        $elsewhere = $this->userWithRole(
            $alpha, 'Branch Manager', ['hr.view', 'hr.edit'], onlyAt: $second,
        );

        $message = $this->as($elsewhere)
            ->patchJson("/api/v1/hr/onboardings/{$id}", ['answers' => ['wage' => '19.00']])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('authority over that territory', $message);
        $this->assertStringNotContainsString('franchise', $message);
    }

    /** A template nobody can sign would be a form nobody can finish. */
    public function test_an_onboarding_cannot_start_against_a_template_with_no_signature(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleFor($alpha);

        $invitationId = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => 'newstarter@example.test',
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ])->assertCreated()->json('data.id');

        $contractId = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name'   => 'Unsignable',
            'fields' => [[
                'key' => 'wage', 'label' => 'Hourly wage', 'type' => 'number',
                'filled_by' => 'employer', 'required' => true,
            ]],
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitationId,
            'contract_id'   => $contractId,
        ])->assertStatus(422)->assertJsonValidationErrors('contract_id');
    }

    /**
     * The invite can start the paperwork itself, and does not have to (Jose,
     * 2026-09-30: "yes but toggable, some people dont need contracts").
     */
    public function test_an_invitation_can_start_the_paperwork_and_a_bad_contract_refuses_both(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role  = $this->roleFor($alpha);
        $invite = fn (string $email, ?string $contract) => $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/invitations', [
                'email' => $email, 'role_id' => $role->id,
                'scope_type' => 'franchise', 'scope_id' => $alpha['franchise']->id,
                'contract_id' => $contract,
            ]);
        $paperworkFor = fn (string $invitationId) => $this->inTenant($alpha['org'],
            fn () => Onboarding::where('invitation_id', $invitationId)->first());

        $with = $invite('with@example.test', $this->aContract($alpha))->assertCreated()->json('data.id');
        $this->assertSame(Onboarding::IN_PROGRESS, $paperworkFor($with)?->status);

        // The control: no contract, no paperwork, and the invite still goes.
        $without = $invite('without@example.test', null)->assertCreated()->json('data.id');
        $this->assertNull($paperworkFor($without));

        // A contract nobody could sign refuses the invite too, so nobody is
        // emailed into paperwork that never started.
        $unsignable = $this->aContract($alpha, 'Unsignable', [[
            'key' => 'wage', 'label' => 'Hourly wage', 'type' => 'number',
            'filled_by' => 'employer', 'required' => true,
        ]]);
        $invite('refused@example.test', $unsignable)
            ->assertStatus(422)->assertJsonValidationErrors('contract_id');
        $this->assertFalse($this->inTenant($alpha['org'],
            fn () => \App\Models\Invitation::where('email', 'refused@example.test')->exists()));
    }

    /** One arrival, one contract. */
    public function test_an_invitation_carries_at_most_one_onboarding(): void
    {
        [$alpha, , $id] = $this->scenario();

        $invitationId = $this->inTenant($alpha['org'], fn () => Onboarding::findOrFail($id)->invitation_id);
        $contractId   = $this->aContract($alpha, 'Second template');

        $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitationId,
            'contract_id'   => $contractId,
        ])->assertStatus(422)->assertJsonValidationErrors('invitation_id');
    }

    public function test_an_onboarding_can_be_cancelled_and_started_again(): void
    {
        [$alpha, $employee, $id] = $this->scenario();

        $invitationId = $this->inTenant($alpha['org'], fn () => Onboarding::findOrFail($id)->invitation_id);
        $replacement  = $this->aContract($alpha, 'A different contract');

        $this->as($employee)->deleteJson("/api/v1/hr/onboardings/{$id}")
            ->assertForbidden();

        $this->as($alpha['admin'])->deleteJson("/api/v1/hr/onboardings/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50'],
        ])->assertStatus(422)->assertJsonValidationErrors('onboarding');

        $this->as($alpha['admin'])->deleteJson("/api/v1/hr/onboardings/{$id}")
            ->assertStatus(422);

        $next = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitationId,
            'contract_id'   => $replacement,
        ])->assertCreated()->json('data.id');

        $this->assertNotSame($id, $next);
        $this->assertSame('in_progress', $this->form($alpha['admin'], $next)['status']);
    }

    /** Whatever its state (Jose, 2026-10-05: "regardless of anything"); what was signed stays. */
    public function test_a_fully_signed_onboarding_can_still_be_cancelled(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Jose Silva');
        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz');

        $this->assertSame('completed', $this->form($alpha['admin'], $id)['status']);

        $this->as($alpha['admin'])->deleteJson("/api/v1/hr/onboardings/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertCount(2, $this->form($alpha['admin'], $id)['signatures']);
    }

    /**
     * With paperwork, the invitation is emailed only once the employer signs
     * (Jose, 2026-10-06: "make it so the onboarding gets sent only after the
     * employer signs"). Until then there is no link and nothing to resend.
     */
    public function test_an_invitation_with_paperwork_goes_out_when_the_employer_signs(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.username' => 'ezbizi', 'mail.from.address' => 'noreply@ezbizi.test']);
        \Illuminate\Support\Facades\Notification::fake();
        $alpha = $this->provisionTenant('Alpha');
        $contractId = $this->aContract($alpha);

        $created = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email' => 'held@example.test', 'first_name' => 'Hal', 'last_name' => 'Held',
            'role_id' => $this->roleFor($alpha)->id, 'contract_id' => $contractId,
            'scope_type' => 'franchise', 'scope_id' => $alpha['franchise']->id,
        ])->assertCreated()->assertJsonPath('data.held', true);
        $this->assertArrayNotHasKey('accept_url', $created->json('data'));
        \Illuminate\Support\Facades\Notification::assertNothingSent();
        $invitation = $created->json('data.id');
        $this->as($alpha['admin'])->postJson("/api/v1/hr/invitations/{$invitation}/resend")->assertStatus(422);

        $id = collect($this->as($alpha['admin'])->getJson('/api/v1/hr/onboardings')->json('data'))
            ->firstWhere('invitation_id', $invitation)['id'];
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50', 'iban' => self::IBAN],
        ])->assertOk();
        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Jose Silva')->assertCreated();

        \Illuminate\Support\Facades\Notification::assertSentOnDemand(\App\Notifications\InvitationLink::class);
        $this->assertFalse(collect($this->as($alpha['admin'])->getJson('/api/v1/hr/invitations')->json('data'))
            ->firstWhere('id', $invitation)['held']);
    }

    /**
     * The employer signs before the new starter has accepted (Jose,
     * 2026-10-05: "she should be able to sign it regardless"); the employee
     * signs after accepting, and the two signatures make one agreement.
     */
    public function test_the_employer_signs_before_the_invitation_is_accepted(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $contractId = $this->aContract($alpha);
        $invitation = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email' => 'later@example.test', 'first_name' => 'Lee', 'last_name' => 'Later',
            'role_id' => $this->roleFor($alpha)->id,
            'scope_type' => 'franchise', 'scope_id' => $alpha['franchise']->id,
        ])->assertCreated();
        $id = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitation->json('data.id'), 'contract_id' => $contractId,
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50', 'iban' => self::IBAN],
        ])->assertOk();
        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Jose Silva')->assertCreated();
        $this->assertCount(1, $this->form($alpha['admin'], $id)['signatures']);

        $this->accept($this->tokenFrom($invitation->json('data.accept_url')));
        $employee = $this->inTenant($alpha['org'], fn () => User::where('email', 'later@example.test')->firstOrFail());
        $this->assertCount(1, $this->form($employee, $id)['signatures'], 'The employer signature followed the onboarding.');

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['emergency_contact' => 'Ana, 600 111 222'],
        ])->assertOk();
        $this->signAs($employee, $id, 'employee_sign', 'Lee Later')->assertCreated();

        $this->assertSame('completed', $this->form($alpha['admin'], $id)['status']);
    }

    /**
     * The final approval (Jose, 2026-10-05): with the switch on, the two
     * signatures in either order leave it waiting for the sender, and the new
     * starter holds no permission until the sender approves.
     */
    public function test_with_approval_on_the_signed_paperwork_waits_for_the_sender(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();
        $before = $this->as($employee)->getJson('/api/v1/me')->assertOk()->json('data.permissions');
        $this->assertNotSame([], $before, 'The control: the starter holds something before the switch.');
        $this->inTenant($alpha['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)
            ->set(Onboarding::APPROVAL_SETTING, $alpha['org'], true));
        $me = fn () => $this->as($employee)->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertSame([], $me()['permissions']);
        $this->assertTrue($me()['onboarding']['held']);

        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz')->assertCreated();
        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Jose Silva')->assertCreated();

        $form = $this->form($alpha['admin'], $id);
        $this->assertSame('awaiting_approval', $form['status']);
        $this->assertTrue($form['approval']['mine']);
        $this->assertSame('awaiting_approval', $me()['onboarding']['status']);
        $this->assertTrue($me()['onboarding']['held']);

        $this->as($employee)->postJson("/api/v1/hr/onboardings/{$id}/approve")->assertForbidden();
        $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertNull($me()['onboarding']);
        $this->assertSame($before, $me()['permissions']);
        $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$id}/approve")->assertStatus(422);
    }

    /** Off, which is the default, both signatures complete it as before. */
    public function test_with_approval_off_both_signatures_complete_it(): void
    {
        [$alpha, $employee, $id] = $this->completeForm();

        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz')->assertCreated();
        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Jose Silva')->assertCreated();

        $this->assertSame('completed', $this->form($alpha['admin'], $id)['status']);
        $this->assertFalse($this->as($employee)->getJson('/api/v1/me')->json('data.onboarding.held') ?? false);
    }

    /**
     * Revoked after it was accepted (Jose, 2026-10-05: "she should be able to
     * still revoke it. regardless of anything"): the paperwork is cancelled
     * and the account it opened is switched off. A rule still may not.
     */
    public function test_an_accepted_invitation_can_still_be_revoked_by_a_person(): void
    {
        [$alpha, $employee, $id] = $this->scenario();
        $invitationId = $this->form($alpha['admin'], $id)['invitation_id'];

        $this->expectException(\App\Support\Hr\CannotActOnInvitation::class);
        try {
            $this->inTenant($alpha['org'], fn () => app(\App\Support\Hr\InvitationActions::class)
                ->revoke(Invitation::findOrFail($invitationId)));
        } finally {
            $this->assertTrue((bool) $this->inTenant($alpha['org'], fn () => $employee->fresh()->is_active));

            $this->as($alpha['admin'])->deleteJson("/api/v1/hr/invitations/{$invitationId}")
                ->assertOk()
                ->assertJsonPath('data.status', 'revoked');

            $this->assertSame('cancelled', $this->form($alpha['admin'], $id)['status']);
            $this->assertFalse((bool) $this->inTenant($alpha['org'], fn () => $employee->fresh()->is_active));
        }
    }

    /**
     * A company address typed on the form replaces the company's on the
     * contract (Jose, 2026-10-05: "if it's typed it overrides whatever is in
     * place"); empty, the company's own comes back. Only the employer types it.
     */
    public function test_a_typed_company_address_replaces_the_one_on_the_contract(): void
    {
        [$alpha, $employee, $first] = $this->scenario();
        $contract = $this->as($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => 'Office', 'fields' => $this->defaultFields(),
            'body' => "Company Address: {{organization.address}}\n\nWage: {{wage}}",
        ])->assertCreated()->json('data.id');
        $invitation = $this->form($alpha['admin'], $first)['invitation_id'];
        $this->as($alpha['admin'])->deleteJson("/api/v1/hr/onboardings/{$first}")->assertOk();
        $id = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitation, 'contract_id' => $contract,
        ])->assertCreated()->json('data.id');
        $preview = fn (array $answers) => $this->as($alpha['admin'])
            ->postJson("/api/v1/hr/onboardings/{$id}/preview", ['answers' => $answers])
            ->assertOk()->json('data.content');
        $typed = '1 Typed Street, Miami FL 33101';

        $this->assertStringContainsString($typed, $preview(['employer_address' => $typed]));
        $this->assertStringNotContainsString($typed, $preview([]));

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['employer_address' => $typed],
        ])->assertStatus(422);

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['employer_address' => $typed],
        ])->assertOk()
            ->assertJsonPath('data.employer_address.typed', $typed)
            ->assertJsonPath('data.employer_address.editable', true);
        $this->assertStringContainsString($typed, $preview([]));

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['employer_address' => ''],
        ])->assertOk()->assertJsonPath('data.employer_address.typed', null);
        $this->assertStringNotContainsString($typed, $preview([]));
    }

    /**
     * The version is pinned when the form begins.
     *
     * Publishing afterwards must not change a form somebody is filling in — a
     * question that changes after it was answered is a record that misdescribes
     * itself.
     */
    public function test_publishing_a_new_version_does_not_change_a_form_in_flight(): void
    {
        [$alpha, $employee, $id, $contractId] = $this->scenario();

        $pinned = $this->inTenant($alpha['org'], fn () => Onboarding::findOrFail($id)->contract_version_id);

        $this->as($alpha['admin'])->postJson("/api/v1/hr/contracts/{$contractId}/versions", [
            'fields' => [
                ...$this->defaultFields(),
                ['key' => 'locker', 'label' => 'Locker number', 'type' => 'text',
                    'filled_by' => 'employer', 'required' => false],
            ],
            'notes' => 'Added the locker.',
        ])->assertCreated();

        $form = $this->form($alpha['admin'], $id);

        $this->assertSame(
            $pinned,
            $this->inTenant($alpha['org'], fn () => Onboarding::findOrFail($id)->contract_version_id),
        );
        $this->assertNull(
            $this->fieldIn($form, 'locker'),
            'A field published after the form started appeared on it.',
        );

        // And an answer to it is refused, rather than stored against a key the
        // pinned version has never heard of.
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['locker' => '14'],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.locker');
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * A tenant, a contract, an invitation accepted into an account, and an
     * onboarding under way.
     *
     * @return array{0: array, 1: User, 2: string, 3: string}
     */
    /** The list's tiles count every status across all pages. */
    public function test_the_list_counts_each_status_for_its_tiles(): void
    {
        [$alpha, , $id] = $this->scenario();

        $this->as($alpha['admin'])->getJson('/api/v1/hr/onboardings?status=completed')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.tiles', ['in_progress' => 1, 'submitted' => 0, 'awaiting_approval' => 0, 'completed' => 0, 'cancelled' => 0]);
    }

    private function scenario(?array $fields = null): array
    {
        $alpha      = $this->provisionTenant('Alpha');
        $contractId = $this->aContract($alpha, 'Driver', $fields);
        $role       = $this->roleFor($alpha);

        $invitation = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => 'newstarter@example.test',
            'first_name' => 'Marta',
            'last_name'  => 'Ruiz',
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ])->assertCreated();

        // Phone and date of birth are collected at acceptance, which is where
        // they have a home — the profile. The common core is complete from here
        // without anybody typing it into the contract form.
        $this->accept($this->tokenFrom($invitation->json('data.accept_url')));

        $employee = $this->inTenant(
            $alpha['org'],
            fn () => User::where('email', 'newstarter@example.test')->firstOrFail(),
        );

        $id = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitation->json('data.id'),
            'contract_id'   => $contractId,
        ])->assertCreated()->json('data.id');

        return [$alpha, $employee, $id, $contractId];
    }

    /** The same, with every required question answered and nothing signed. */
    private function completeForm(array $overrides = []): array
    {
        [$alpha, $employee, $id, $contractId] = $this->scenario();

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50', 'iban' => $overrides['iban'] ?? self::IBAN],
        ])->assertOk();

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['emergency_contact' => 'Ana, 600 111 222'],
        ])->assertOk();

        $this->assertSame([], $this->form($alpha['admin'], $id)['missing']);

        return [$alpha, $employee, $id, $contractId];
    }

    private function defaultFields(): array
    {
        return [
            ['key' => 'wage', 'label' => 'Hourly wage', 'type' => 'number',
                'filled_by' => 'employer', 'required' => true],
            // The field the whole sensitive mechanism exists for.
            ['key' => 'iban', 'label' => 'Bank account', 'type' => 'text',
                'filled_by' => 'either', 'required' => true, 'sensitive' => true],
            ['key' => 'emergency_contact', 'label' => 'Emergency contact', 'type' => 'text',
                'filled_by' => 'employee', 'required' => true],
            ['key' => 'licence', 'label' => 'Driving licence', 'type' => 'file',
                'filled_by' => 'employee', 'required' => false],
            ['key' => 'employer_sign', 'label' => 'For the employer', 'type' => 'signature',
                'filled_by' => 'employer', 'required' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature',
                'filled_by' => 'employee', 'required' => true],
        ];
    }

    private function aContract(array $tenant, string $name = 'Driver', ?array $fields = null): string
    {
        return $this->signedInAs($tenant['admin'])->postJson('/api/v1/hr/contracts', [
            'name'   => $name,
            'fields' => $fields ?? $this->defaultFields(),
        ])->assertCreated()->json('data.id');
    }

    private function aJob(array $tenant): string
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = \App\Models\Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number'              => 'C-'.bin2hex(random_bytes(3)),
                'name'                => 'A Customer',
            ]);

            return \App\Models\Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id'  => $customer->id,
                'number'       => 'J-'.bin2hex(random_bytes(3)),
                'title'        => 'A job',
                'status'       => 'scheduled',
            ])->getKey();
        });
    }

    private function roleFor(array $tenant): Role
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name'            => 'Technician',
                'is_system'       => false,
            ]);

            // Deliberately nothing from HR: the new starter must be able to fill
            // in their own form without any of it.
            $role->syncPermissionKeys(['customers.view']);

            return $role;
        });
    }

    /**
     * Sign, having first read the document — which is what a real client does and
     * what the endpoint requires.
     */
    private function signAs(User $actor, string $id, string $field, string $name): TestResponse
    {
        return $this->as($actor)->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key'     => $field,
            'affirmation'   => $name,
            'consent'       => true,
            'drawing' => self::SIGNATURE_PNG,
            'document_hash' => $this->documentHash($actor, $id),
        ])->assertCreated();
    }

    private function documentHash(User $actor, string $id): string
    {
        return $this->as($actor)
            ->getJson("/api/v1/hr/onboardings/{$id}/document")
            ->assertOk()
            ->json('data.content_hash');
    }

    private function form(User $actor, string $id): array
    {
        return $this->as($actor)->getJson("/api/v1/hr/onboardings/{$id}")->assertOk()->json('data');
    }

    private function fieldIn(array $form, string $key): ?array
    {
        foreach ($form['fields'] as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /** The stored answer, decrypted by the application — never off the wire. */
    private function rawAnswer(array $tenant, string $onboardingId, string $key): ?string
    {
        return $this->inTenant($tenant['org'], fn () => OnboardingAnswer::query()
            ->where('onboarding_id', $onboardingId)
            ->where('field_key', $key)
            ->first()?->value);
    }

    private function revealEvents(array $tenant)
    {
        return $this->inTenant(
            $tenant['org'],
            fn () => AuditEvent::where('action', 'sensitive_value.revealed')->get(),
        );
    }

    private function invitationStatus(array $tenant): string
    {
        return $this->inTenant($tenant['org'], fn () => Invitation::firstOrFail()->fresh()->status());
    }

    /**
     * Sign in, having first dropped the scoped container.
     *
     * Laravel does not reset scoped instances between requests made inside one
     * test, and ScopeReach memoises reach per actor for its lifetime. A test that
     * switches between the employer and the employee without this is asking two
     * questions of one cached answer.
     */
    private function as(User $actor): static
    {
        $this->app->forgetScopedInstances();

        return $this->signedInAs($actor);
    }

    private function accept(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        $this->postJson('/api/v1/invitations/accept', [
            'token'         => $token,
            'password'      => 'a-real-password',
            'phone'         => '+34 600 000 000',
            'address'       => '1 Test Street',
            'date_of_birth' => '1990-04-03',
        ])->assertCreated();
    }

    private function tokenFrom(?string $url): string
    {
        $this->assertIsString($url, 'The invitation response carried no accept_url.');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['token'];
    }
}
