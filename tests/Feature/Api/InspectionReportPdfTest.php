<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionGrant;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Support\Files\HardenedPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The report as a file somebody can send to a customer.
 *
 * Two things are worth pinning and neither is "a PDF came back". The first is
 * that the CONVERTER STAYS LOCKED: the page is built from field labels and
 * answers typed by a tenant, and a converter that resolves references in it is
 * an SSRF from inside the network. The second is that a photograph travels
 * INSIDE the file — a report whose pictures are links is a report that is blank
 * on the customer's machine.
 */
class InspectionReportPdfTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_report_downloads_as_a_pdf_naming_the_inspection(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, $this->textScreen());

        $reportId = $this->submitWith($tech, $seeded, ['crown' => 'Cracked at the wash']);

        $response = $this->signedInAs($tech)
            ->get("/api/v1/inspections/reports/{$reportId}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');

        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf, 'it should be a real PDF');
        $this->assertStringContainsString(
            'filename="Level-1-14-Maple-',
            $response->headers->get('content-disposition'),
        );
    }

    /**
     * The picture is IN the file, not pointed at from it.
     *
     * Asserted on the document rather than on the PDF's bytes, and deliberately:
     * a `data:` URI in the page is the exact property that makes the converter's
     * locks free, and it is the thing that breaks if somebody later "optimises"
     * this into an `<img src="/api/v1/attachments/…">`. That change would still
     * produce a PDF, and the PDF would be blank where the photograph was —
     * HardenedPdf refuses http outright. A size assertion would not catch it
     * reliably; this does, by name.
     */
    public function test_a_photograph_travels_inside_the_document(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, $this->photoScreen());

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $photoId = $this->signedInAs($tech)->post('/api/v1/attachments', [
            'attachable_type' => 'inspection_runs',
            'attachable_id' => $runId,
            'file' => UploadedFile::fake()->image('crown.jpg', 400, 400),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => ['damage' => [$photoId]],
        ])->assertOk();

        $reportId = $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk()->json('data.id');

        $report = $this->inTenant(
            $tenant['org'],
            fn () => InspectionReport::findOrFail($reportId),
        );

        $html = $this->inTenant(
            $tenant['org'],
            fn () => app(\App\Support\Inspection\ReportDocument::class)->html($report),
        );

        $this->assertStringContainsString('src="data:image/jpeg;base64,', $html,
            'the photograph must be carried in the page, not fetched from it');
        $this->assertStringNotContainsString('/api/v1/attachments', $html,
            'a link here is a blank space on the copy the customer opens');

        // And the whole thing still converts.
        $pdf = $this->signedInAs($tech)
            ->get("/api/v1/inspections/reports/{$reportId}/pdf")
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * A signature reaches the report as a picture, not as a uuid.
     *
     * The pad rasterises to PNG and stores the attachment id in the same
     * one-element list a photo answer uses, so this is really a test that
     * ReportDocument treats SIGNATURE like PHOTO. Before it did, the id printed
     * as text in the place the signature goes — which is the exact failure the
     * drawn mark was built to fix, reappearing one layer down.
     */
    public function test_a_signature_is_embedded_as_an_image_not_printed_as_an_id(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, [[
            'name' => 'Sign off',
            'fields' => [[
                'key' => 'signed_by',
                'label' => 'Customer signature',
                'type' => 'signature',
            ]],
        ]]);

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        // What the pad uploads: a PNG, against the run.
        $signatureId = $this->signedInAs($tech)->post('/api/v1/attachments', [
            'attachable_type' => 'inspection_runs',
            'attachable_id' => $runId,
            'file' => UploadedFile::fake()->image('signature.png', 600, 200),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => ['signed_by' => [$signatureId]],
        ])->assertOk();

        $reportId = $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk()->json('data.id');

        $report = $this->inTenant($tenant['org'], fn () => InspectionReport::findOrFail($reportId));
        $html = $this->inTenant(
            $tenant['org'],
            fn () => app(\App\Support\Inspection\ReportDocument::class)->html($report),
        );

        $this->assertStringContainsString('src="data:image/', $html,
            'the signature must be embedded, the way a photograph is');
        $this->assertStringNotContainsString($signatureId, $html,
            'the attachment id must never appear as text where the signature goes');

        $this->signedInAs($tech)
            ->get("/api/v1/inspections/reports/{$reportId}/pdf")
            ->assertOk();
    }

    /**
     * An unsigned signature field says "Not signed", not "No photograph".
     */
    public function test_an_unsigned_field_says_not_signed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, [[
            'name' => 'Sign off',
            'fields' => [[
                'key' => 'signed_by',
                'label' => 'Customer signature',
                'type' => 'signature',
            ]],
        ]]);

        $report = $this->inTenant($tenant['org'], fn () => InspectionReport::create([
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
            'inspection_definition_version_id' => $seeded['version']->id,
            'submitted_by' => $tech->id,
            'submitted_at' => now(),
            'answers' => ['signed_by' => []],
            'layout_preset' => 'step_strip',
            'definition_name' => 'Level 1',
        ]));

        $html = $this->inTenant(
            $tenant['org'],
            fn () => app(\App\Support\Inspection\ReportDocument::class)->html($report),
        );

        $this->assertStringContainsString('Not signed', $html);
        $this->assertStringNotContainsString('No photograph', $html);
    }

    /**
     * An unanswered field says so.
     *
     * Blank space reads as "nothing to report", and "nobody looked" is a
     * different finding — it is the reason the field was on the form.
     */
    public function test_an_unanswered_field_is_named_rather_than_left_blank(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, $this->textScreen(required: false));

        $report = $this->inTenant($tenant['org'], fn () => InspectionReport::create([
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
            'inspection_definition_version_id' => $seeded['version']->id,
            'submitted_by' => $tech->id,
            'submitted_at' => now(),
            'answers' => ['crown' => ''],
            'layout_preset' => 'step_strip',
            'definition_name' => 'Level 1',
        ]));

        $html = $this->inTenant(
            $tenant['org'],
            fn () => app(\App\Support\Inspection\ReportDocument::class)->html($report),
        );

        $this->assertStringContainsString('Not answered', $html);
        $this->assertStringContainsString('Crown', $html);
    }

    /**
     * The code a finding is measured against is printed with it.
     *
     * This is what the report is FOR, on a failed finding: the customer is
     * being told their chimney does not meet a named standard, and the standard
     * has to be on the page or the claim is just an opinion.
     */
    public function test_a_frozen_citation_is_printed_on_the_report(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);

        $seeded = $this->seedOffered($tenant, $tech, [[
            'name' => 'Liner',
            'fields' => [[
                'key' => 'liner', 'label' => 'Chimney liner', 'type' => 'text',
                // As publish would have written it: the words, not a pointer.
                'code' => [
                    'code' => 'R1003.11',
                    'title' => 'R1003.11 FLUE LINING (MATERIAL)',
                    'body' => 'All chimneys shall be lined.',
                ],
            ]],
        ]]);

        $report = $this->inTenant($tenant['org'], fn () => InspectionReport::create([
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
            'inspection_definition_version_id' => $seeded['version']->id,
            'submitted_by' => $tech->id,
            'submitted_at' => now(),
            'answers' => ['liner' => 'Cracked'],
            'layout_preset' => 'step_strip',
            'definition_name' => 'Level 1',
        ]));

        $html = $this->inTenant(
            $tenant['org'],
            fn () => app(\App\Support\Inspection\ReportDocument::class)->html($report),
        );

        $this->assertStringContainsString('R1003.11 FLUE LINING (MATERIAL)', $html);
        $this->assertStringContainsString('All chimneys shall be lined.', $html);
    }

    /**
     * A label somebody typed is TEXT, never markup.
     *
     * The builder lets a company name its own fields, and the page they end up
     * on is parsed by an HTML library. A field called `<script>` must arrive as
     * those nine characters.
     */
    public function test_a_tenant_typed_label_cannot_become_markup(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, [[
            'name' => 'Roof',
            'fields' => [[
                'key' => 'crown',
                'label' => '<script>alert(1)</script>',
                'type' => 'text',
            ]],
        ]]);

        $report = $this->inTenant($tenant['org'], fn () => InspectionReport::create([
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
            'inspection_definition_version_id' => $seeded['version']->id,
            'submitted_by' => $tech->id,
            'submitted_at' => now(),
            'answers' => ['crown' => '<img src=x onerror=alert(1)>'],
            'layout_preset' => 'step_strip',
            'definition_name' => 'Level 1',
        ]));

        $html = $this->inTenant(
            $tenant['org'],
            fn () => app(\App\Support\Inspection\ReportDocument::class)->html($report),
        );

        // The escaped forms are what SHOULD be there — `onerror=` survives as
        // literal text inside `&lt;img src=x onerror=alert(1)&gt;` and that is
        // the correct outcome, so asserting on that substring alone would fail
        // against working code. What must not exist is a real TAG.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    // ── the converter itself ─────────────────────────────────────

    /**
     * Every scheme is refused, and the rules are RUN rather than counted.
     *
     * "There is a rule" and "the rule refuses" are different facts, and only the
     * second one is the guarantee. dompdf is pinned as `^3.1`, so a minor
     * upgrade lands on a deploy without anybody reading the changelog.
     */
    public function test_the_converter_refuses_every_scheme_it_knows(): void
    {
        $options = HardenedPdf::options('a4');

        $this->assertFalse($options->getIsRemoteEnabled());
        $this->assertFalse($options->getIsPhpEnabled());
        $this->assertFalse($options->getIsJavascriptEnabled(),
            'dompdf ships this ON — a PDF carrying JavaScript runs in the reader that opens it');

        foreach (['file://', 'http://', 'https://', 'phar://'] as $scheme) {
            foreach ($options->getAllowedProtocols()[$scheme]['rules'] as $rule) {
                [$permitted] = $rule($scheme.'ezbizi.invalid/probe');
                $this->assertFalse($permitted, "$scheme should be refused outright");
            }
        }
    }

    /**
     * `data:` is the one exception, and it is narrow.
     *
     * PNG and JPEG carry their own bytes and reach nothing. `data:image/svg+xml`
     * is a document format with scripts and external references of its own, and
     * admitting it would hand back the whole surface the other rules close.
     */
    public function test_only_inline_png_and_jpeg_are_permitted(): void
    {
        $rules = HardenedPdf::options('a4')->getAllowedProtocols()['data://']['rules'];

        $verdict = function (string $uri) use ($rules): bool {
            foreach ($rules as $rule) {
                [$permitted] = $rule($uri);
                if ($permitted !== true) {
                    return false;
                }
            }

            return true;
        };

        $this->assertTrue($verdict('data:image/png;base64,AAAA'));
        $this->assertTrue($verdict('data:image/jpeg;base64,AAAA'));
        $this->assertFalse($verdict('data:image/svg+xml;base64,AAAA'));
        $this->assertFalse($verdict('data:text/html;base64,AAAA'));
        $this->assertFalse($verdict('data://ezbizi.invalid/probe'));
    }

    public function test_an_unknown_paper_size_is_refused_rather_than_substituted(): void
    {
        $this->expectExceptionMessageMatches('/not a size this converter knows/');

        HardenedPdf::options('a4-ish');
    }

    // ── reach ────────────────────────────────────────────────────

    public function test_a_role_without_inspection_view_cannot_download_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, $this->textScreen());
        $reportId = $this->submitWith($tech, $seeded, ['crown' => 'Fine']);

        $outsider = $this->userWithRole($tenant, 'Nadia', ['customers.view']);

        $this->signedInAs($outsider)
            ->get("/api/v1/inspections/reports/{$reportId}/pdf")
            ->assertForbidden();
    }

    /**
     * The shared link opens with no session (a Workiz job pastes it), a forged
     * one does not, and archiving the report takes the link back.
     */
    public function test_the_shared_link_opens_without_signing_in_until_archived(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, $this->textScreen());
        $reportId = $this->submitWith($tech, $seeded, ['crown' => 'Cracked at the wash']);

        $report = $this->inTenant($tenant['org'], fn () => \App\Models\InspectionReport::query()->findOrFail($reportId));
        $url = $report->shareUrl();
        $this->app['auth']->forgetGuards();

        $response = $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->get(str_replace($reportId, (string) \Illuminate\Support\Str::uuid(), $url))->assertForbidden();

        $this->inTenant($tenant['org'], fn () => $report->archive());
        $this->get($url)->assertNotFound();
    }

    /**
     * An old A1 link lands on the capture saved for it: a PDF as a PDF, A1's
     * page sandboxed, and only through a signed URL (A1's box hands those out
     * after checking A1's own token).
     */
    public function test_an_a1_report_link_serves_its_capture_only_when_signed(): void
    {
        $tenant = $this->provisionTenant('Triplex Group'); // renamed from A1-Group; the lookup must not use the name
        $files = app(\App\Support\Files\FileStore::class);
        $dir = $files->directoryFor('a1-inspection-reports', $tenant['org']->id).'/links';
        $disk = Storage::disk($files->defaultDisk());
        $disk->put("{$dir}/inspection-report-67.pdf", '%PDF-1.4 sixty-seven');
        $disk->put("{$dir}/inspection-report-27.html", '<p>twenty-seven</p>');

        $pdf = \Illuminate\Support\Facades\URL::signedRoute('inspection.a1.link', ['id' => 67]);
        $this->get($pdf)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame('%PDF-1.4 sixty-seven', $this->get($pdf)->getContent());

        $page = $this->get(\Illuminate\Support\Facades\URL::signedRoute('inspection.a1.link', ['id' => 27]))->assertOk();
        $this->assertSame('<p>twenty-seven</p>', $page->getContent());
        $this->assertStringStartsWith('sandbox;', (string) $page->headers->get('content-security-policy'));

        $this->get('/api/v1/inspections/a1/67')->assertForbidden();
        $this->get(\Illuminate\Support\Facades\URL::signedRoute('inspection.a1.link', ['id' => 68]))->assertNotFound();
    }

    // ── helpers ──────────────────────────────────────────────────

    private function textScreen(bool $required = true): array
    {
        return [[
            'name' => 'Roof',
            'fields' => [[
                'key' => 'crown',
                'label' => 'Crown',
                'type' => 'text',
                'required' => $required,
            ]],
        ]];
    }

    private function photoScreen(): array
    {
        return [[
            'name' => 'Damage',
            'fields' => [[
                'key' => 'damage',
                'label' => 'Photographs',
                'type' => 'photo',
                'max_count' => 4,
            ]],
        ]];
    }

    private function submitWith($tech, array $seeded, array $answers): string
    {
        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $this->signedInAs($tech)
            ->patchJson("/api/v1/inspections/runs/{$runId}", ['answers' => $answers])
            ->assertOk();

        return $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk()
            ->json('data.id');
    }

    private function tech(array $tenant, string $name = 'Rodrigo', array $extra = [])
    {
        return $this->userWithRole($tenant, $name, [
            'inspection.view', 'inspection.create', ...$extra, 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view']);
    }

    private function seedOffered(array $tenant, $tech, array $screens): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $tech, $screens) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => '14 Maple',
            ]);

            $definition = InspectionDefinition::create([
                'name' => 'Level 1',
                'created_by' => $tenant['admin']->id,
                'published_at' => now(),
                'screens' => $screens,
            ]);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version' => 1,
                'layout_preset' => 'step_strip',
                'screens' => $screens,
                'published_by' => $tenant['admin']->id,
            ]);

            $definition->update(['current_version_id' => $version->id]);

            InspectionDefinitionGrant::create([
                'inspection_definition_id' => $definition->id,
                'role_id' => $tech->role_id,
            ]);

            return [
                'customer' => $customer,
                'definition' => $definition->fresh(),
                'version' => $version,
            ];
        });
    }
}
