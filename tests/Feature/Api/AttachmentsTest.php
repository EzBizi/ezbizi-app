<?php

namespace Tests\Feature\Api;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Support\Files\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Attaching a file to a record (plan §9, §11).
 *
 * The primitive the app did not have, and the one Contracts cannot ship without.
 * What is proved here is mostly about who may NOT read a file — that is the §9
 * failure the whole endpoint exists to avoid, and it is the half that a working
 * upload button will never tell you about.
 */
class AttachmentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The default disk in the test environment is `local`, and FileStore
        // reads exactly that — so faking it faking the whole store is the point
        // rather than a coincidence.
        Storage::fake('local');
    }

    // ── the round trip ───────────────────────────────────────────

    /**
     * The bytes that go in are the bytes that come out.
     *
     * `createWithContent` and not `create`, and the difference is a trap worth
     * naming: `UploadedFile::fake()->create('x.pdf', 12)` makes an EMPTY file and
     * fakes `getSize()` into reporting 12KB. A round-trip test written with it
     * asserts that twelve kilobytes came back from a file that never had any, so
     * it fails against perfectly good code — and the version of it that "passes"
     * is one that checks the reported size instead of the content, which proves
     * nothing at all.
     */
    public function test_a_file_can_be_attached_to_a_job_and_read_back(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< >>\n%%EOF\n";

        $created = $this->signedInAs($alpha['admin'])->post('/api/v1/attachments', [
            'attachable_type' => 'jobs',
            'attachable_id'   => $job->id,
            'file'            => UploadedFile::fake()->createWithContent('meter.pdf', $content),
        ], ['Accept' => 'application/json'])->assertCreated();

        $created->assertJsonPath('data.original_name', 'meter.pdf');
        $created->assertJsonPath('data.attachable.type', 'jobs');
        $created->assertJsonPath('data.attachable.id', $job->id);
        $created->assertJsonPath('data.uploaded_by.name', 'Alpha Admin');
        $created->assertJsonPath('data.size_bytes', strlen($content));

        $id = $created->json('data.id');

        $body = $this->signedInAs($alpha['admin'])->get("/api/v1/attachments/{$id}")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->streamedContent();

        $this->assertSame($content, $body);
    }

    /** Inspection labels a job's photos before or after; the list says which. */
    public function test_the_list_says_a_photos_before_or_after_label(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $id = $this->signedInAs($alpha['admin'])->post('/api/v1/attachments', [
            'attachable_type' => 'jobs',
            'attachable_id'   => $job->id,
            'file'            => UploadedFile::fake()->createWithContent('meter.pdf', "%PDF-1.4
%%EOF
"),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/attachments?attachable_type=jobs&attachable_id='.$job->id)
            ->assertJsonPath('data.0.label', null);

        $this->inTenant($alpha['org'], fn () => Attachment::findOrFail($id)->update(['label' => 'before']));

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/attachments?attachable_type=jobs&attachable_id='.$job->id)
            ->assertJsonPath('data.0.label', 'before');
    }

    /** A job's list carries its inspection reports' before and after photos, no others. */
    public function test_a_jobs_list_carries_its_inspection_reports_labelled_photos(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);
        $list  = fn () => $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/attachments?attachable_type=jobs&attachable_id='.$job->id)->json('data');

        $ids = [];
        foreach (['before', null] as $label) {
            $ids[] = $id = $this->signedInAs($alpha['admin'])->post('/api/v1/attachments', [
                'attachable_type' => 'jobs',
                'attachable_id'   => $job->id,
                'file'            => UploadedFile::fake()->createWithContent('p.pdf', "%PDF-1.4\n%%EOF\n"),
            ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        }

        $this->inTenant($alpha['org'], function () use ($alpha, $job, $ids) {
            $definition = \App\Models\InspectionDefinition::create([
                'name' => 'Level 1', 'created_by' => $alpha['admin']->id, 'published_at' => now(), 'screens' => [],
            ]);
            $version = \App\Models\InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id, 'version' => 1,
                'layout_preset' => 'step_strip', 'screens' => [], 'published_by' => $alpha['admin']->id,
            ]);
            $report = \App\Models\InspectionReport::create([
                'customer_id' => $job->customer_id, 'job_id' => $job->id,
                'inspection_definition_id' => $definition->id, 'inspection_definition_version_id' => $version->id,
                'submitted_by' => $alpha['admin']->id, 'submitted_at' => now(), 'answers' => [],
                'layout_preset' => 'step_strip', 'definition_name' => 'Level 1',
            ]);
            // Both moved to the report; only the labelled one belongs on the job.
            Attachment::whereIn('id', $ids)->update(['attachable_type' => 'inspection_reports', 'attachable_id' => $report->id]);
            Attachment::whereKey($ids[0])->update(['label' => 'before']);
        });

        $this->assertSame([$ids[0]], array_column($list(), 'id'));
    }

    /**
     * The row records the bytes, not what the uploader said about them.
     *
     * All three of these are read before `store()` moves the temporary file. Read
     * afterwards, `getSize()` answers false and `hash_file` hashes nothing — so
     * the row would record a zero-byte file with the sha256 of an empty string,
     * which looks exactly like data.
     */
    public function test_the_row_records_the_size_the_hash_and_the_detected_type(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $this->attach($alpha, $job, UploadedFile::fake()->image('photo.png', 40, 40));

        $row = $this->inTenant($alpha['org'], fn () => Attachment::firstOrFail());

        $this->assertGreaterThan(0, $row->size_bytes);
        $this->assertSame('image/png', $row->mime);

        // The hash is of the stored bytes, so it can actually be checked.
        $this->assertSame(
            hash('sha256', Storage::disk('local')->get($row->path)),
            $row->checksum,
        );
    }

    /**
     * The mime is DETECTED, never taken from the request.
     *
     * It is echoed back as a Content-Type on every download, so a
     * caller-supplied one is a caller-supplied response header. This uploads real
     * PNG bytes under a client mime of `text/html`, which is the shape of the
     * attack: get the server to serve script from its own origin.
     */
    public function test_the_client_supplied_mime_is_ignored(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $lying = UploadedFile::fake()->image('photo.png', 20, 20);

        $this->attach($alpha, $job, new UploadedFile(
            $lying->getRealPath(), 'photo.png', 'text/html', test: true,
        ));

        $row = $this->inTenant($alpha['org'], fn () => Attachment::firstOrFail());

        $this->assertSame('image/png', $row->mime);
    }

    /**
     * And served as a download whatever it is.
     *
     * An honest mime is not a safe mime. `Content-Disposition: attachment` plus
     * `nosniff` is what keeps an uploaded document from executing on this app's
     * origin, and both are asserted because either one alone has a bypass.
     */
    public function test_an_attachment_is_never_served_inline(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $id = $this->attach($alpha, $job, UploadedFile::fake()->create('notes.txt', 1, 'text/plain'))
            ->json('data.id');

        $response = $this->signedInAs($alpha['admin'])->get("/api/v1/attachments/{$id}")->assertOk();

        $this->assertStringStartsWith(
            'attachment;',
            $response->headers->get('content-disposition'),
        );
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    // ── authorization is the SUBJECT's (§4, §9) ──────────────────

    public function test_reading_a_file_needs_the_permission_and_the_reach_of_its_record(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $id = $this->attach($alpha, $job)->json('data.id');

        // A second franchise, under a company this person has nothing to do with.
        $elsewhere = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id'      => $company->id,
                'name'            => 'Other F1',
            ]);
        });

        // Holds jobs.view in full, and cannot reach the franchise the job is at.
        // The permission gate passes and the reach gate is what must refuse —
        // which is the combination an attachments-specific permission would have
        // got wrong.
        $outsider = $this->userWithRole(
            $alpha, 'Other Branch', ['jobs.view'], onlyAt: $elsewhere,
        );

        $this->signedInAs($outsider)->get("/api/v1/attachments/{$id}")->assertForbidden();

        // And somebody at the right place, with the right permission, may.
        $insider = $this->userWithRole(
            $alpha, 'This Branch', ['jobs.view'], onlyAt: $alpha['franchise'],
        );

        $this->signedInAs($insider)->get("/api/v1/attachments/{$id}")->assertOk();
    }

    /**
     * Reading is not writing, and the two are separate abilities on purpose.
     *
     * `jobs.view` gets the file; adding one or taking one away is `jobs.edit`.
     * Collapsing them would mean anybody who can look at a job can delete the
     * photograph that proves what was there.
     */
    public function test_attaching_and_removing_need_edit_rather_than_view(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $job    = $this->job($alpha);
        $reader = $this->userWithRole($alpha, 'Reader', ['jobs.view']);

        $this->signedInAs($reader)->post('/api/v1/attachments', [
            'attachable_type' => 'jobs',
            'attachable_id'   => $job->id,
            'file'            => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $id = $this->attach($alpha, $job)->json('data.id');

        $this->signedInAs($reader)->deleteJson("/api/v1/attachments/{$id}")->assertForbidden();
    }

    public function test_another_organizations_attachment_does_not_exist(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $id = $this->attach($alpha, $this->job($alpha))->json('data.id');

        // 404 and not 403: the row is not theirs to be refused, and "forbidden"
        // would confirm the id names something real.
        $this->signedInAs($beta['admin'])->get("/api/v1/attachments/{$id}")->assertNotFound();
        $this->signedInAs($beta['admin'])->deleteJson("/api/v1/attachments/{$id}")->assertNotFound();
    }

    /**
     * The path is not something a request may choose.
     *
     * `path` and `disk` are unfillable, and this is why: a request that could set
     * them would name any file on the volume — including another organization's,
     * since the tenant scope filters rows and knows nothing about the filesystem
     * — and then read it back through an endpoint that authorizes correctly
     * against the attachment's own subject.
     */
    public function test_a_request_cannot_choose_where_the_file_comes_from(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $this->signedInAs($alpha['admin'])->post('/api/v1/attachments', [
            'attachable_type' => 'jobs',
            'attachable_id'   => $job->id,
            'path'            => 'logos/somebody-else/secret.png',
            'disk'            => 'public',
            'checksum'        => str_repeat('0', 64),
            'file'            => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $row = $this->inTenant($alpha['org'], fn () => Attachment::firstOrFail());

        $this->assertNotSame('logos/somebody-else/secret.png', $row->path);
        $this->assertSame('local', $row->disk);
        $this->assertNotSame(str_repeat('0', 64), $row->checksum);
    }

    public function test_a_type_the_registry_does_not_declare_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // Neither a wire name nor a class name gets through — the string is
        // looked up in a declared map and is never treated as a class.
        foreach (['users', 'App\\Models\\User'] as $type) {
            $this->signedInAs($alpha['admin'])->post('/api/v1/attachments', [
                'attachable_type' => $type,
                'attachable_id'   => $alpha['admin']->id,
                'file'            => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
            ], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('attachable_type');
        }
    }

    // ── limits (§11) ─────────────────────────────────────────────

    /**
     * A refusal that names the number.
     *
     * "The file may not be greater than 20480 kilobytes" is a unit nobody thinks
     * in. The limit is lowered here rather than uploading twenty megabytes, which
     * would prove the same thing far more slowly.
     */
    public function test_an_oversized_file_is_refused_with_the_actual_limit_in_the_message(): void
    {
        config(['ezbizi.attachments.max_kilobytes' => 100]);

        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $this->signedInAs($alpha['admin'])->post('/api/v1/attachments', [
            'attachable_type' => 'jobs',
            'attachable_id'   => $job->id,
            'file'            => UploadedFile::fake()->create('huge.pdf', 400, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file')
            ->assertJsonPath(
                'errors.file.0',
                fn ($m) => str_contains((string) $m, '100 KB'),
            );
    }

    /**
     * The upload PHP threw away before the validator could weigh it.
     *
     * A file over `upload_max_filesize` but inside `post_max_size` arrives
     * present and empty: an UploadedFile carrying UPLOAD_ERR_INI_SIZE and no
     * bytes. `max:` has nothing to compare, so Laravel says "The file failed to
     * upload" — true, useless, and it sends somebody to check their connection
     * for a problem that is a size limit.
     */
    public function test_a_file_php_refused_for_its_size_still_says_so(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $refusedByPhp = new UploadedFile(
            // The path is not read: Symfony skips the existence check when an
            // error is set, which is the same thing PHP does — there are no
            // bytes to point at.
            '', 'enormous.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, test: true,
        );

        $this->signedInAs($alpha['admin'])->post('/api/v1/attachments', [
            'attachable_type' => 'jobs',
            'attachable_id'   => $job->id,
            'file'            => $refusedByPhp,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.file.0',
                fn ($m) => str_contains((string) $m, 'too large'),
            );
    }

    public function test_an_extension_this_installation_does_not_take_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        // SVG is deliberately absent from the list: it is a document a browser
        // executes script from, served from this app's own origin.
        $this->signedInAs($alpha['admin'])->post('/api/v1/attachments', [
            'attachable_type' => 'jobs',
            'attachable_id'   => $job->id,
            'file'            => UploadedFile::fake()->create('logo.svg', 2, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /**
     * The limit the client is told is the one that will be enforced.
     *
     * UploadLimits clamps the configured value to what PHP will actually carry,
     * so a config of 100MB on a box with `post_max_size = 8M` must not be quoted
     * at anybody — they would spend four minutes uploading a file that PHP
     * discards before this application sees it.
     */
    public function test_the_list_publishes_the_limit_that_will_really_be_applied(): void
    {
        config(['ezbizi.attachments.max_kilobytes' => PHP_INT_MAX]);

        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $limits = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/attachments?attachable_type=jobs&attachable_id='.$job->id)
            ->assertOk()->json('limits');

        $this->assertLessThan(PHP_INT_MAX, $limits['max_kilobytes']);
        $this->assertNotEmpty($limits['extensions']);
    }

    // ── the file follows the record (§9) ─────────────────────────

    /**
     * A soft delete keeps everything, a force delete takes it.
     *
     * Both matter and they pull opposite ways. A soft-deleted job can be
     * restored, and a restore that comes back without its photographs is a lie
     * about what was kept. A force-deleted one has to take its files, or the
     * bytes outlive the record that justified holding them with nothing left in
     * the database pointing at them — personal data nobody can ever find again
     * to erase.
     */
    public function test_files_survive_a_soft_delete_and_go_with_a_force_delete(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $this->attach($alpha, $job);

        $path = $this->inTenant($alpha['org'], fn () => Attachment::firstOrFail()->path);

        $this->inTenant($alpha['org'], fn () => $job->delete());

        Storage::disk('local')->assertExists($path);
        $this->assertSame(1, $this->inTenant($alpha['org'], fn () => Attachment::count()));

        $this->inTenant($alpha['org'], fn () => $job->forceDelete());

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, $this->inTenant($alpha['org'], fn () => Attachment::count()));
    }

    public function test_removing_an_attachment_removes_the_bytes_and_leaves_a_record_of_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $id   = $this->attach($alpha, $job)->json('data.id');
        $path = $this->inTenant($alpha['org'], fn () => Attachment::firstOrFail()->path);

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/attachments/{$id}")->assertOk();

        Storage::disk('local')->assertMissing($path);

        // The audit entry carries the name and the hash, because after this
        // there is no row to look at and no file to inspect.
        $entry = $this->inTenant(
            $alpha['org'],
            fn () => AuditEvent::where('action', 'attachment.removed')->firstOrFail(),
        );

        $this->assertSame($id, $entry->target_id);
        $this->assertArrayHasKey('checksum', $entry->metadata);
    }

    // ── the disk is config, not a literal (§11) ──────────────────

    /**
     * The regression this whole class exists for.
     *
     * Two controllers passed the literal `'local'` to `store()` and read files
     * back with `Storage::disk('local')->path()` — a method that exists only on
     * the local driver. FILESYSTEM_DISK therefore moved nothing, and setting it
     * to `s3` would have kept writing locally and then thrown on the first read.
     *
     * Pointing the default at a second disk and finding the bytes there is the
     * only assertion that actually proves the config value decides anything.
     */
    public function test_changing_the_default_disk_moves_where_files_are_written(): void
    {
        Storage::fake('elsewhere');
        config(['filesystems.default' => 'elsewhere']);

        $alpha = $this->provisionTenant('Alpha');
        $job   = $this->job($alpha);

        $id = $this->attach($alpha, $job)->json('data.id');

        $row = $this->inTenant($alpha['org'], fn () => Attachment::firstOrFail());

        $this->assertSame('elsewhere', $row->disk);
        Storage::disk('elsewhere')->assertExists($row->path);
        Storage::disk('local')->assertMissing($row->path);

        // And it is served from where it was written rather than from wherever
        // the default happens to point later. This is what the `disk` column is
        // for, and without it a change of default would 404 every existing file.
        config(['filesystems.default' => 'local']);

        $this->signedInAs($alpha['admin'])->get("/api/v1/attachments/{$id}")->assertOk();
    }

    public function test_a_logo_still_works_now_that_it_goes_through_the_store(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->post('/api/v1/branding/organization', [
            'logo' => UploadedFile::fake()->image('logo.png', 512, 512),
        ], ['Accept' => 'application/json'])->assertOk();

        // Inline, because the validator has already established these bytes are
        // a PNG of one of two exact sizes — unlike an arbitrary attachment.
        $response = $this->signedInAs($alpha['admin'])
            ->get('/api/v1/branding/organization')->assertOk();

        $this->assertStringStartsWith(
            'inline;',
            $response->headers->get('content-disposition'),
        );
        $this->assertSame('image/png', $response->headers->get('content-type'));
    }

    /** Per-organization prefix from day one (§11), so erasure is a prefix delete. */
    public function test_files_are_stored_under_the_organizations_own_prefix(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->attach($alpha, $this->job($alpha));

        $path = $this->inTenant($alpha['org'], fn () => Attachment::firstOrFail()->path);

        $this->assertStringStartsWith(
            app(FileStore::class)->directoryFor('attachments', $alpha['org']->id).'/',
            $path,
        );
    }

    /**
     * Declaring a type attachable is two edits, and this is what joins them.
     *
     * Attachables says a record type can carry files; HasAttachments is what
     * gives it the relation and — more importantly — the delete hook that takes
     * the files with the record. A type listed in one and not the other produces
     * "call to undefined method attachments()" on the first upload, and if
     * somebody adds the relation by hand instead, a permanently deleted record
     * silently leaves its files on disk with nothing pointing at them.
     */
    public function test_every_declared_attachable_can_actually_carry_attachments(): void
    {
        $missing = [];

        foreach (\App\Support\Files\Attachables::all() as $key => $class) {
            if (! in_array(
                \App\Models\Concerns\HasAttachments::class,
                class_uses_recursive($class),
                true,
            )) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, sprintf(
            'These are declared attachable and do not use HasAttachments: %s',
            implode(', ', $missing),
        ));
    }

    // ── helpers ──────────────────────────────────────────────────

    private function job(array $tenant): Job
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number'              => 'C-'.bin2hex(random_bytes(3)),
                'name'                => 'A Customer',
            ]);

            return Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id'  => $customer->id,
                'number'       => 'J-'.bin2hex(random_bytes(3)),
                'title'        => 'Work',
                'status'       => 'scheduled',
                'currency'     => 'USD',
            ]);
        });
    }

    private function attach(array $tenant, Job $job, ?UploadedFile $file = null)
    {
        return $this->signedInAs($tenant['admin'])->post('/api/v1/attachments', [
            'attachable_type' => 'jobs',
            'attachable_id'   => $job->id,
            'file'            => $file ?? UploadedFile::fake()->create('a.pdf', 3, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }
}
