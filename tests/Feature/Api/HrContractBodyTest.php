<?php

namespace Tests\Feature\Api;

use App\Models\ContractVersion;
use App\Models\User;
use App\Support\Contracts\MergeTags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The contract writing itself — the body, its merge tags, and the document they
 * produce.
 *
 * The owner's words, looking at the shipped designer: *"we are missing something
 * critical which is the contract writing itself, you have the shortcodes but
 * nowhere to put them."*
 *
 * ── What is actually at stake in each of these ───────────────────
 *
 * Almost none of it is "does the string come out right". A merge tag is a hole
 * in a legal document that something is poured into months after it was written,
 * by a different person, with nobody watching. So:
 *
 *   a tag that names nothing        must be refused WHILE SOMEBODY IS LOOKING at
 *                                   the editor, not discovered on the contract of
 *                                   somebody who was hired in November
 *   a tag with no answer behind it  must render as a gap that says it is one. An
 *                                   empty string is not a missing term, it is a
 *                                   DIFFERENT term, and nobody agreed to it
 *   a body                          is tenant-authored text rendered as HTML into
 *                                   a file served from this origin with a
 *                                   signature on it. It is hostile input
 *   the preview                     must be the same bytes as the thing signed,
 *                                   or approving it means nothing
 *   the wording                     must be versioned exactly as hard as the
 *                                   fields, because it is what people agree to
 */
class HrContractBodyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The signed document is written through FileStore, which reads
        // `filesystems.default` — `local` under test.
        Storage::fake('local');
    }

    // ── 1. what a tag may name ───────────────────────────────────

    /**
     * The three sources, all in one document, because the point is that they
     * share one syntax and one namespace.
     *
     * A field is the template's own. The common core is read from the profile —
     * the one-home rule means the contract MERGES a first name rather than asking
     * for a second copy of it. The facts come off the record itself, and none of
     * the four is a question anybody should have to declare as a field.
     */
    public function test_tags_resolve_for_fields_the_common_core_and_the_facts(): void
    {
        [$alpha, , $onboarding] = $this->scenario(body:
            'This Agreement is made on {{document.date}} between {{organization.name}} '
            .'and {{first_name}} {{last_name}}, under {{contract.name}} version '
            .'{{contract.version}}, at a rate of {{wage}} per hour.'
        );

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $html = $this->documentFor($alpha['admin'], $onboarding);

        // The field, the two core values, and all four facts.
        $this->assertStringContainsString('at a rate of 18.50 per hour', $html);
        $this->assertStringContainsString('between Alpha and Marta Ruiz', $html);
        $this->assertStringContainsString('under Driver version 1', $html);
        $this->assertStringContainsString('made on '.now()->toDateString().' between', $html);

        // And nothing is left holding a tag.
        $this->assertStringNotContainsString('{{', $html);
    }

    /**
     * The document date is the day the paperwork was drawn up, and it CANNOT be
     * the day it was signed.
     *
     * Not a preference. DocumentRenderer is deterministic by contract — the same
     * onboarding renders the same bytes every time — because the signing endpoint
     * refuses a signature whose fingerprint no longer matches what the signer
     * read. A document carrying the moment of signing would change between the
     * preview and the render taken to store it, so no signature could ever be
     * accepted. This proves the value survives a signature unchanged.
     */
    public function test_the_document_date_does_not_move_when_the_document_is_signed(): void
    {
        [$alpha, , $onboarding] = $this->scenario(body: 'Dated {{document.date}}.');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $before = $this->documentFor($alpha['admin'], $onboarding);

        $this->signAsEmployer($alpha['admin'], $onboarding);

        $this->assertSame(
            $this->agreementIn($before),
            $this->agreementIn($this->documentFor($alpha['admin'], $onboarding)),
            'The wording changed between the preview that was fingerprinted and the document '
            .'that was stored.',
        );
    }

    // ── 2. refusals, at publish, naming the tag ──────────────────

    /**
     * A typo is refused with the tag quoted back.
     *
     * The alternative is what this whole check exists to prevent: the version
     * publishes, months pass, and the mistake surfaces on the employment contract
     * of somebody who started last week — at which point the document is wrong,
     * the version is immutable, and nobody involved can do anything about it
     * without publishing a new one and re-papering the hire.
     */
    public function test_an_unknown_tag_is_refused_at_publish_and_names_itself(): void
    {
        [$alpha, $contract] = $this->aContract();

        $response = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => 'Pay is {{waige}} per hour.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertStringContainsString('{{waige}}', $response->json('errors.body.0'));

        // And nothing was written on the way past.
        $this->assertSame(1, $this->versionCount($alpha, $contract));
    }

    /**
     * A retired field cannot be merged, and the refusal says which one.
     *
     * A retired field is not asked of anybody joining under this version, so the
     * tag would print the "not provided" gap on every single contract the version
     * produces — a template that is broken for everybody rather than for one
     * unlucky record.
     */
    public function test_a_retired_tag_is_refused_at_publish_and_names_itself(): void
    {
        [$alpha, $contract] = $this->aContract();

        $response = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                // Retired rather than removed — removing a key is refused for its
                // own reason (it orphans answers), and this test is about the tag.
                'fields' => [$this->field(['retired' => true])],
                'body'   => 'Pay is {{wage}} per hour.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $error = $response->json('errors.body.0');

        $this->assertStringContainsString('{{wage}}', $error);
        $this->assertStringContainsString('Hourly wage', $error);
        $this->assertStringContainsString('retired', $error);
    }

    /**
     * Two more refusals that are about the SHAPE of the thing being asked for.
     *
     * A signature is an act, not a value — merging one into a sentence would be
     * printing the evidence of an agreement inside the agreement, which is the
     * same circularity that keeps signatures out of the document entirely.
     *
     * A stray `{{` is refused rather than printed, and that is the strict half of
     * the syntax decision: `{{` ALWAYS opens a tag. The alternative — printing
     * anything that fails to parse — means a mistyped tag silently becomes part
     * of the contract's wording.
     */
    public function test_a_signature_tag_and_a_stray_brace_are_both_refused(): void
    {
        [$alpha, $contract] = $this->aContract();

        $signature = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => [...$this->fields(), $this->signatureField()],
                'body'   => 'Signed: {{employer_sign}}',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertStringContainsString('{{employer_sign}}', $signature->json('errors.body.0'));

        $malformed = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => 'A bonus of {{ 50% of turnover }} may be paid.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertStringContainsString('50% of turnover', $malformed->json('errors.body.0'));
    }

    /**
     * A single brace is ordinary text and must survive untouched.
     *
     * The mirror of the test above, and it is what makes the strictness
     * defensible rather than merely strict: `{` on its own is refused by nothing,
     * so an author who genuinely needs a brace has one.
     */
    public function test_a_single_brace_is_not_a_tag(): void
    {
        [$alpha, , $onboarding] = $this->scenario(body: 'Schedule {A} applies. Rate {{wage}}.');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $this->assertStringContainsString(
            'Schedule {A} applies. Rate 18.50.',
            $this->documentFor($alpha['admin'], $onboarding),
        );
    }

    // ── 3. the unanswered case ───────────────────────────────────

    /**
     * A tag with no answer behind it renders as a gap that NAMES ITSELF.
     *
     * This is the decision the brief asks to be defended, and the defence is that
     * the two alternatives both produce a document nobody wrote. An empty string
     * turns "a bonus of {{bonus}} is payable" into "a bonus of  is payable",
     * which is not a sentence with a hole in it — it is a different sentence, and
     * it is the one a tribunal would read. A bare dash says something is missing
     * and not what.
     *
     * So it is the field's own label, in brackets, saying plainly that it was not
     * provided: impossible to mistake for a term, and it identifies which term
     * went missing.
     *
     * It is also rare BY DESIGN rather than by luck — a required field cannot get
     * this far, because submit refuses while one is unanswered. Reaching it means
     * the template's author marked the field optional, so the document has to be
     * signable with it empty.
     */
    public function test_a_blank_answer_renders_as_a_named_gap_rather_than_an_empty_one(): void
    {
        [$alpha, , $onboarding] = $this->scenario(
            fields: [
                $this->field(),
                $this->field(['key' => 'bonus', 'label' => 'Annual bonus', 'type' => 'text',
                    'required' => false]),
            ],
            body: 'A bonus of {{bonus}} is payable each year.',
        );

        $html = $this->documentFor($alpha['admin'], $onboarding);

        $this->assertStringContainsString(
            'A bonus of <span class="unanswered">[Annual bonus not provided]</span> is payable',
            $html,
        );

        // The sentence must not be able to read as though a term were agreed.
        $this->assertStringNotContainsString('A bonus of  is payable', $html);
    }

    // ── 4. the body is hostile input ─────────────────────────────

    /**
     * Script in the body cannot execute in the document, and neither can script
     * in an ANSWER.
     *
     * Both halves matter and they fail differently. The body is written by
     * somebody with the authority to publish a template — so this is not
     * primarily about them attacking themselves; it is that the document is
     * served from this application's own origin to an employee, and a template
     * author who can inject script into it can act as every new starter who opens
     * their contract. The answer half is the ordinary case: the person filling in
     * the form is not trusted at all.
     */
    public function test_script_in_the_body_cannot_execute_in_the_rendered_document(): void
    {
        [$alpha, , $onboarding] = $this->scenario(
            fields: [$this->field(['key' => 'notes', 'label' => 'Notes', 'type' => 'text'])],
            body: "<script>fetch('//evil')</script>\n\nNotes: {{notes}}",
        );

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['notes' => '<img src=x onerror=alert(1)>'],
        ])->assertOk();

        $html = $this->documentFor($alpha['admin'], $onboarding);

        // The TAG is what executes. `onerror=` is not asserted absent, and must
        // not be: the escaped payload below contains that exact substring as
        // visible text, which is the correct outcome — an attribute with no
        // element to hang off is four words in a paragraph.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);

        // Present, and visible as text — swallowing it would be its own bug: the
        // author would never learn their markup did nothing.
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);

        // And the document is still one element deep where the body went, so
        // there is no second context the escaping could be wrong for.
        $this->assertStringContainsString('<section class="agreement">', $html);
    }

    /**
     * A merge value cannot smuggle a tag in, because the substitution never
     * re-reads what it has just written.
     *
     * Without that, an answer of "{{iban}}" typed into any field the employee can
     * write would print somebody's bank account into the contract — a field
     * reaching another field, through a document.
     */
    public function test_an_answer_that_looks_like_a_tag_is_not_treated_as_one(): void
    {
        [$alpha, , $onboarding] = $this->scenario(
            fields: [
                $this->field(['key' => 'notes', 'label' => 'Notes', 'type' => 'text']),
                $this->field(['key' => 'iban', 'label' => 'Bank account', 'type' => 'text']),
            ],
            body: 'Notes: {{notes}}',
        );

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['notes' => '{{iban}}', 'iban' => 'ES91 2100 0418 4502 0005 1332'],
        ])->assertOk();

        $html = $this->documentFor($alpha['admin'], $onboarding);

        $this->assertStringContainsString('Notes: {{iban}}', $html);
        $this->assertStringNotContainsString('ES91 2100', $html);
    }

    // ── 5. the preview is the document ───────────────────────────

    /**
     * The preview and the signed document are produced by one piece of code, so
     * for the same inputs they are the same bytes.
     *
     * Compared as the AGREEMENT SECTION rather than the whole file, and
     * deliberately: the two documents genuinely differ in their headers, because
     * a preview is of the version that WOULD be published next while the signed
     * document is of the version that produced it. What has to be identical is
     * the merged wording — every tag in the same place, every value escaped the
     * same way — because that is what somebody approves in the editor and what
     * somebody else signs.
     *
     * The sample values are settable as answers on purpose, which is what lets
     * this be an equality assertion rather than a shape assertion.
     */
    public function test_the_preview_matches_what_signing_produces_for_the_same_inputs(): void
    {
        $body = 'Duties: {{duties}}. Starting {{start_date}}.';

        $fields = [
            $this->field(['key' => 'duties', 'label' => 'Duties', 'type' => 'text']),
            $this->field(['key' => 'start_date', 'label' => 'Start date', 'type' => 'text']),
            $this->signatureField(),
        ];

        [$alpha, , $onboarding, $contract] = $this->scenario(fields: $fields, body: $body);

        $preview = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/preview", ['fields' => $fields, 'body' => $body])
            ->assertOk();

        // The samples are the labels, bracketed — never a plausible name or a
        // plausible wage, because a preview that looks real is one somebody
        // prints and hands to a new starter.
        $this->assertStringContainsString('Duties: [Duties]. Starting [Start date].',
            $preview->json('data.content'));
        $this->assertTrue($preview->json('data.is_preview'));

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['duties' => '[Duties]', 'start_date' => '[Start date]'],
        ])->assertOk();

        $this->signAsEmployer($alpha['admin'], $onboarding);

        $signed = $this->as($alpha['admin'])
            ->getJson("/api/v1/hr/onboardings/{$onboarding}/document")->assertOk();

        $this->assertFalse($signed->json('data.is_preview'), 'The document was never stored.');

        $this->assertSame(
            $this->agreementIn($preview->json('data.content')),
            $this->agreementIn($signed->json('data.content')),
            'The wording somebody approved in the editor is not the wording somebody signed.',
        );
    }

    /**
     * A preview carries REAL facts and SAMPLE answers, and the split is
     * deliberate.
     *
     * The business's name, the contract's name and the version are known before
     * anybody is hired, so showing "[Business name]" where the real one will
     * appear tells the author nothing. An answer is not known, and inventing a
     * plausible one — a name, a wage — produces a page somebody prints and
     * mistakes for a contract.
     */
    public function test_a_preview_shows_real_facts_and_sample_answers(): void
    {
        [$alpha, $contract] = $this->aContract();

        $content = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/preview", [
                'fields' => $this->fields(),
                'body'   => 'Between {{organization.name}} and {{first_name}}, at {{wage}}.',
            ])
            ->assertOk()
            ->json('data.content');

        $this->assertStringContainsString(
            'Between Alpha and [First name], at [Hourly wage].',
            $content,
        );

        // A template with no wording says so on the LIST, so nobody has to open
        // every one to find the ones still producing a form dump — which is the
        // state the owner was pointing at.
        $this->assertFalse($this->contractList($alpha)[0]['has_body']);

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => 'Between {{organization.name}} and {{first_name}}.',
            ])->assertCreated();

        $this->assertTrue($this->contractList($alpha)[0]['has_body']);
    }

    /** The preview refuses precisely what publishing refuses, and says the same thing. */
    public function test_the_preview_refuses_the_tags_publishing_refuses(): void
    {
        [$alpha, $contract] = $this->aContract();

        $response = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/preview", [
                'fields' => $this->fields(),
                'body'   => 'Pay is {{waige}} per hour.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertStringContainsString('{{waige}}', $response->json('errors.body.0'));
    }

    /**
     * The list of tags that resolve comes from the server, for the field list
     * being EDITED.
     *
     * A client compiling its own would have to know four server rules — that
     * retired fields are out, that signatures are out, that the common core is
     * asked but `middle_name` is not, and that facts are dotted — restated in a
     * language that cannot see them change. The exact text to insert comes back
     * too, so the client does not even concatenate the delimiters.
     */
    public function test_the_resolvable_tags_are_published_rather_than_compiled_by_the_client(): void
    {
        [$alpha, $contract] = $this->aContract([
            $this->field(),
            $this->field(['key' => 'gone', 'label' => 'Old thing', 'type' => 'text', 'retired' => true]),
            $this->signatureField(),
        ]);

        $tags = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/preview", [
                'fields' => [
                    $this->field(),
                    $this->field(['key' => 'gone', 'label' => 'Old thing', 'type' => 'text', 'retired' => true]),
                    $this->signatureField(),
                ],
            ])
            ->assertOk()
            ->json('data.merge_tags');

        $keys = array_column($tags, 'key');

        $this->assertContains('wage', $keys);
        $this->assertContains('first_name', $keys);
        $this->assertContains('contract.name', $keys);
        $this->assertNotContains('gone', $keys, 'A retired field was offered as a tag.');
        $this->assertNotContains('employer_sign', $keys, 'A signature was offered as a tag.');
        $this->assertNotContains('middle_name', $keys, 'A reserved key onboarding never asks was offered.');

        $wage = $tags[array_search('wage', $keys, true)];

        $this->assertSame('{{wage}}', $wage['tag']);
        $this->assertSame('Hourly wage', $wage['label']);
        $this->assertSame('field', $wage['source']);

        // The syntax itself, so the client never hardcodes the delimiters.
        $catalogue = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/hr/contracts/catalogue')->assertOk()->json('data.merge_tags');

        $this->assertSame('{{', $catalogue['open']);
        $this->assertSame('}}', $catalogue['close']);
        $this->assertSame(
            ['organization.name', 'contract.name', 'contract.version', 'document.date'],
            array_column($catalogue['facts'], 'key'),
        );
    }

    // ── 6. the wording is versioned ──────────────────────────────

    /**
     * The body is in the diff, and a change to it alone is publishable.
     *
     * The second half is the one with teeth. Publishing an unchanged field list
     * is refused — a version that changes nothing splits the records that follow
     * it from the ones before for no reason. If the body were not counted, then
     * rewriting the entire agreement while touching no field would hit that
     * refusal: the one publish that most needs a version of its own would be the
     * one the server turned away.
     */
    public function test_the_body_is_in_the_diff_and_a_body_only_change_can_be_published(): void
    {
        [$alpha, $contract] = $this->aContract();

        // v1 has no wording, so adding it is an addition rather than an edit —
        // three different acts, reported as three.
        $added = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => 'Pay is {{wage}} per hour.',
            ])->assertCreated();

        $this->assertSame('added', $added->json('data.diff.body'));
        $this->assertSame([], $added->json('data.diff.added'), 'No field changed, yet one was reported.');
        $this->assertFalse($added->json('data.diff.is_empty'));

        $edited = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => 'Pay is {{wage}} per hour, reviewed annually.',
            ])->assertCreated();

        $this->assertSame('edited', $edited->json('data.diff.body'));
        $this->assertSame(3, $edited->json('data.version'));

        // Identical wording AND identical fields is still refused.
        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => 'Pay is {{wage}} per hour, reviewed annually.',
            ])->assertStatus(422)->assertJsonValidationErrors('fields');

        // Every version keeps its own wording, and the history says so.
        $versions = $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/contracts/{$contract}/versions")->assertOk()->json('data');

        $this->assertSame('Pay is {{wage}} per hour, reviewed annually.', $versions[0]['body']);
        $this->assertSame('Pay is {{wage}} per hour.', $versions[1]['body']);
        $this->assertNull($versions[2]['body']);
    }

    /**
     * Taking the wording out is its own kind of change, and it is reported as
     * one.
     *
     * It does not blank the contract — it drops the document back to the
     * generated form dump — so somebody who cleared the box by accident has
     * changed what every new starter signs. That deserves a word of its own in
     * the diff rather than being folded in with an edit.
     */
    public function test_removing_the_wording_is_reported_and_falls_back_to_the_generated_form(): void
    {
        [$alpha, $contract] = $this->aContract();

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => 'Pay is {{wage}} per hour.',
            ])->assertCreated();

        $removed = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                // Whitespace, not omission: "I cleared the box" has to be the
                // same act as "I sent nothing", or there are two ways to spell it.
                'body'   => "  \n  ",
            ])->assertCreated();

        $this->assertSame('removed', $removed->json('data.diff.body'));
        $this->assertNull($removed->json('data.contract.body'));
    }

    /**
     * The wording cannot be changed through the endpoint that renames things.
     *
     * PATCH already refuses a field list, and the body is refused there for a
     * stronger reason than consistency: `description` on that same request is a
     * note ABOUT the template, while the body is the agreement itself. Accepting
     * it would let somebody rewrite what people sign as a side effect of fixing a
     * typo in the name — with no version, no diff and nothing in the history.
     */
    public function test_the_wording_cannot_be_changed_through_patch(): void
    {
        [$alpha, $contract] = $this->aContract();

        $response = $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/contracts/{$contract}", [
                'name' => 'Driver (updated)',
                'body' => 'Something entirely different.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertStringContainsString('/versions', $response->json('errors.body.0'));

        // And the rename did not go through either — a refusal is not a partial
        // save.
        $this->assertSame(
            'Driver',
            $this->signedInAs($alpha['admin'])
                ->getJson("/api/v1/hr/contracts/{$contract}")->json('data.name'),
        );
    }

    /**
     * A body is capped, for the reason every immutable column here is capped.
     *
     * It can never be edited or deleted once published, and the version-history
     * screen returns every version's body in one response — so an uncapped body
     * is a payload that grows with each publish and cannot be taken back.
     */
    public function test_a_body_is_capped(): void
    {
        [$alpha, $contract] = $this->aContract();

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => str_repeat('a', MergeTags::MAX_BODY_LENGTH + 1),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    /**
     * The cap is in CHARACTERS, and the column has to hold that many of them in
     * any script.
     *
     * The two are not the same limit and the difference is a silent one. MySQL's
     * `text` holds 65,535 BYTES, so a legal 40,000-character agreement written in
     * Cyrillic — 80,000 bytes under utf8mb4 — would have been truncated on the
     * way in: a contract quietly missing its last clause, in a row that can never
     * be edited, under a signature. Hence `mediumText`.
     */
    public function test_a_body_survives_a_script_that_costs_more_than_a_byte_a_character(): void
    {
        [$alpha, $contract] = $this->aContract();

        // Two bytes per letter under utf8mb4, so this is ~75,000 bytes —
        // comfortably past what `text` would have held, and comfortably inside
        // the cap. Built with no trailing space on purpose: Laravel's
        // TrimStrings middleware trims every string on the way in, so a body
        // with one would come back a character shorter and the assertion below
        // would be measuring the framework rather than the column.
        $body = implode(' ', array_fill(0, 5000, 'договор'));

        $this->assertGreaterThan(65535, strlen($body));
        $this->assertLessThan(MergeTags::MAX_BODY_LENGTH, mb_strlen($body));

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$contract}/versions", [
                'fields' => $this->fields(),
                'body'   => $body,
            ])->assertCreated();

        $this->assertSame(
            $body,
            $this->signedInAs($alpha['admin'])
                ->getJson("/api/v1/hr/contracts/{$contract}")->assertOk()->json('data.body'),
            'The wording came back shorter than it went in.',
        );
    }

    /**
     * A template with no wording still produces a document, and it is the one it
     * always produced.
     *
     * The generated form dump is the FALLBACK now rather than the only output,
     * and it has to stay exactly what it was: every version published before
     * bodies existed renders through it, and "the same record produces the same
     * document" is the property the whole fingerprint rests on.
     */
    public function test_a_contract_with_no_wording_still_renders_the_generated_form(): void
    {
        [$alpha, , $onboarding] = $this->scenario(body: null);

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboarding}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $html = $this->documentFor($alpha['admin'], $onboarding);

        $this->assertStringContainsString('<h2>The person</h2>', $html);
        $this->assertStringContainsString('<h2>Terms</h2>', $html);
        $this->assertStringContainsString('<dt>Hourly wage</dt><dd>18.50</dd>', $html);
        $this->assertStringNotContainsString('class="agreement"', $html);
    }

    // ── helpers ──────────────────────────────────────────────────

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
        ];
    }

    private function signatureField(): array
    {
        return $this->field([
            'key' => 'employer_sign', 'label' => 'For the employer', 'type' => 'signature',
            'filled_by' => 'employer',
        ]);
    }

    private function fields(): array
    {
        return [$this->field()];
    }

    /** @return array{0: array, 1: string} the tenant, and a contract with one version */
    private function aContract(?array $fields = null, ?string $body = null): array
    {
        $alpha = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name'   => 'Driver',
            'fields' => $fields ?? $this->fields(),
            'body'   => $body,
        ])->assertCreated()->json('data.id');

        return [$alpha, $id];
    }

    /**
     * A tenant, a contract with wording, an accepted invitation and an onboarding
     * under way.
     *
     * The acceptance is not ceremony: the common core has to be complete before
     * the form can be submitted, and phone and date of birth are collected there
     * because that is where they have a home.
     *
     * @return array{0: array, 1: User, 2: string, 3: string}
     */
    private function scenario(?array $fields = null, ?string $body = null): array
    {
        $fields ??= [$this->field()];

        // An onboarding refuses a template nobody can sign, so every scenario
        // gets a signature line whether or not the test is about one. Appended
        // rather than required of each caller, so a test can say what it is
        // actually about.
        if (array_search('signature', array_column($fields, 'type'), true) === false) {
            $fields[] = $this->signatureField();
        }

        [$alpha, $contract] = $this->aContract($fields, $body);

        $invitation = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => 'newstarter@example.test',
            'first_name' => 'Marta',
            'last_name'  => 'Ruiz',
            'role_id'    => $this->roleFor($alpha),
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ])->assertCreated();

        $this->accept($this->tokenFrom($invitation->json('data.accept_url')));

        $employee = $this->inTenant(
            $alpha['org'],
            fn () => User::where('email', 'newstarter@example.test')->firstOrFail(),
        );

        $onboarding = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitation->json('data.id'),
            'contract_id'   => $contract,
        ])->assertCreated()->json('data.id');

        return [$alpha, $employee, $onboarding, $contract];
    }

    private function documentFor(User $actor, string $onboarding): string
    {
        return $this->as($actor)
            ->getJson("/api/v1/hr/onboardings/{$onboarding}/document")
            ->assertOk()
            ->json('data.content');
    }

    /** The merged wording on its own — see the parity test for why only this part. */
    private function agreementIn(string $html): string
    {
        $this->assertStringContainsString('<section class="agreement">', $html);

        preg_match('#<section class="agreement">.*?</section>#s', $html, $matches);

        return $matches[0];
    }

    private function signAsEmployer(User $actor, string $onboarding): void
    {
        $hash = $this->as($actor)
            ->getJson("/api/v1/hr/onboardings/{$onboarding}/document")
            ->assertOk()
            ->json('data.content_hash');

        $this->as($actor)->postJson("/api/v1/hr/onboardings/{$onboarding}/sign", [
            'field_key'     => 'employer_sign',
            'affirmation'   => 'Alpha Admin',
            'consent'       => true,
            'document_hash' => $hash,
        ])->assertCreated();
    }

    private function contractList(array $tenant): array
    {
        return $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/hr/contracts')->assertOk()->json('data');
    }

    private function versionCount(array $tenant, string $contract): int
    {
        return $this->inTenant(
            $tenant['org'],
            fn () => ContractVersion::where('contract_id', $contract)->count(),
        );
    }

    private function roleFor(array $tenant): string
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $role = \App\Models\Role::create([
                'organization_id' => $tenant['org']->id,
                'name'            => 'Technician',
                'is_system'       => false,
            ]);

            // Nothing from HR: a new starter must be able to fill in their own
            // form without any of it.
            $role->syncPermissionKeys(['customers.view']);

            return $role->id;
        });
    }

    /**
     * Sign in, having first dropped the scoped container — ScopeReach memoises
     * reach per actor, and a test that switches between two people without this
     * asks two questions of one cached answer.
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
