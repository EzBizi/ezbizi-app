<?php

namespace Tests\Feature\Api;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\OnboardingDocument;
use App\Models\Role;
use App\Models\User;
use App\Support\Contracts\DocumentFingerprint;
use App\Support\Contracts\PdfRenderer;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The PDF COPY of a contract document.
 *
 * Everything here is about one claim, because it is the only claim the copy
 * makes and the whole design turns on it: THIS FILE IS A COPY OF THE DOCUMENT
 * THAT WAS SIGNED, AND IT IS NOT THE DOCUMENT THAT WAS SIGNED.
 *
 * So the tests are, in order: it is converted from the stored bytes rather than
 * rendered a second time; it says on its face what it is; it carries a
 * fingerprint of its own that is written down and verifies; it is refused when
 * the bytes it would copy no longer match their fingerprint; it needs exactly
 * what reading the HTML needs and is logged the same way; it refuses in a
 * sentence when there is nothing to copy; and the converter fetches nothing,
 * ever, from a document a customer wrote.
 */
class HrOnboardingDocumentPdfTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const IBAN = 'ES91 2100 0418 4502 0005 1332';

    protected function setUp(): void
    {
        parent::setUp();

        // FileStore reads `filesystems.default`, which is `local` under test.
        Storage::fake('local');
    }

    // ── 1. it is a copy of the stored bytes ──────────────────────

    /**
     * The one property that makes this a copy rather than a second document.
     *
     * An AMENDMENT is what tells the two apart, and nothing else can. After it,
     * revision 1 on the disk says the old wage and the template would render the
     * new one — so a converter that re-rendered instead of reading would hand
     * somebody a PDF of terms nobody ever signed, and it would look perfect.
     *
     * Both revisions are asked for, because "always shows revision 1" would pass
     * half of this on its own.
     */
    public function test_the_copy_is_converted_from_the_stored_bytes_and_not_rendered_again(): void
    {
        [$alpha, $employee, $id] = $this->signedOnboarding();

        $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$id}/amendments", [
            'answers' => ['wage' => '4.00'],
            'reason'  => 'The wage was typed wrongly on the original.',
        ])->assertCreated();

        $original = $this->textOf($this->pdf($alpha['admin'], $id, revision: 1));

        $this->assertStringContainsString('18.50', $original,
            'The copy of revision 1 did not carry revision 1 wage, so it was not made from '
            .'the bytes revision 1 stored.');
        $this->assertStringNotContainsString('4.00', $original,
            'The copy of revision 1 carried the AMENDED wage — it was re-rendered from the '
            .'template, which is the second-artefact failure the whole design forbids.');

        $amended = $this->textOf($this->pdf($alpha['admin'], $id, revision: 2));

        $this->assertStringContainsString('4.00', $amended);
        $this->assertStringNotContainsString('18.50', $amended);
    }

    /**
     * The fingerprint printed on the copy is the fingerprint OF the bytes it was
     * made from — checked against the file on the disk rather than against the
     * row, so this cannot pass by copying one column into another.
     */
    public function test_the_fingerprint_on_the_copy_verifies_against_the_stored_document(): void
    {
        [$alpha, , $id] = $this->signedOnboarding();

        $printed = $this->textOf($this->pdf($alpha['admin'], $id));
        $stored  = $this->storedBytes($alpha, $id, revision: 1);
        $row     = $this->documentRow($alpha, $id, revision: 1);

        $this->assertStringContainsString((string) $row->content_hash, $printed,
            'The copy did not print the fingerprint of the document it derives from.');

        $this->assertTrue(
            DocumentFingerprint::matches($stored, (string) $row->content_hash, (string) $row->hash_algorithm),
            'The fingerprint the copy prints does not verify against the bytes on the disk.',
        );
    }

    // ── 2. it says what it is ────────────────────────────────────

    /**
     * A derived copy that looks like the original is the thing that causes
     * trouble later, so this asserts the words rather than the presence of a box.
     *
     * The footer is asserted separately from the banner because they exist for
     * different failures: the banner is for somebody opening the file, the footer
     * is for page three of a printout that has been separated from page one.
     */
    public function test_the_copy_says_on_its_face_that_it_is_a_copy_and_names_its_source(): void
    {
        [$alpha, , $id] = $this->signedOnboarding();

        $response = $this->pdfResponse($alpha['admin'], $id);
        $body     = (string) $response->getContent();
        $text     = $this->textOf($body);
        $row      = $this->documentRow($alpha, $id, revision: 1);

        $this->assertStringStartsWith('%PDF-', $body, 'That is not a PDF.');

        // The banner.
        $this->assertStringContainsString('COPY OF A DOCUMENT SIGNED ELECTRONICALLY', $text);
        $this->assertStringContainsString('Driver', $text, 'The copy does not name the contract.');
        $this->assertStringContainsString('version 1, revision 1', $text,
            'The copy does not identify which document it derives from.');
        $this->assertStringContainsString((string) $row->content_hash, $text);

        /*
         * The footer, stamped on every page rather than written into the HTML.
         *
         * This document runs to two pages, which is what makes the assertion
         * worth making: the second page is the one that gets separated from the
         * first in a folder, and it has to say what it is on its own.
         */
        $this->assertStringContainsString('page 1 of 2', $text);
        $this->assertStringContainsString('page 2 of 2', $text,
            'A loose page cannot say which page of what it is.');

        // Once per page. The banner shouts the same words in capitals, which is
        // why this counts the footer's own casing rather than a shared phrase.
        $this->assertSame(2, substr_count($text, 'COPY of a document signed electronically'),
            'The footer is not on every page of the copy.');

        // And it arrives as a file, never as something to render on this origin.
        $response->assertHeader('Content-Type', PdfRenderer::MIME);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        // Symfony re-orders Cache-Control directives alphabetically on the way
        // out, so this is `private, no-store` as it was written and as it arrives.
        $response->assertHeader('Cache-Control', 'no-store, private');

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment', $disposition,
            'A contract converted from customer text must never be rendered on this origin.');
        $this->assertStringContainsString('driver-v1-r1.pdf', $disposition,
            'The copy is not named after the document it copies.');
    }

    // ── 3. the copy's own fingerprint ────────────────────────────

    /**
     * What ties a PDF in somebody's inbox back to the document it came from.
     *
     * The recorded value is checked by hashing the bytes that were actually
     * SENT, so a hash recorded over something else — the pre-banner document, an
     * earlier render — fails here.
     */
    public function test_the_copy_carries_its_own_fingerprint_and_it_is_recorded(): void
    {
        [$alpha, , $id] = $this->signedOnboarding();

        $response = $this->pdfResponse($alpha['admin'], $id);
        $body     = (string) $response->getContent();
        $row      = $this->documentRow($alpha, $id, revision: 1);

        $copyHash = DocumentFingerprint::of($body);

        $response->assertHeader('X-Copy-Fingerprint', $copyHash);
        $response->assertHeader('X-Document-Fingerprint', (string) $row->content_hash);

        $recorded = $this->inTenant($alpha['org'], fn () => AuditEvent::query()
            ->where('action', 'onboarding.document_copied')->get());

        $this->assertCount(1, $recorded, 'A copy left the building without a record of it.');

        $metadata = $recorded->first()->metadata;

        $this->assertSame($copyHash, $metadata['copy']['hash']);
        $this->assertSame(strlen($body), $metadata['copy']['length']);
        $this->assertSame((string) $row->content_hash, $metadata['document']['hash']);
        $this->assertSame(1, $metadata['revision']);

        $this->assertTrue(
            DocumentFingerprint::matches($body, $metadata['copy']['hash'], $metadata['copy']['algorithm']),
            'The fingerprint recorded for the copy does not verify against the copy.',
        );
    }

    /**
     * One document, one copy — and therefore one fingerprint to be traced by.
     *
     * A converter that stamped `now()` into every file would give a single
     * revision an unbounded number of fingerprints, and "this is the copy that
     * was downloaded" would stop being a thing anybody could check.
     */
    public function test_two_copies_of_one_revision_are_the_same_file(): void
    {
        [$alpha, , $id] = $this->signedOnboarding();

        $first  = $this->pdf($alpha['admin'], $id);
        $second = $this->pdf($alpha['admin'], $id);

        $this->assertSame(
            DocumentFingerprint::of($first),
            DocumentFingerprint::of($second),
            'Two conversions of one stored document produced two different files.',
        );
    }

    // ── 4. refusals ──────────────────────────────────────────────

    /**
     * R8: what somebody is told when there is nothing to copy.
     *
     * Two different nothings, and they need different sentences — a form nobody
     * has signed yet is not the same problem as a revision number that does not
     * exist.
     */
    public function test_a_copy_of_a_document_that_does_not_exist_refuses_in_a_sentence(): void
    {
        [$alpha, , $id] = $this->unsignedOnboarding();

        $unsigned = $this->as($alpha['admin'])
            ->get("/api/v1/hr/onboardings/{$id}/document/pdf")
            ->assertNotFound()
            ->json('message');

        $this->assertIsSentence($unsigned);
        $this->assertStringContainsString('nothing has been signed', $unsigned);

        [$beta, , $signedId] = $this->signedOnboarding('Beta');

        $missing = $this->as($beta['admin'])
            ->get("/api/v1/hr/onboardings/{$signedId}/document/pdf?revision=9")
            ->assertNotFound()
            ->json('message');

        $this->assertIsSentence($missing);
        $this->assertStringContainsString('revision 9', $missing);
    }

    /**
     * A copy must not assert on its face that it came from a signed document
     * when the server can no longer say that the bytes are the signed document.
     *
     * The HTML endpoint still answers, with `intact: false` beside the content,
     * and that difference is deliberate: a screen can show you the verdict, a
     * file that has been emailed to somebody cannot.
     */
    public function test_no_copy_is_made_of_a_document_that_no_longer_matches_its_fingerprint(): void
    {
        [$alpha, , $id] = $this->signedOnboarding();

        $attachment = $this->inTenant($alpha['org'], fn () => Attachment::findOrFail(
            OnboardingDocument::where('onboarding_id', $id)->firstOrFail()->attachment_id,
        ));

        Storage::disk($attachment->disk)->put($attachment->path, 'not the document that was signed');

        $refusal = $this->as($alpha['admin'])
            ->get("/api/v1/hr/onboardings/{$id}/document/pdf")
            ->assertStatus(409)
            ->json('message');

        $this->assertIsSentence($refusal);

        // The record is still readable, and still says what is wrong with it.
        $this->as($alpha['admin'])->getJson("/api/v1/hr/onboardings/{$id}/document")
            ->assertOk()->assertJsonPath('data.intact', false);
    }

    // ── 5. the same door as the HTML ─────────────────────────────

    /**
     * Adding a format must not add a way round the mechanism.
     *
     * `GET /attachments/{id}` was that hole once — the document endpoint demanded
     * the reveal permission and the file endpoint served the same bytes on `view`
     * alone. This asserts the PDF is not the third door: the same refusal for the
     * same reader, and an entry naming the same fields when it is allowed.
     */
    public function test_the_copy_needs_what_the_document_needs_and_is_recorded_the_same_way(): void
    {
        [$alpha, , $id] = $this->signedOnboarding();

        // Signing read the document twice already, so the counts here are deltas
        // rather than totals — the alternative is a test that quietly depends on
        // how many times the ceremony happens to open the document.
        $before = $this->revealEvents($alpha)->count();

        // `hr.view` reaches the record and not the answers in it.
        $reader = $this->userWithRole($alpha, 'Reader', ['hr.view']);

        $this->as($reader)->getJson("/api/v1/hr/onboardings/{$id}/document")->assertForbidden();
        $this->as($reader)->get("/api/v1/hr/onboardings/{$id}/document/pdf")->assertForbidden();

        $this->assertCount($before, $this->revealEvents($alpha),
            'A refused read still wrote a reveal entry.');

        // The employer holds `hr.edit`, which is what revealing takes.
        $this->as($alpha['admin'])->getJson("/api/v1/hr/onboardings/{$id}/document")->assertOk();
        $this->as($alpha['admin'])->get("/api/v1/hr/onboardings/{$id}/document/pdf")->assertOk();

        $reveals = $this->revealEvents($alpha);

        $this->assertCount($before + 2, $reveals,
            'Reading the copy was not recorded as a reveal.');

        $copies = $reveals->filter(
            fn (AuditEvent $e) => $e->metadata['via'] === 'contract document (PDF copy)',
        );

        $this->assertCount(1, $copies, 'The entry does not say which artefact was handed over.');

        foreach ($reveals as $event) {
            $this->assertSame(['iban'], $event->metadata['fields'],
                'The two formats do not name the same disclosed fields.');
        }
    }

    /** Another organization's contract is not reachable in either format. */
    public function test_the_copy_is_scoped_to_the_tenant_like_everything_else(): void
    {
        [$alpha, , $id] = $this->signedOnboarding();

        $before = $this->revealEvents($alpha)->count();

        $beta = $this->provisionTenant('Beta');

        $this->as($beta['admin'])->get("/api/v1/hr/onboardings/{$id}/document/pdf")
            ->assertNotFound();

        $this->assertCount($before, $this->revealEvents($alpha));
    }

    // ── 6. the converter fetches nothing ─────────────────────────

    /**
     * THE SECURITY TEST. The document body is tenant input, and a converter that
     * resolves references in it is a request forger standing inside the network.
     *
     * ── How each half is proved ──────────────────────────────────
     *
     * REMOTE, by a socket that is really listening. The document points at a
     * throwaway HTTP server this test starts on the loopback interface, and the
     * assertion is that NOTHING EVER CONNECTED to it.
     *
     * It is worth saying why it is not done the tidier way, because the tidier
     * way was here first and it did not work. Replacing the `http` and `https`
     * STREAM WRAPPERS looks like interception and is not: dompdf fetches through
     * cURL whenever the extension is loaded (Helpers::getFileContent picks
     * `$can_use_curl` ahead of `file_get_contents`), and cURL does not go
     * through PHP's stream layer at all. That version of this test passed with
     * every lock in PdfRenderer deleted — it watched a door the converter never
     * uses. A socket is underneath both paths, so it sees either one.
     *
     * The server answers 404 immediately rather than hanging, so that a
     * regression fails in a second instead of blocking on cURL's default
     * no-timeout until somebody kills the suite.
     *
     * LOCAL, by comparison, because there is no connection to observe for a file
     * read. Two conversions are run of documents that differ only in whether the
     * file they point at EXISTS. A converter allowed to read local files embeds
     * a 200-byte image in one and a placeholder in the other; a converter that
     * reads nothing takes the identical path through both and — the conversion
     * being deterministic — produces identical bytes. So one equality covers it.
     *
     * The HTML is handed to the renderer directly rather than through a contract
     * template, and that is not a shortcut. DocumentRenderer escapes the body, so
     * a tenant CANNOT get an `<img>` into a document today; this asserts that the
     * converter is safe anyway, which is the property that has to hold when the
     * escaping is one refactor away from somebody's good idea.
     */
    /**
     * The banner has to survive the document it is warning you about.
     *
     * PdfRenderer's docblock used to claim that inline styling meant the
     * document's own stylesheet could not restyle the banner. That is not what
     * inline styling does: an inline declaration outranks a stylesheet only FOR
     * THE PROPERTY IT DECLARES, and the box declared border, padding, margin,
     * font and colour — not `display`. So `div { display: none }`, which is not
     * even an attack so much as a plausible stylesheet, removed the entire
     * notice while every one of those declarations still won.
     *
     * A tenant cannot reach this today, because the body is escaped and no
     * `<style>` element survives it. That is exactly why it is worth a test: the
     * property has to hold for the day the escaping is one refactor away from
     * somebody's good idea, which is the same argument the network test makes.
     *
     * The footer is asserted here too. It is stamped onto the canvas after
     * layout, so no CSS can reach it at all, and it is what still says "copy"
     * if the banner is ever defeated by a property this does not know about.
     */
    /**
     * The paper is the ORGANIZATION's, not the installation's.
     *
     * It was `config('contracts.pdf.paper')`, one value for everybody on the
     * box, and that file's own docblock conceded it was the wrong home: two
     * organizations on one installation disagree the moment one of them is in
     * North America. A franchise in Ohio and its parent in Manchester printed
     * contracts on the same wrong sheet.
     *
     * Asserted on the page geometry rather than on the setting coming back out
     * of the resolver, which would only prove the resolver works. A4 is
     * 595.28×841.89pt and letter is 612×792pt — wider and shorter — so the MediaBox
     * says which sheet was actually used.
     */
    public function test_the_paper_follows_the_organization_setting(): void
    {
        [$alpha, , $id] = $this->signedOnboarding();

        $default = $this->pdf($alpha['admin'], $id);

        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0[\d.]*\s+0[\d.]*\s+595\./', $default,
            'The copy did not come out on A4, which is the registry default, so this test '
            .'cannot tell a changed setting from a coincidence.');

        // Inside the tenant: `settings` is tenant-scoped and TenantScope fails
        // closed, so writing one from a bare test refuses rather than writing a
        // row nobody owns.
        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('contract_pdf_paper', $alpha['org'], 'letter'));

        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0[\d.]*\s+0[\d.]*\s+612\./',
            $this->pdf($alpha['admin'], $id),
            'The organization asked for letter and the copy still came out on A4, so the '
            .'paper size is not reading the setting.');
    }

    public function test_the_footer_survives_a_document_that_tries_to_hide_the_banner(): void
    {
        $renderer = app(PdfRenderer::class);

        $hiding = '<html><head><style>'
            .'div { display: none !important; visibility: hidden !important; }'
            .'</style></head><body>'
            .'<div>THE RULE REACHED THIS ONE</div>'
            .'<p>Terms of the agreement.</p>'
            .'</body></html>';

        $text = $this->textOf($renderer->render(...$this->conversionOf($hiding)));

        // The control, and this one comes first: if the converter ignored the
        // stylesheet altogether the banner would survive for a reason that says
        // nothing, and the assertion below would pass vacuously forever.
        $this->assertStringNotContainsString('THE RULE REACHED THIS ONE', $text,
            'The converter ignored the document’s stylesheet entirely, so this test cannot say '
            .'anything about whether the banner resists one. Find a rule it does honour.');

        // The banner IS gone here, and that is not a bug being tolerated
        // quietly — it is the measured limit of an HTML banner, written up in
        // PdfRenderer's docblock. What must never be gone is the footer.
        $this->assertStringContainsString('COPY of a document signed electronically', $text,
            'A hostile stylesheet removed the LAST mark saying the file is a copy. The banner '
            .'can be defeated — that is known and documented — but the footer is stamped onto '
            .'the canvas after layout, where no CSS reaches it. If this fails, a copy of a '
            .'signed contract can be dressed up as the original.');

        $this->assertStringContainsString('Terms of the agreement.', $text,
            'The document itself did not render, so the assertion above proves nothing.');
    }

    public function test_the_converter_fetches_nothing_a_tenant_document_points_at(): void
    {
        $renderer = app(PdfRenderer::class);

        $image = tempnam(sys_get_temp_dir(), 'ezbizi-pdf-probe-').'.png';
        file_put_contents($image, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $missing = $image.'.gone';

        $probe = $this->startProbe();

        try {
            $withFile    = $renderer->render(...$this->conversionOf(
                $this->hostileDocument($image, $probe['port']),
            ));
            $withoutFile = $renderer->render(...$this->conversionOf(
                $this->hostileDocument($missing, $probe['port']),
            ));
        } finally {
            $reached = $this->stopProbe($probe);
        }

        $this->assertSame([], $reached,
            'The converter reached for the network from a document a customer wrote.');

        $this->assertSame($withFile, $withoutFile,
            'A document pointing at a file that exists converted differently from one pointing '
            .'at a file that does not, so the converter read the local disk.');

        // And the file it was pointed at is nowhere in the output.
        $this->assertStringNotContainsString('ezbizi-pdf-probe', $this->textOf($withFile));

        @unlink($image);
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * A hostile document — the shapes a contract body could take if the
     * escaping in DocumentRenderer ever stopped.
     *
     * Every remote reference points at the loopback probe rather than at a real
     * address. That is on purpose: a test that aimed at 169.254.169.254 for
     * realism would prove nothing on a box with no route to it, and would sit
     * there timing out on a box that has one. The probe is a socket that is
     * genuinely listening, so "nothing connected" is a fact about the converter
     * rather than a fact about this machine's network.
     *
     * The paths are still the ones that matter — the instance metadata path is
     * what an attacker would ask for on this platform's own hosting, where it
     * answers credentials to anything that asks — so a recorded hit reads like
     * the attack it would have been.
     */
    private function hostileDocument(string $localFile, int $port): string
    {
        $host = '127.0.0.1:'.$port;

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<title>Hostile</title>'
            .'<link rel="stylesheet" href="http://'.$host.'/latest/meta-data/">'
            .'<style>@import url("https://'.$host.'/steal.css");</style>'
            .'</head><body>'
            .'<p>Terms of the agreement.</p>'
            .'<p><img src="http://'.$host.'/latest/meta-data/iam/" alt="a"></p>'
            .'<p><img src="https://'.$host.'/beacon.png" alt="b"></p>'
            .'<p><img src="file://'.$localFile.'" alt="c"></p>'
            .'<p><img src="'.$localFile.'" alt="d"></p>'
            .'</body></html>';
    }

    /**
     * `data:` is the ONE permitted scheme, and only in one exact shape.
     *
     * It used to be refused outright, which was right until a version could
     * carry a cover: DocumentRenderer embeds that PNG as
     * `data:image/png;base64,` INSIDE the document, so a blanket refusal meant
     * a copy opened on a cover page with nothing on it.
     *
     * Permitting it is safe for a reason that does not extend to the other
     * schemes: a `data:` URI goes NOWHERE. It carries its own bytes, already in
     * the document being converted, so resolving it reaches nothing this server
     * can see. Every other scheme is refused precisely because it sends the
     * converter somewhere on the strength of text a customer wrote.
     *
     * But `data:` is a container for anything, which is what this test is
     * about. `data:image/svg+xml` is the one that matters — SVG is a document
     * format with scripts and external references of its own, so admitting it
     * would hand back the whole surface PdfRenderer exists to close. The rule
     * matches the PNG prefix rather than the scheme, and this holds it to that.
     */
    public function test_only_an_inline_png_is_permitted_and_no_other_data_uri(): void
    {
        $renderer = app(PdfRenderer::class);

        // A real 1x1 PNG, so the permitted case is genuinely permitted rather
        // than passing because nothing decoded.
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $svg = base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'
        );

        $inlinePng = '<p><img src="data:image/png;base64,'.$png.'" alt="ok"></p>';

        $legitimate = $renderer->render(...$this->conversionOf(
            '<html><body><p>Terms.</p>'.$inlinePng.'</body></html>'
        ));

        $this->assertStringContainsString('/Subtype /Image', $legitimate,
            'The inline PNG was refused, so a cover would never reach the copy — which is '
            .'the whole reason data: is permitted at all.');

        /*
         * The comparison, and why it is a comparison rather than a count.
         *
         * dompdf draws a BROKEN-IMAGE PLACEHOLDER where it refuses to load
         * something, and a placeholder is itself an image in the PDF. So
         * counting images cannot tell "the SVG was drawn" from "the SVG was
         * refused and a placeholder took its place" — the first draft of this
         * test asserted a count of one, got two, and the two was the
         * placeholder doing its job.
         *
         * What settles it is treating the hostile data: URIs exactly like a URL
         * that is definitely refused. If a document carrying `data:image/svg+xml`
         * and `data:text/html` converts to the same number of images as one
         * carrying two refused `http://` images, then those schemes were refused
         * too — whatever dompdf chose to paint in their place.
         */
        $withHostileData = $renderer->render(...$this->conversionOf(
            '<html><body><p>Terms.</p>'.$inlinePng
            .'<p><img src="data:image/svg+xml;base64,'.$svg.'" alt="a"></p>'
            .'<p><img src="data:text/html;base64,'.base64_encode('<b>no</b>').'" alt="b"></p>'
            .'</body></html>'
        ));

        $withRefusedHttp = $renderer->render(...$this->conversionOf(
            '<html><body><p>Terms.</p>'.$inlinePng
            .'<p><img src="http://169.254.169.254/one.png" alt="a"></p>'
            .'<p><img src="http://169.254.169.254/two.png" alt="b"></p>'
            .'</body></html>'
        ));

        $this->assertSame(
            substr_count($withRefusedHttp, '/Subtype /Image'),
            substr_count($withHostileData, '/Subtype /Image'),
            'A data: URI that is not an inline PNG was treated differently from a refused '
            .'http:// image, so the rule is matching the SCHEME rather than the exact '
            .'prefix — and an SVG carries scripts and external references of its own.',
        );
    }

    /** The arguments a conversion takes, with everything but the document fixed. */
    /**
     * What they uploaded rides at the back of the copy (Mia, 2026-10-02: the
     * finished contract "that includes all the images, signatures"): a picture
     * is drawn, any other file is named, and the stored document is untouched.
     */
    public function test_uploaded_pictures_are_printed_after_the_contract(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $without = app(PdfRenderer::class)->render(...$this->conversionOf('<html><body><p>Terms.</p></body></html>'));
        $with = app(PdfRenderer::class)->render(...$this->conversionOf('<html><body><p>Terms.</p></body></html>') + [
            'attachments' => [
                ['label' => 'Driving licence', 'name' => 'licence.png', 'mime' => 'image/png', 'data' => $png],
                ['label' => 'Certificate', 'name' => 'cert.pdf', 'mime' => 'application/pdf', 'data' => null],
            ],
        ]);

        $this->assertStringNotContainsString('/Subtype /Image', $without);
        $this->assertStringContainsString('/Subtype /Image', $with);
        $text = $this->textOf($with);
        $this->assertStringContainsString('Attachments', $text);
        $this->assertStringContainsString('licence.png', $text);
        $this->assertStringContainsString('cert.pdf', $text);
    }

    /**
     * Everything on file the wording does not state, after the contract
     * (Jose, 2026-10-05: "bank information, ssn, etc").
     */
    public function test_the_record_is_printed_after_the_contract(): void
    {
        $with = app(PdfRenderer::class)->render(...$this->conversionOf('<html><body><p>Terms.</p></body></html>') + [
            'record' => [['label' => 'Bank account', 'value' => self::IBAN], ['label' => 'Work hours', 'value' => 'Mornings']],
        ]);

        $text = $this->textOf($with);
        $this->assertStringContainsString('Employee record', $text);
        $this->assertStringContainsString(self::IBAN, $text);
        $this->assertStringContainsString('Mornings', $text);
        $this->assertStringNotContainsString('Employee record', $this->textOf(
            app(PdfRenderer::class)->render(...$this->conversionOf('<html><body><p>Terms.</p></body></html>')),
        ));
    }

    private function conversionOf(string $document): array
    {
        return [
            'storedDocument'    => $document,
            'organizationName'  => 'Alpha',
            'contractName'      => 'Driver',
            'version'           => 1,
            'revision'          => 1,
            'sourceFingerprint' => str_repeat('a', 64),
            'sourceAlgorithm'   => 'sha256',
            'sourceStoredAt'    => new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')),
        ];
    }

    /**
     * Start a throwaway HTTP server on the loopback interface.
     *
     * It runs in a CHILD PROCESS, which is the only way a single-threaded test
     * can have something answering a socket while it is itself blocked inside a
     * conversion. It records the moment of ACCEPT rather than a parsed request,
     * so an `https://` reference — whose TLS handshake this plain socket will
     * never complete — is still counted as the connection it was.
     *
     * The child picks its own port and prints it; nothing here guesses a number
     * or races another test for one.
     *
     * @return array{port: int, log: string, process: resource, pipes: array}
     */
    private function startProbe(): array
    {
        $log = tempnam(sys_get_temp_dir(), 'ezbizi-pdf-probe-log-');

        $code = sprintf(
            '$log = %s;'
            .'$s = stream_socket_server("tcp://127.0.0.1:0", $e, $m);'
            .'if (! $s) { fwrite(STDOUT, "0\n"); exit(1); }'
            .'$n = stream_socket_get_name($s, false);'
            .'fwrite(STDOUT, substr($n, strrpos($n, ":") + 1) . "\n");'
            .'while (($c = @stream_socket_accept($s, 30)) !== false) {'
            .'    file_put_contents($log, "connected\n", FILE_APPEND);'
            // Answered at once, and closed. A probe that held the connection
            // open would leave a regression hanging on cURL's default of no
            // timeout at all, which turns a failing test into a stuck suite.
            .'    @fwrite($c, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");'
            .'    @fclose($c);'
            .'}',
            var_export($log, true),
        );

        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process, 'The probe server would not start.');

        // Blocks until the child has bound and is listening, so the conversion
        // below cannot race the socket into existence.
        $port = (int) trim((string) fgets($pipes[1]));

        $this->assertGreaterThan(0, $port, 'The probe server did not report a port.');

        return ['port' => $port, 'log' => $log, 'process' => $process, 'pipes' => $pipes];
    }

    /**
     * Stop the probe and report what reached it.
     *
     * Called from a `finally`, because a child process left running would
     * outlive the suite and hold a port.
     *
     * @return string[]  one entry per connection accepted
     */
    private function stopProbe(array $probe): array
    {
        proc_terminate($probe['process']);

        foreach ($probe['pipes'] as $pipe) {
            @fclose($pipe);
        }

        proc_close($probe['process']);

        $recorded = (string) @file_get_contents($probe['log']);

        @unlink($probe['log']);

        return array_values(array_filter(explode("\n", trim($recorded))));
    }

    /**
     * The words a PDF puts on the page, pulled back out of it.
     *
     * The content streams are inflated where they are compressed, and the string
     * literals inside the text operators are joined — which is enough to assert
     * on wording and is deliberately not a PDF parser.
     */
    private function textOf(string $pdf): string
    {
        $streams = '';

        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $matches)) {
            foreach ($matches[1] as $chunk) {
                $inflated = @gzuncompress($chunk);
                $streams .= ($inflated === false ? $chunk : $inflated)."\n";
            }
        }

        $text = '';

        if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $streams, $literals)) {
            foreach ($literals[0] as $literal) {
                $text .= stripcslashes(substr($literal, 1, -1)).' ';
            }
        }

        return $text;
    }

    private function assertIsSentence(?string $message): void
    {
        $this->assertIsString($message, 'The refusal said nothing at all.');
        $this->assertStringContainsString(' ', $message, 'That is an identifier, not a sentence.');
        $this->assertMatchesRegularExpression('/[.!?]$/', trim($message));

        foreach (['Exception', '::', 'hr.view', 'hr.edit', 'AuthorizationException', 'HTTP_'] as $tell) {
            $this->assertStringNotContainsString($tell, $message,
                'The refusal shows an internal identifier instead of explaining itself.');
        }
    }

    private function pdf(User $actor, string $id, ?int $revision = null): string
    {
        return $this->pdfResponse($actor, $id, $revision)->getContent();
    }

    private function pdfResponse(User $actor, string $id, ?int $revision = null): TestResponse
    {
        $url = "/api/v1/hr/onboardings/{$id}/document/pdf"
            .($revision === null ? '' : "?revision={$revision}");

        return $this->as($actor)->get($url)->assertOk();
    }

    private function documentRow(array $tenant, string $id, int $revision): OnboardingDocument
    {
        return $this->inTenant($tenant['org'], fn () => OnboardingDocument::query()
            ->where('onboarding_id', $id)->where('revision', $revision)->firstOrFail());
    }

    private function storedBytes(array $tenant, string $id, int $revision): string
    {
        $attachment = $this->inTenant($tenant['org'], fn () => Attachment::findOrFail(
            $this->documentRow($tenant, $id, $revision)->attachment_id,
        ));

        return Storage::disk($attachment->disk)->get($attachment->path);
    }

    private function revealEvents(array $tenant)
    {
        return $this->inTenant($tenant['org'], fn () => AuditEvent::query()
            ->where('action', 'sensitive_value.revealed')->get());
    }

    // ── the scenario ─────────────────────────────────────────────

    /** A tenant, a contract, an accepted invitation and a form filled in. */
    private function unsignedOnboarding(string $name = 'Alpha'): array
    {
        $alpha = $this->provisionTenant($name);

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

        $role = $this->inTenant($alpha['org'], function () use ($alpha) {
            $role = Role::create([
                'organization_id' => $alpha['org']->id,
                'name'            => 'Technician',
                'is_system'       => false,
            ]);

            // Nothing from HR: a new starter fills in their own form without it.
            $role->syncPermissionKeys(['customers.view']);

            return $role;
        });

        $email = 'newstarter@'.$alpha['org']->slug.'.test';

        $invitation = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => $email,
            'first_name' => 'Marta',
            'last_name'  => 'Ruiz',
            'role_id'    => $role->id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ])->assertCreated();

        parse_str(
            (string) parse_url((string) $invitation->json('data.accept_url'), PHP_URL_QUERY),
            $query,
        );

        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        $this->postJson('/api/v1/invitations/accept', [
            'token'         => $query['token'],
            'password'      => 'a-real-password',
            'phone'         => '+34 600 000 000',
            'address'       => '1 Test Street',
            'date_of_birth' => '1990-04-03',
        ])->assertCreated();

        $employee = $this->inTenant(
            $alpha['org'],
            fn () => User::where('email', $email)->firstOrFail(),
        );

        $id = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitation->json('data.id'),
            'contract_id'   => $contractId,
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$id}", [
            'answers' => ['wage' => '18.50', 'iban' => self::IBAN],
        ])->assertOk();

        return [$alpha, $employee, $id];
    }

    /** The same, signed by both sides, so revision 1 exists on the disk. */
    private function signedOnboarding(string $name = 'Alpha'): array
    {
        [$alpha, $employee, $id] = $this->unsignedOnboarding($name);

        $this->signAs($alpha['admin'], $id, 'employer_sign', 'Alpha Admin');
        $this->signAs($employee, $id, 'employee_sign', 'Marta Ruiz');

        return [$alpha, $employee, $id];
    }

    private function signAs(User $actor, string $id, string $field, string $name): void
    {
        $hash = $this->as($actor)->getJson("/api/v1/hr/onboardings/{$id}/document")
            ->assertOk()->json('data.content_hash');

        $this->as($actor)->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key'     => $field,
            'affirmation'   => $name,
            'consent'       => true,
            'drawing' => self::SIGNATURE_PNG,
            'document_hash' => $hash,
        ])->assertCreated();
    }

    /**
     * ScopeReach memoises reach per actor for the life of the container, and
     * Laravel does not reset scoped instances between requests inside one test —
     * so switching sides without this asks two questions of one cached answer.
     */
    private function as(User $actor): static
    {
        $this->app->forgetScopedInstances();

        return $this->signedInAs($actor);
    }
}
