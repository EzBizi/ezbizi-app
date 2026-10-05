<?php

namespace Tests\Feature\Api;

use App\Models\ContractVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Three changes to what a contract document IS, from the owner looking at the
 * live preview.
 *
 *   1. *"a contract should not say version 4 or anyone, those 2 fields should
 *      not show in the contract"* — the version line and the template's
 *      description come out of the rendered document.
 *   2. *"we also need the ability to create a cover for the contract as well,
 *      which means being able to use either the company logo that's already
 *      been uploaded or a new file altogether."*
 *   3. *"remove gender from the always asked questions, and make middle name
 *      optional, as some people dont have a middle name."*
 *
 * ── What is actually at stake ────────────────────────────────────
 *
 * Not "does the string come out right", in any of the three.
 *
 *   the header    every rendered document changes shape, so the thing to prove
 *                 is that the removal is a removal and not a blanking: the
 *                 document still identifies itself, and the version is still
 *                 recorded where it is EVIDENCE
 *   the cover     is the first page of a document somebody signs. The whole
 *                 test is that it cannot change afterwards — a cover pinned to
 *                 a mutable logo would rewrite the front page of contracts
 *                 signed years earlier, silently, with nothing failing
 *   the core      a question withdrawn must not take the answers already given
 *                 with it, and an optional question must not read as a required
 *                 one on the wire
 */
class HrContractCoverAndCoreTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Covers and rendered documents are both written through FileStore,
        // which reads `filesystems.default` — `local` under test.
        Storage::fake('local');
    }

    // ── 1. the header ────────────────────────────────────────────

    /**
     * The version line and the description are gone from the document.
     *
     * Asserted on the SIGNED document rather than on the preview, because the
     * preview is the thing the owner was looking at and the document is the
     * thing that matters — and they go through one assembler, so proving it
     * here proves it for both.
     *
     * ── The controls are the point of this test ──────────────────
     *
     * "The document does not contain 'Version 1'" passes perfectly on an empty
     * string, on a 404 body, and on a renderer that threw. So every negative
     * below is paired with a positive: the business is still named, the
     * agreement is still named, the wording is still there. Without those, a
     * renderer that returned nothing would look like a fix.
     */
    public function test_the_document_does_not_print_the_template_version_or_its_description(): void
    {
        [$alpha, , $onboarding] = $this->scenario(description: 'Anyone');

        $document = $this->documentFor($alpha['admin'], $onboarding);

        // The two lines the owner named.
        $this->assertStringNotContainsString('Version 1', $document);
        $this->assertStringNotContainsString('class="version"', $document);
        $this->assertStringNotContainsString('Anyone', $document);
        $this->assertStringNotContainsString('class="description"', $document);

        // CONTROLS. The document is still a document.
        $this->assertStringContainsString('Alpha', $document, 'The business is no longer named.');
        $this->assertStringContainsString('Driver', $document, 'The agreement is no longer named.');
        $this->assertStringContainsString('<header>', $document, 'The header itself has gone.');
        $this->assertStringContainsString('per hour', $document, 'The wording has gone.');
    }

    /**
     * The description is metadata for whoever PICKS the template, and it is
     * still exactly where it was.
     *
     * The pair to the test above, and it is what makes that one a move rather
     * than a deletion. Somebody choosing between eleven templates reads these
     * sentences; the person signing does not.
     */
    public function test_the_description_is_still_on_the_list_that_somebody_picks_a_template_from(): void
    {
        [$alpha, , , $contract] = $this->scenario(description: 'Anyone');

        $list = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/hr/contracts')->assertOk()->json('data');

        $this->assertSame('Anyone', $list[0]['description']);

        $this->assertSame('Anyone', $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/contracts/{$contract}")->assertOk()->json('data.description'));
    }

    /**
     * Nothing is lost evidentially — which is the reason the lines could come
     * out at all, and is worth holding rather than asserting in a docblock.
     *
     * The version a record was produced under is still on the onboarding, and
     * still on every signature taken against it. A future change that "restored"
     * the version to the document for evidential reasons would be answering a
     * problem this test says does not exist.
     */
    public function test_the_version_is_still_recorded_where_it_is_evidence(): void
    {
        [$alpha, , $onboarding] = $this->scenario();

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $this->signAsEmployer($alpha['admin'], $onboarding);

        $record = $this->as($alpha['admin'])
            ->getJson("/api/v1/hr/onboardings/{$onboarding}")->assertOk()->json('data');

        // The record still names the version that produced it…
        $this->assertSame(1, $record['contract']['version']);

        // …and the signature still fingerprints the exact document.
        $this->assertNotEmpty(
            $record['signatures'][0]['content_hash'],
            'A signature no longer fingerprints anything.',
        );
    }

    // ── 2. the cover ─────────────────────────────────────────────

    /**
     * No cover is the ordinary contract, and it stays that way.
     *
     * The control for every cover test below: without it, "the cover is on the
     * document" would pass just as well on a build that put one on everything.
     */
    public function test_a_contract_has_no_cover_unless_somebody_chooses_one(): void
    {
        [$alpha, , $onboarding, $contract] = $this->scenario();

        $document = $this->documentFor($alpha['admin'], $onboarding);

        $this->assertStringNotContainsString('class="cover"', $document);
        $this->assertStringNotContainsString('data:image/png;base64,', $document);

        $this->assertFalse($this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/contracts/{$contract}")->assertOk()->json('data.has_cover'));

        $this->assertNull($this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/contracts/{$contract}")->assertOk()->json('data.cover'));
    }

    /**
     * ═══════════════════════════════════════════════════════════════
     *  THE COVER BELONGS TO THE VERSION. THIS IS THAT SENTENCE AS A
     *  TEST, AND IT IS THE ONE THAT MATTERS.
     *
     *  A contract's document is pinned when it is published. A cover
     *  that could change afterwards would mean a signed document whose
     *  first page is not the one that was signed — and it would happen
     *  with nothing failing, on the ordinary act of a business
     *  replacing its logo.
     *
     *  So: publish with the business's own mark, render, REPLACE THE
     *  MARK, render again, and the document must be unchanged.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_replacing_the_business_logo_does_not_change_a_published_contracts_cover(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->uploadLogo($alpha, 512, 512);

        [$contract, $onboarding] = $this->contractWithCover($alpha, ['source' => 'logo']);

        $before = $this->coverDataUriIn($this->documentFor($alpha['admin'], $onboarding));

        // The business rebrands. An ordinary act, through the ordinary endpoint.
        $this->uploadLogo($alpha, 1024, 256);

        $after = $this->coverDataUriIn($this->documentFor($alpha['admin'], $onboarding));

        $this->assertSame($before, $after,
            'Replacing the logo changed the front page of a contract that was already published.');

        /*
         * CONTROL, and this test is worthless without it.
         *
         * `assertSame($before, $after)` passes on a renderer that emits the
         * same wrong bytes twice — including one that renders no cover at all,
         * since `coverDataUriIn` would then fail, or one that pins a blank. So
         * the document's cover must be the OLD mark and must NOT be the new
         * one, which is what actually distinguishes "pinned" from "broken".
         */
        $this->assertNotSame(
            base64_encode($this->currentLogoBytes($alpha)),
            $after,
            'The document is showing the logo as it is TODAY, so nothing was pinned.',
        );
    }

    /**
     * The business's mark is not uploaded a second time.
     *
     * The half of the owner's sentence that is easy to lose: *"either the
     * company logo that's already been uploaded or a new file altogether"*. A
     * `logo` cover carries no file at all — it names the mark already on the
     * business — and what lands on the document is that mark's actual bytes.
     */
    public function test_the_logo_already_uploaded_becomes_the_cover_without_uploading_it_again(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->uploadLogo($alpha, 512, 512);

        [, $onboarding] = $this->contractWithCover($alpha, ['source' => 'logo']);

        $document = $this->documentFor($alpha['admin'], $onboarding);

        $this->assertStringContainsString('class="cover"', $document);
        $this->assertSame(
            base64_encode($this->currentLogoBytes($alpha)),
            $this->coverDataUriIn($document),
            'The cover is not the mark that was uploaded for this business.',
        );
    }

    /**
     * And a file of your own, which is the other half.
     *
     * Uploaded through the staging endpoint — not bound to a contract, because
     * a contract being created has no id — and named by the publish.
     */
    public function test_a_file_uploaded_for_this_contract_becomes_the_cover(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $upload = $this->uploadCover($alpha, 800, 1100);

        $this->assertSame(
            "/hr/contracts/covers/{$upload['upload_id']}",
            $upload['image_path'],
            'The server did not say where the upload can be read.',
        );

        [$contract, $onboarding] = $this->contractWithCover($alpha, [
            'source' => 'file', 'upload_id' => $upload['upload_id'],
        ]);

        $this->assertStringContainsString(
            'class="cover"',
            $this->documentFor($alpha['admin'], $onboarding),
        );

        $cover = $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/contracts/{$contract}")->assertOk()->json('data.cover');

        $this->assertSame('file', $cover['source']);
        $this->assertSame($upload['upload_id'], $cover['upload_id']);
        $this->assertSame('cover.png', $cover['filename']);

        // And the bytes are readable back, which is what the designer's
        // thumbnail is pointed at.
        $this->signedInAs($alpha['admin'])->get('/api/v1'.$cover['image_path'])
            ->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    /**
     * Changing the cover is a NEW VERSION, not an edit — exactly like changing
     * the wording.
     *
     * Both directions. The renaming endpoint refuses a cover outright with the
     * reason rather than ignoring it, and the publish endpoint accepts one and
     * mints a version. A cover that could be swapped through PATCH would be a
     * signed document with a different front page and no version saying so.
     */
    public function test_the_cover_cannot_be_changed_without_publishing_a_version(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->uploadLogo($alpha, 512, 512);

        [$contract] = $this->contractWithCover($alpha, ['source' => 'logo'], onboard: false);

        $refusal = $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/contracts/{$contract}", [
                'name'  => 'Driver (2026)',
                'cover' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cover')
            ->json('errors.cover.0');

        // R8: a sentence saying what to do, never a key or a class name.
        $this->assertStringContainsString('publishes a new version', $refusal);
        $this->assertStringNotContainsString('cover_source', $refusal);

        // The rename on its own still works — the refusal is about the cover.
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/contracts/{$contract}", ['name' => 'Driver (2026)'])
            ->assertOk();

        $this->assertSame(1, $this->versionCount($alpha, $contract));
    }

    /**
     * A publish that changes ONLY the cover is a real publish.
     *
     * The no-op guard refuses a version that changes nothing, and it compared
     * the field list and the wording. Without the cover in that comparison, the
     * one act this feature exists for — swapping the front page — would be
     * refused as "nothing to publish", which is the most confusing possible
     * answer to somebody who has just chosen an image.
     */
    public function test_publishing_a_new_cover_and_nothing_else_is_not_refused_as_a_no_op(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $upload = $this->uploadCover($alpha, 800, 1100);

        [$contract] = $this->contractWithCover($alpha, null, onboard: false);

        $this->assertSame(1, $this->versionCount($alpha, $contract));

        $published = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => self::BODY,
                'cover'  => ['source' => 'file', 'upload_id' => $upload['upload_id']],
            ])
            ->assertCreated();

        $this->assertSame(2, $this->versionCount($alpha, $contract));
        $this->assertTrue($published->json('data.cover.changed'));
        $this->assertNull($published->json('data.cover.from'));
        $this->assertSame('file', $published->json('data.cover.to'));

        // CONTROL: the same publish AGAIN, with the same cover, is the no-op
        // the guard is for. Without this, the assertion above would pass on a
        // build that had simply deleted the guard.
        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => self::BODY,
                'cover'  => ['source' => 'file', 'upload_id' => $upload['upload_id']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields');

        $this->assertSame(2, $this->versionCount($alpha, $contract));
    }

    /**
     * An old version keeps its own cover when a new one is published.
     *
     * The version history is what answers "what did version 1 look like", and a
     * cover stored anywhere else would answer it with "whatever it is today".
     */
    public function test_each_version_keeps_the_cover_it_was_published_with(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->uploadLogo($alpha, 512, 512);

        [$contract] = $this->contractWithCover($alpha, ['source' => 'logo'], onboard: false);

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => self::BODY,
                'cover'  => null,
            ])
            ->assertCreated();

        $versions = collect($this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/contracts/{$contract}/versions")->assertOk()->json('data'))
            ->keyBy('version');

        $this->assertTrue($versions[1]['has_cover'], 'Version 1 lost the cover it was published with.');
        $this->assertSame('logo', $versions[1]['cover']['source']);
        $this->assertFalse($versions[2]['has_cover']);
        $this->assertNull($versions[2]['cover']);
    }

    /** Another organization's staged cover is not found, never forbidden. */
    public function test_a_cover_uploaded_by_another_organization_cannot_be_used(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta', 'admin@beta.test');

        $theirs = $this->uploadCover($beta, 800, 1100);

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name'   => 'Driver',
            'fields' => $this->fields(),
            'cover'  => ['source' => 'file', 'upload_id' => $theirs['upload_id']],
        ])->assertStatus(422)->assertJsonValidationErrors('cover.upload_id');

        // And the bytes are out of reach too, with the same answer.
        $this->signedInAs($alpha['admin'])
            ->get("/api/v1/hr/contracts/covers/{$theirs['upload_id']}")
            ->assertNotFound();
    }

    /**
     * PNG only, which is Branding's rule and is inherited on purpose — and the
     * SHAPE rule, which is not.
     *
     * A logo is cropped to one of two exact sizes so a row of marks lines up. A
     * cover is a page, and a page cropped to a 4:1 lockup is not a cover. So an
     * 800x1100 portrait is accepted where the branding endpoint would refuse it.
     */
    public function test_a_cover_must_be_a_png_but_need_not_be_a_logos_shape(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->post('/api/v1/hr/contracts/covers', [
            'cover' => UploadedFile::fake()->image('cover.jpg', 800, 1100),
        ])->assertStatus(422)->assertJsonValidationErrors('cover');

        // CONTROL: the same dimensions as a PNG go through, which is what says
        // the refusal above was about the FORMAT and not about the size.
        $this->signedInAs($alpha['admin'])->post('/api/v1/hr/contracts/covers', [
            'cover' => UploadedFile::fake()->image('cover.png', 800, 1100),
        ])->assertCreated();

        // And a shape the branding endpoint refuses outright.
        $this->signedInAs($alpha['admin'])->post('/api/v1/hr/contracts/covers', [
            'cover' => UploadedFile::fake()->image('cover.png', 1337, 907),
        ])->assertCreated();
    }

    /** A business with no logo is told so, rather than publishing a blank page. */
    public function test_choosing_the_business_logo_when_there_is_none_is_refused_with_the_reason(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $refusal = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name'   => 'Driver',
            'fields' => $this->fields(),
            'cover'  => ['source' => 'logo'],
        ])->assertStatus(422)->assertJsonValidationErrors('cover.source')
            ->json('errors')['cover.source'][0];

        $this->assertStringContainsString('no logo uploaded', $refusal);
    }

    /**
     * The cover reaches the PDF copy — the page break AND the artwork.
     *
     * This was a pinned gap for one round: PdfRenderer refused every scheme
     * outright, so the `data:` URI carrying the mark was refused alongside
     * `file://` and `http://`, and a copy opened on a cover page with nothing
     * on it. The tripwire that stood here failed the moment the rule was
     * narrowed, which is what it was for.
     *
     * `data:image/png;base64,` is now the one permitted prefix. That is not a
     * hole in the argument the other rules make: every other scheme is refused
     * because it sends the converter SOMEWHERE on the strength of customer
     * text, and a `data:` URI goes nowhere — the bytes are already inside the
     * document. `data:text/html` and `data:image/svg+xml` stay refused, which
     * is why the prefix is matched rather than the scheme.
     */
    public function test_the_pdf_copy_carries_the_cover_page_and_its_image(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->uploadLogo($alpha, 512, 512);

        [, $onboarding] = $this->contractWithCover($alpha, ['source' => 'logo']);

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $this->signAsEmployer($alpha['admin'], $onboarding);

        $pdf = (string) $this->as($alpha['admin'])
            ->get("/api/v1/hr/onboardings/{$onboarding}/document/pdf")
            ->assertOk()
            ->getContent();

        preg_match_all('#/Type\s*/Page[^s]#', $pdf, $pages);

        $this->assertGreaterThanOrEqual(2, count($pages[0]),
            'The cover page break is not reaching the PDF copy either, which is a bigger '
            .'problem than the missing artwork.');

        $this->assertStringContainsString('/Subtype /Image', $pdf,
            'The cover artwork is missing from the PDF copy, so a copy opens on a cover page '
            .'with nothing on it. Check PdfRenderer’s data:// rule — it is the one scheme '
            .'permitted, and only for the exact prefix data:image/png;base64,.');
    }

    // ── 3. the common core ───────────────────────────────────────

    /**
     * Reserved and asked are two lists, and the catalogue finally says which is
     * which.
     *
     * Fact B: eight keys are refused to a template, six are asked of a person,
     * and nothing on the wire distinguished them — so the designer drew the
     * refusal list under "Always asked" and showed two questions nobody is ever
     * asked.
     */
    public function test_the_catalogue_distinguishes_a_reserved_key_from_an_asked_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $data = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/hr/contracts/catalogue')->assertOk()->json('data');

        $reserved = collect($data['reserved_keys'])->keyBy('key');
        $core     = array_column($data['core'], 'key');

        // Still refused to a template — every one of the eight.
        $this->assertSame([
            'email', 'name', 'first_name', 'middle_name', 'last_name',
            'phone', 'address', 'date_of_birth', 'gender',
        ], $reserved->keys()->all());

        // Gender is reserved without being asked; address is asked.
        $this->assertSame(
            ['email', 'first_name', 'middle_name', 'last_name', 'phone', 'address', 'date_of_birth'],
            $core,
        );

        $this->assertFalse($reserved['gender']['asked'], 'Gender is still published as asked.');
        $this->assertFalse($reserved['name']['asked']);
        $this->assertTrue($reserved['email']['asked']);

        // The flag the designer needs to stop drawing an optional box as a
        // required one — which is the complaint this came from.
        $this->assertTrue($reserved['middle_name']['asked']);
        $this->assertFalse($reserved['middle_name']['required'],
            'Middle name is published as required, which is what made the form demand one.');
        $this->assertTrue($reserved['last_name']['required']);

        // `core` is FILTERED from `reserved_keys`, not built beside it — two
        // lists that overlap are two lists that drift.
        foreach ($data['core'] as $entry) {
            $this->assertSame($reserved[$entry['key']], $entry,
                "The two lists disagree about {$entry['key']}.");
        }
    }

    /**
     * Onboarding stops asking for a gender and starts asking, optionally, for a
     * middle name.
     *
     * On the FORM the two sides actually fill in, because that is where the
     * owner saw it. A key's presence in the form is what "asked" means.
     */
    public function test_the_onboarding_form_drops_gender_and_offers_an_optional_middle_name(): void
    {
        [$alpha, , $onboarding] = $this->scenario();

        $form = collect($this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/onboardings/{$onboarding}")->assertOk()->json('data.fields'))
            ->keyBy('key');

        // The form is a form. Without this, every assertion below passes on an
        // empty list — which is exactly what a wrong payload key produces.
        $this->assertGreaterThan(5, $form->count(), 'The form came back empty.');

        $this->assertFalse($form->has('gender'), 'Onboarding still asks for a gender.');

        $this->assertTrue($form->has('middle_name'), 'Onboarding does not ask for a middle name.');
        $this->assertFalse($form['middle_name']['required']);
        $this->assertSame('Middle name', $form['middle_name']['label']);

        // CONTROLS: the rest of the core is still asked, and still required —
        // without these, "gender is absent" would pass on an empty form.
        $this->assertTrue($form->has('first_name'));
        $this->assertTrue($form['last_name']['required']);
        $this->assertTrue($form['date_of_birth']['required']);
    }

    /**
     * Some people do not have a middle name, and the form has to be finishable
     * by them.
     *
     * The owner's whole sentence. A required box would be a form nobody without
     * one can complete honestly — and completeness is checked at the first
     * SIGNATURE, which is where the document is rendered and frozen, so that is
     * where this has to be proven.
     */
    public function test_somebody_with_no_middle_name_can_still_finish_the_form(): void
    {
        [$alpha, , $onboarding] = $this->scenario();

        $outstanding = fn (): array => array_keys($this->as($alpha['admin'])
            ->getJson("/api/v1/hr/onboardings/{$onboarding}")->assertOk()->json('data.missing'));

        /*
         * CONTROL FIRST, because it is what gives the rest of this test
         * meaning: `missing` is genuinely reporting something, so
         * "middle_name is not in it" is a fact about middle_name rather than
         * about a completeness check that has stopped running.
         */
        $this->assertContains('wage', $outstanding());
        $this->assertNotContains('middle_name', $outstanding(),
            'A missing middle name is being counted against somebody who has none.');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $this->assertSame([], $outstanding());

        // And the document renders and is signed, with no middle name anywhere.
        $this->signAsEmployer($alpha['admin'], $onboarding);
    }

    /**
     * ── THE ANSWERS ALREADY GIVEN ARE NOT TOUCHED ────────────────
     *
     * Withdrawing a QUESTION and deleting the ANSWERS are different acts, and
     * only the first one happened. `users.gender` still exists, still holds
     * what people typed under the old behaviour, and is still theirs to read
     * and change under "Your account".
     *
     * Whether that data should be purged is the owner's decision, taken through
     * erasure. This test is what stops it being taken by accident.
     */
    public function test_a_gender_already_collected_survives_the_question_being_withdrawn(): void
    {
        [$alpha, $employee, $onboarding] = $this->scenario();

        // Given under the old behaviour, by the person whose it is.
        $this->as($employee)->patchJson('/api/v1/me', ['gender' => 'Prefer not to say'])
            ->assertOk();

        // A whole onboarding runs past it, up to and including the signature.
        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $this->signAsEmployer($alpha['admin'], $onboarding);

        // Still on the column.
        $this->assertSame('Prefer not to say', $this->inTenant(
            $alpha['org'],
            fn () => User::query()->findOrFail($employee->getKey())->gender,
        ));

        // Still on the profile screen, which is where it lives now — theirs to
        // read and theirs to change.
        $this->as($employee)->getJson('/api/v1/me/profile')
            ->assertOk()->assertJsonPath('gender', 'Prefer not to say');

        $this->as($employee)->patchJson('/api/v1/me', ['gender' => 'Female'])
            ->assertOk()->assertJsonPath('gender', 'Female');
    }

    /**
     * A client still sending a gender is told the truth about it.
     *
     * It used to be asked, so a build in somebody's browser still sends it. The
     * generic "not a question on this contract" sent people looking at their
     * template for a field that was never theirs and cannot be added — the key
     * is still reserved. The refusal names the profile instead.
     */
    public function test_sending_a_gender_to_the_onboarding_form_is_refused_with_where_it_lives(): void
    {
        [$alpha, , $onboarding] = $this->scenario();

        $refusal = $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
                'answers' => ['gender' => 'Female'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.gender')
            ->json('errors')['answers.gender'][0];

        $this->assertStringContainsString('Your account', $refusal);
        $this->assertStringNotContainsString('version', $refusal);
    }

    /**
     * A middle name written through onboarding lands on the profile and nowhere
     * else, and the display name is recomposed around it.
     *
     * The one-home rule, for the key that has just joined the asked list.
     */
    public function test_a_middle_name_is_written_through_to_the_profile(): void
    {
        [$alpha, $employee, $onboarding] = $this->scenario();

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['middle_name' => 'Elena'],
        ])->assertOk();

        $fresh = $this->inTenant(
            $alpha['org'],
            fn () => User::query()->findOrFail($employee->getKey()),
        );

        $this->assertSame('Elena', $fresh->middle_name);
        $this->assertSame('Marta Elena Ruiz', $fresh->name);
    }

    // ── helpers ──────────────────────────────────────────────────

    private const BODY = 'The rate is {{wage}} per hour.';

    private function field(array $overrides = []): array
    {
        return $overrides + [
            'key'       => 'wage',
            'label'     => 'Hourly wage',
            'type'      => 'number',
            'filled_by' => 'employer',
            'required'  => true,
            'sensitive' => false,
            'retired'   => false,
            'is_expiry' => false,
        ];
    }

    private function fields(): array
    {
        return [
            $this->field(),
            $this->field([
                'key' => 'employer_sign', 'label' => 'For the employer',
                'type' => 'signature', 'filled_by' => 'employer',
            ]),
        ];
    }

    /** The organization's mark, uploaded through the endpoint that owns it. */
    private function uploadLogo(array $tenant, int $width, int $height): void
    {
        $this->signedInAs($tenant['admin'])->post('/api/v1/branding/organization', [
            'logo' => UploadedFile::fake()->image('logo.png', $width, $height),
        ])->assertOk();
    }

    /** @return array{upload_id: string, filename: string, image_path: string} */
    private function uploadCover(array $tenant, int $width, int $height): array
    {
        return $this->signedInAs($tenant['admin'])->post('/api/v1/hr/contracts/covers', [
            'cover' => UploadedFile::fake()->image('cover.png', $width, $height),
        ])->assertCreated()->json('data');
    }

    private function currentLogoBytes(array $tenant): string
    {
        return $this->signedInAs($tenant['admin'])
            ->get('/api/v1/branding/organization')->assertOk()->streamedContent();
    }

    /**
     * A contract published with the given cover, and optionally an onboarding
     * under way against it.
     *
     * @return array{0: string, 1: string|null} the contract, and the onboarding
     */
    private function contractWithCover(array $tenant, ?array $cover, bool $onboard = true): array
    {
        $contract = $this->signedInAs($tenant['admin'])->postJson('/api/v1/hr/contracts', [
            'name'   => 'Driver',
            'fields' => $this->fields(),
            'body'   => self::BODY,
            'cover'  => $cover,
        ])->assertCreated()->json('data.id');

        return [$contract, $onboard ? $this->onboardingFor($tenant, $contract) : null];
    }

    /**
     * The whole document pipeline: a tenant, a contract, an invitation somebody
     * accepted, and an onboarding pinned to the version.
     *
     * @return array{0: array, 1: User, 2: string, 3: string}
     */
    private function scenario(?string $description = null, string $tenant = 'Alpha', ?string $email = null): array
    {
        $alpha = $this->provisionTenant($tenant, $email);

        $contract = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name'        => 'Driver',
            'description' => $description,
            'fields'      => $this->fields(),
            'body'        => self::BODY,
        ])->assertCreated()->json('data.id');

        $onboarding = $this->onboardingFor($alpha, $contract);

        $employee = $this->inTenant(
            $alpha['org'],
            fn () => User::query()->where('email', $this->starterFor($tenant))->firstOrFail(),
        );

        return [$alpha, $employee, $onboarding, $contract];
    }

    private function starterFor(string $tenant): string
    {
        return strtolower($tenant).'-starter@example.test';
    }

    private function onboardingFor(array $tenant, string $contract): string
    {
        $name = (string) $tenant['org']->name;

        $invitation = $this->signedInAs($tenant['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => $this->starterFor($name),
            'first_name' => 'Marta',
            'last_name'  => 'Ruiz',
            'role_id'    => $this->roleFor($tenant),
            'scope_type' => 'franchise',
            'scope_id'   => $tenant['franchise']->id,
        ])->assertCreated();

        $this->accept($this->tokenFrom($invitation->json('data.accept_url')));

        return $this->as($tenant['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitation->json('data.id'),
            'contract_id'   => $contract,
        ])->assertCreated()->json('data.id');
    }

    private function documentFor(User $actor, string $onboarding): string
    {
        return $this->as($actor)
            ->getJson("/api/v1/hr/onboardings/{$onboarding}/document")
            ->assertOk()
            ->json('data.content');
    }

    /** The base64 payload of the cover's data URI, or a failure saying there is none. */
    private function coverDataUriIn(string $document): string
    {
        $found = preg_match('#<img class="cover-mark" src="data:image/png;base64,([^"]+)"#', $document, $m);

        $this->assertSame(1, $found, 'The document has no cover image on it.');

        return $m[1];
    }

    private function versionCount(array $tenant, string $contract): int
    {
        return $this->inTenant(
            $tenant['org'],
            fn () => ContractVersion::query()->where('contract_id', $contract)->count(),
        );
    }

    private function signAsEmployer(User $actor, string $onboarding): void
    {
        $document = $this->as($actor)
            ->getJson("/api/v1/hr/onboardings/{$onboarding}/document")->assertOk()->json('data');

        $this->as($actor)->postJson("/api/v1/hr/onboardings/{$onboarding}/sign", [
            'field_key'     => 'employer_sign',
            'document_hash' => $document['content_hash'],
            'affirmation'   => 'Jose Demasi',
            'consent'       => true,
            'drawing' => self::SIGNATURE_PNG,
            'intent'        => true,
        ])->assertCreated();
    }

    private function roleFor(array $tenant): string
    {
        return (string) $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/roles')->assertOk()->json('data.0.id');
    }

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
