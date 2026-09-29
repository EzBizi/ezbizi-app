<?php

namespace Tests\Feature\Inspection;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Support\Files\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A1's filed reports land on their jobs, and nowhere they do not belong.
 *
 * The manifest fixtures copy the SHAPE of a real entry the A1 Tools Dismantler
 * pasted from the box (report 90) — the key names, `workiz_job_uuid: null` with
 * only the serial filled, `source: archived_pdf` — rather than a shape written
 * from a description of it. A parser built to a paraphrase passes its own tests
 * and reads nothing in production.
 *
 * Every refusal case is written against a fully valid target, so a pass means
 * the guard held rather than that there was nothing to write to.
 */
class InspectionImportA1ReportsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(app(FileStore::class)->defaultDisk());

        $this->dir = storage_path('framework/testing/a1-reports-'.bin2hex(random_bytes(4)));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** The ordinary case, and the positive control for everything below. */
    public function test_a_report_is_attached_to_its_job_by_serial(): void
    {
        $a1 = $this->provisionTenant('A1-Group');
        $job = $this->jobWithSerial($a1, '200022572');

        $this->writeManifest([$this->entry(90, '200022572')]);

        $status = Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir]);

        $this->assertSame(1, $this->attachmentsOn($a1, $job), 'report 90 lands on its job');
        $this->assertSame(0, $status);
    }

    /**
     * THE PATH PRODUCTION WILL ACTUALLY TAKE: the UUID filled, as in v2.
     *
     * Every other test here uses the first export's shape, with
     * `workiz_job_uuid: null` and only the serial — which is how the suite came
     * to cover the serial fallback thoroughly and the UUID branch not at all.
     * The Dismantler then re-wrote the manifest (manifest_version 2) with the
     * UUID filled on all seven, so the run on the box goes down the branch no
     * test had walked. Both keys present, naming the same job, is the ordinary
     * v2 case.
     */
    public function test_the_v2_manifest_attaches_by_uuid_with_the_serial_agreeing(): void
    {
        $a1 = $this->provisionTenant('A1-Group');
        $job = $this->jobWithSerial($a1, '200022572', uuid: 'K7QX2M');

        $entry = $this->entry(90, '200022572');
        $entry['workiz_job_uuid'] = 'K7QX2M';
        $this->writeManifest([$entry], version: 2);

        $status = Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir]);

        $this->assertSame(1, $this->attachmentsOn($a1, $job), 'v2 lands by UUID');
        $this->assertSame(0, $status);
    }

    /**
     * A UUID and a serial that name DIFFERENT jobs hold rather than guess.
     *
     * Both are real jobs in A1-Group, so either lookup alone would attach.
     * Only the agreement check stops it, and attaching a customer's findings to
     * the wrong job is worse than not attaching them.
     */
    public function test_a_uuid_and_serial_naming_different_jobs_holds(): void
    {
        $a1 = $this->provisionTenant('A1-Group');
        $byUuid = $this->jobWithSerial($a1, '111111111', uuid: 'AAAAAA');
        $bySerial = $this->jobWithSerial($a1, '200022572', uuid: 'BBBBBB');

        $entry = $this->entry(90, '200022572');
        $entry['workiz_job_uuid'] = 'AAAAAA';
        $this->writeManifest([$entry], version: 2);

        Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir]);

        $this->assertSame(0, $this->attachmentsOn($a1, $byUuid), 'not the job the UUID names');
        $this->assertSame(0, $this->attachmentsOn($a1, $bySerial), 'nor the job the serial names');
    }

    /**
     * No job means HELD — archived, not attached, and NOTHING CREATED.
     *
     * Report 28's case. EzBizi has no customer merge, so an importer that
     * minted a job or customer to receive an orphan would leave a permanent
     * duplicate. Asserted on the counts of jobs and customers, not only on the
     * absent attachment.
     */
    public function test_a_report_with_no_job_is_held_and_nothing_is_created(): void
    {
        $a1 = $this->provisionTenant('A1-Group');
        [$jobsBefore, $customersBefore] = $this->counts($a1);

        $this->writeManifest([$this->entry(28, null, source: 'rendered_mpdf')]);

        Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir]);

        $this->assertSame([$jobsBefore, $customersBefore], $this->counts($a1), 'no job and no customer minted for an orphan');
        $this->assertTrue(
            Storage::disk(app(FileStore::class)->defaultDisk())->exists(
                "a1-inspection-reports/{$a1['org']->id}/inspection-report-28.pdf",
            ),
            'held still means ARCHIVED — the copy is the whole point',
        );
    }

    /**
     * THE CASE THAT WOULD HURT MOST: the same serial in another organization.
     *
     * `workiz_jobs` holds every tenant's jobs. If the lookup were not confined
     * to A1-Group, a serial that happened to exist in another organization
     * would put an A-1 customer's inspection — their name, address and findings
     * — into a stranger's company. Built so that the OTHER organization is the
     * only one with a matching job, so a leaking lookup has somewhere to land.
     */
    public function test_a_matching_serial_in_another_organization_is_never_used(): void
    {
        $a1 = $this->provisionTenant('A1-Group');
        $other = $this->provisionTenant('Somebody Else');
        $theirs = $this->jobWithSerial($other, '200022572');

        $this->writeManifest([$this->entry(90, '200022572')]);

        Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir]);

        $this->assertSame(0, $this->attachmentsOn($other, $theirs), 'another organization must never receive an A1 report');
    }

    /**
     * THE LOOKUP ITSELF IS CONFINED, not merely rescued further down.
     *
     * The test above passes even with the tenant scope removed from the
     * `workiz_jobs` lookup, and that is worth recording rather than hiding: two
     * later guards catch it — `Job::find` is tenant-scoped too, so the foreign
     * id resolves to nothing, and the uploader refuses to cross organizations.
     * The OUTCOME is safe three times over. But that test was green for a
     * reason other than the one its name gives.
     *
     * This is the case that separates them. When BOTH organizations have the
     * serial, a confined lookup finds A1's one job and attaches; an unconfined
     * one finds two, calls it ambiguous and holds a report that should have
     * landed. Sabotaging the scope fails here and only here.
     */
    public function test_a_serial_shared_with_another_organization_still_lands_on_a1s_own_job(): void
    {
        $a1 = $this->provisionTenant('A1-Group');
        $other = $this->provisionTenant('Somebody Else');

        $ours = $this->jobWithSerial($a1, '200022572');
        $this->jobWithSerial($other, '200022572');

        $this->writeManifest([$this->entry(90, '200022572')]);

        Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir]);

        $this->assertSame(
            1,
            $this->attachmentsOn($a1, $ours),
            'A1\'s own job, found by a lookup that never saw the other organization',
        );
    }

    /**
     * A file that does not match its manifest checksum writes nothing.
     *
     * A truncated copy archived as the record would be found the day a
     * customer asked for it. The job exists and is valid, so a pass means the
     * integrity check refused, not that there was nowhere to attach.
     */
    public function test_a_corrupt_file_writes_nothing(): void
    {
        $a1 = $this->provisionTenant('A1-Group');
        $job = $this->jobWithSerial($a1, '200022572');

        $entry = $this->entry(90, '200022572');
        $entry['sha256'] = str_repeat('0', 64);
        $this->writeManifest([$entry]);

        $status = Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir]);

        $this->assertSame(0, $this->attachmentsOn($a1, $job), 'a checksum mismatch must not attach');
        $this->assertFalse(
            Storage::disk(app(FileStore::class)->defaultDisk())->exists(
                "a1-inspection-reports/{$a1['org']->id}/inspection-report-90.pdf",
            ),
            'nor archive the corrupt copy as the record',
        );
        $this->assertSame(1, $status, 'and the run must say it failed');
    }

    /** Running it twice attaches nothing the second time. */
    public function test_a_second_run_attaches_nothing_new(): void
    {
        $a1 = $this->provisionTenant('A1-Group');
        $job = $this->jobWithSerial($a1, '200022572');
        $this->writeManifest([$this->entry(90, '200022572')]);

        Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir]);
        Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir]);

        $this->assertSame(1, $this->attachmentsOn($a1, $job));
    }

    /** A dry run touches nothing, attachment or archive. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $a1 = $this->provisionTenant('A1-Group');
        $job = $this->jobWithSerial($a1, '200022572');
        $this->writeManifest([$this->entry(90, '200022572')]);

        Artisan::call('inspection:import-a1-reports', ['dir' => $this->dir, '--dry-run' => true]);

        $this->assertSame(0, $this->attachmentsOn($a1, $job));
        $this->assertSame([], Storage::disk(app(FileStore::class)->defaultDisk())->allFiles());
    }

    // ── fixtures ─────────────────────────────────────────────────

    /**
     * One manifest entry in the shape the Dismantler pasted verbatim for
     * report 90, including the export's `workiz_job_uuid: null`.
     *
     * @return array<string, mixed>
     */
    private function entry(int $id, ?string $serial, string $source = 'archived_pdf'): array
    {
        $pdf = "inspection-report-{$id}.pdf";
        $bytes = "%PDF-1.4 A1 report {$id}\n%%EOF\n";
        file_put_contents($this->dir.'/'.$pdf, $bytes);

        return [
            'a1_report_id' => $id,
            'a1_estimate_ids' => [2408],
            'a1_client_id' => 36626,
            'a1_client_ids' => [36626],
            'report_crm_client_id' => 36626,
            'a1_job_id' => $serial === null ? null : 18163,
            'workiz_job_serial' => $serial,
            'workiz_job_uuid' => null,
            'inspection_date' => '2026-08-05',
            'created_at' => '2026-08-05T16:19:36Z',
            'submitted_at' => '2026-08-05T16:19:38Z',
            'pdf_file' => $pdf,
            'source' => $source,
            'sha256' => hash('sha256', $bytes),
            'bytes' => strlen($bytes),
            'pages' => 3,
            'images' => null,
        ];
    }

    /**
     * v2 adds top-level `manifest_version` and `pdfs_written_at`, which the
     * importer must ignore rather than trip on.
     *
     * @param  list<array<string, mixed>>  $reports
     */
    private function writeManifest(array $reports, int $version = 1): void
    {
        $manifest = ['reports' => $reports];

        if ($version >= 2) {
            $manifest = ['manifest_version' => 2, 'pdfs_written_at' => '2026-09-29T18:00:00Z'] + $manifest;
        }

        file_put_contents($this->dir.'/manifest.json', json_encode($manifest));
    }

    private function jobWithSerial(array $tenant, string $serial, ?string $uuid = null): Job
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $serial, $uuid) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => '14 Maple',
            ]);

            $job = Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'Inspection',
                'status' => 'completed',
            ]);

            $uuid ??= strtoupper(bin2hex(random_bytes(3)));

            WorkizJob::create([
                'franchise_id' => $tenant['franchise']->id,
                'remote_id' => $uuid,
                'serial_id' => (int) $serial,
                'job_id' => $job->id,
                // The shape the projection stores: Workiz's own answer.
                'payload' => ['UUID' => $uuid, 'SerialId' => (int) $serial],
                'synced_at' => now(),
            ]);

            return $job;
        });
    }

    private function attachmentsOn(array $tenant, Job $job): int
    {
        return $this->inTenant($tenant['org'], fn () => Attachment::query()
            ->where('attachable_type', $job->getMorphClass())
            ->where('attachable_id', $job->id)
            ->count());
    }

    /** @return array{0: int, 1: int} */
    private function counts(array $tenant): array
    {
        return $this->inTenant($tenant['org'], fn () => [Job::query()->count(), Customer::query()->count()]);
    }
}
