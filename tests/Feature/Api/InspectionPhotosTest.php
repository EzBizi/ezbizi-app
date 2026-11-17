<?php

namespace Tests\Feature\Api;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionGrant;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\InspectionRun;
use App\Support\Files\FileStore;
use App\Support\Privacy\Anonymiser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The photograph a technician takes on site, and where it ends up.
 *
 * ── The failure this file exists to catch ────────────────────────
 *
 * A run is HARD deleted at submit — the report is the copy that survives. So a
 * photograph attached to the run and left there is destroyed by HasAttachments'
 * own delete hook at the exact moment the inspection is finished, which is the
 * one moment the picture starts to matter. The re-point in
 * InspectionRunsController::submit is what prevents that, and
 * `test_a_photo_moves_to_the_report_when_the_run_is_submitted` is the only
 * thing standing between it and a future refactor that moves the delete.
 *
 * ── Why reports are not in Attachables ───────────────────────────
 *
 * `inspection_reports` is an internal morph identity: in the morph map so an
 * attachment can point at one, absent from Attachables::all() so the generic
 * upload endpoint can never target one. It has to be, because
 * InspectionReportPolicy::update IS `archive` — anybody who may hide a report
 * would otherwise be able to add photographs to a frozen one, or take one out
 * of it. Removal is closed in AttachmentsController::destroy, and NOT with an
 * `attachmentRemovalRefusal` on the report: that hook refuses the §9 purge
 * too, and the last test here is the one that says the purge must win.
 */
class InspectionPhotosTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    // ── the run ──────────────────────────────────────────────────

    public function test_a_technician_attaches_a_photo_to_their_own_run(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech);
        $runId = $this->startRun($tech, $seeded);

        $this->uploadPhoto($tech, $runId)
            ->assertJsonPath('data.attachable.type', 'inspection_runs')
            ->assertJsonPath('data.attachable.id', $runId);

        $this->signedInAs($tech)
            ->getJson('/api/v1/attachments?attachable_type=inspection_runs&attachable_id='.$runId)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * A draft is personal, and so is the photograph in it.
     *
     * InspectionRunPolicy answers "no such run" rather than "not allowed", and
     * the attachment inherits that answer because AttachmentsController asks the
     * subject rather than a permission of its own.
     */
    public function test_another_technician_cannot_read_a_photo_from_someone_elses_run(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $rodrigo = $this->tech($tenant, 'Rodrigo');
        $carlos = $this->tech($tenant, 'Carlos');
        $seeded = $this->seedOffered($tenant, $rodrigo);
        $this->grant($tenant, $seeded['definition'], $carlos->role_id);

        $runId = $this->startRun($rodrigo, $seeded);
        $photoId = $this->uploadPhoto($rodrigo, $runId)->json('data.id');

        $this->signedInAs($carlos)
            ->get('/api/v1/attachments/'.$photoId)
            ->assertNotFound();
    }

    // ── submit ───────────────────────────────────────────────────

    /**
     * The one that matters. The run is destroyed; the photograph is not.
     */
    public function test_a_photo_moves_to_the_report_when_the_run_is_submitted(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, screens: $this->photoScreen());

        $runId = $this->startRun($tech, $seeded);
        $photoId = $this->uploadPhoto($tech, $runId)->json('data.id');

        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => ['damage' => [$photoId]],
        ])->assertOk();

        $reportId = $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk()
            ->json('data.id');

        $this->inTenant($tenant['org'], function () use ($photoId, $reportId) {
            $this->assertSame(0, InspectionRun::count(), 'the run is gone');

            $photo = Attachment::findOrFail($photoId);
            $this->assertSame('inspection_reports', $photo->attachable_type);
            $this->assertSame($reportId, $photo->attachable_id);

            // The bytes, not just the row. The delete hook removes both, so a
            // row that survived with no file behind it is the same failure
            // wearing a different shirt.
            $this->assertTrue(
                Storage::disk($photo->disk)->exists($photo->path),
                'the photograph itself should still be on disk',
            );
        });

        // And it is still readable, now through the report's own policy.
        $this->signedInAs($tech)->get('/api/v1/attachments/'.$photoId)->assertOk();
    }

    /**
     * A report's photograph is held by the report's franchise rule too
     * (authorization sweep, 2026-11-17). GET attachments/{id} relies on
     * InspectionReportPolicy::view alone; before the policy applied reach, a
     * sibling company holding inspection.view opened the photo with 200 while
     * GET inspections/reports/{id} refused the same person with 404.
     */
    public function test_a_sibling_company_cannot_open_a_report_photograph(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, screens: $this->photoScreen());

        $runId = $this->startRun($tech, $seeded);
        $photoId = $this->uploadPhoto($tech, $runId)->json('data.id');
        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => ['damage' => [$photoId]],
        ])->assertOk();
        $this->signedInAs($tech)->postJson("/api/v1/inspections/runs/{$runId}/submit")->assertOk();
        $this->inTenant($tenant['org'], fn () => $this->assertSame('inspection_reports', Attachment::findOrFail($photoId)->attachable_type));

        $siblingFranchise = $this->inTenant($tenant['org'], function () use ($tenant) {
            $company = \App\Models\Company::create(['organization_id' => $tenant['org']->id, 'name' => 'Alpha Sibling Co']);

            return \App\Models\Franchise::create(['organization_id' => $tenant['org']->id, 'company_id' => $company->id, 'name' => 'Alpha Sibling F']);
        });
        $sideways = $this->userWithRole($tenant, 'Sibling Inspector', [
            'inspection.view', 'inspection.reports.view',
        ], 'sibling@alpha.test', $siblingFranchise);

        $refused = $this->signedInAs($sideways)->get('/api/v1/attachments/'.$photoId)->getStatusCode();
        $this->assertContains($refused, [403, 404], "sibling company got {$refused} for a report photograph");

        // The control: the same file is served to somebody whose view holds the franchise.
        $this->signedInAs($tenant['admin'])->get('/api/v1/attachments/'.$photoId)->assertOk();
    }

    // ── the frozen report ────────────────────────────────────────

    public function test_nobody_can_upload_a_photo_onto_a_report(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech);
        $reportId = $this->submitEmptyRun($tech, $seeded);

        $this->signedInAs($tech)->post('/api/v1/attachments', [
            'attachable_type' => 'inspection_reports',
            'attachable_id' => $reportId,
            'file' => UploadedFile::fake()->createWithContent('after.jpg', 'later'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_a_photo_in_a_submitted_report_cannot_be_removed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant, extra: ['inspection.archive', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view']);
        $seeded = $this->seedOffered($tenant, $tech, screens: $this->photoScreen());

        $runId = $this->startRun($tech, $seeded);
        $photoId = $this->uploadPhoto($tech, $runId)->json('data.id');
        $this->signedInAs($tech)->postJson("/api/v1/inspections/runs/{$runId}/submit")->assertOk();

        $this->signedInAs($tech)
            ->deleteJson('/api/v1/attachments/'.$photoId)
            ->assertNotFound();

        $this->inTenant($tenant['org'], fn () => $this->assertNotNull(Attachment::find($photoId)));
    }

    // ── §9 ───────────────────────────────────────────────────────

    /**
     * Erasing the customer takes the photographs of their house with it.
     *
     * `answers` is already declared erasable and blanks to `[]`. Without the
     * report reaching Attachment::personalDataAbout, that blanking would leave
     * the pictures on disk with nothing left pointing at them — §9's "uploaded
     * files" miss in its most literal form.
     */
    public function test_erasing_the_customer_removes_the_report_photograph(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, screens: $this->photoScreen());

        $runId = $this->startRun($tech, $seeded);
        $photoId = $this->uploadPhoto($tech, $runId)->json('data.id');
        $this->signedInAs($tech)->postJson("/api/v1/inspections/runs/{$runId}/submit")->assertOk();

        [$disk, $path] = $this->inTenant($tenant['org'], function () use ($photoId) {
            $photo = Attachment::findOrFail($photoId);

            return [$photo->disk, $photo->path];
        });

        app(Anonymiser::class)->purge($seeded['customer']);

        $this->inTenant($tenant['org'], function () use ($photoId, $disk, $path) {
            $this->assertNull(Attachment::find($photoId), 'the row is gone');
            $this->assertFalse(
                Storage::disk($disk)->exists($path),
                'and so are the bytes — a purge that leaves the picture has erased nothing',
            );
        });
    }

    // ── helpers ──────────────────────────────────────────────────

    private function photoScreen(): array
    {
        return [[
            'title' => 'Damage',
            'fields' => [[
                'key' => 'damage',
                'label' => 'Photographs',
                'type' => 'photo',
                'max_count' => 4,
            ]],
        ]];
    }

    private function startRun($tech, array $seeded): string
    {
        return $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');
    }

    private function submitEmptyRun($tech, array $seeded): string
    {
        $runId = $this->startRun($tech, $seeded);

        return $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk()
            ->json('data.id');
    }

    private function uploadPhoto($tech, string $runId, string $content = 'JPEGBYTES')
    {
        return $this->signedInAs($tech)->post('/api/v1/attachments', [
            'attachable_type' => 'inspection_runs',
            'attachable_id' => $runId,
            'file' => UploadedFile::fake()->createWithContent('crown.jpg', $content),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    private function tech(array $tenant, string $name = 'Rodrigo', array $extra = [])
    {
        return $this->userWithRole($tenant, $name, [
            'inspection.view', 'inspection.create', ...$extra, 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view']);
    }

    private function grant(array $tenant, InspectionDefinition $definition, string $roleId): void
    {
        $this->inTenant($tenant['org'], fn () => InspectionDefinitionGrant::create([
            'inspection_definition_id' => $definition->id,
            'role_id' => $roleId,
        ]));
    }

    /**
     * @return array{customer: Customer, definition: InspectionDefinition, version: InspectionDefinitionVersion}
     */
    private function seedOffered(array $tenant, $tech, array $screens = []): array
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
