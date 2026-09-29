<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkizJob;
use App\Support\Files\AttachmentUploader;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Bring A1's filed inspection reports into EzBizi before A1 is shut down.
 *
 * ── What these are, and what they are not ───────────────────────
 *
 * A1's own rendered PDFs, exported by the A1 Tools Dismantler. They are NOT
 * EzBizi inspection reports and this does not turn them into InspectionReport
 * rows: a native report carries a definition, a frozen version and structured
 * answers, and wrapping a PDF in invented structure would manufacture records
 * that look native and are not. They land as the files they are.
 *
 * ── Two steps per report, and the first is the one that matters ──
 *
 *   1. ARCHIVE. Every report is copied into EzBizi's own private storage,
 *      beside the manifest. Once that copy exists nothing can lose it — not A1
 *      shutting down, not the staging directory being cleaned — whatever anybody
 *      later decides about where it belongs. This runs for every report,
 *      attached or not.
 *
 *   2. ATTACH, where there is somewhere honest to attach it: the report's job.
 *      Jobs take attachments and customers do not, so a customer match alone is
 *      not a landing place.
 *
 * A report with no job is HELD in the archive and named in the output. It is
 * never attached to something invented to receive it: EzBizi has no customer
 * merge, so a parent minted here would be a permanent duplicate rather than a
 * to-do.
 *
 * ── The join ────────────────────────────────────────────────────
 *
 * `workiz_jobs.remote_id` is the Workiz job UUID and `workiz_jobs.serial_id`
 * the serial (measured on the box: all 14,412 remote_ids are the 6-character
 * UUID). The UUID is preferred and the serial is the fallback — the first
 * export of this manifest carried `workiz_job_uuid: null` for every report, a
 * bug in the export rather than in A1, and a parser that trusted only the UUID
 * would have held all eight for no reason.
 *
 * EXACTLY ONE match or it holds. Zero is "not in EzBizi yet". More than one is
 * ambiguous, and attaching a customer's inspection to the wrong job is worse
 * than not attaching it. When both keys are present they must name the SAME
 * job; if they disagree the manifest is wrong about something and a person
 * should look.
 *
 * ── Integrity before anything is written ────────────────────────
 *
 * Each file's sha256 is checked against the manifest first. A truncated or
 * swapped copy fails loudly and writes nothing for that report — a corrupt PDF
 * archived as the record would be discovered the day a customer asked for it.
 *
 * Idempotent: a report already attached to its job (same checksum) is left
 * alone, and an archive copy already in place is not rewritten.
 */
class InspectionImportA1Reports extends Command
{
    protected $signature = 'inspection:import-a1-reports
                            {dir : The directory holding manifest.json and the PDFs}
                            {--dry-run : Say what would happen and write nothing}';

    protected $description = "Archive A1's filed inspection reports into EzBizi and attach each to its job";

    /** These are A1's reports; they belong to A1-Group and nowhere else. */
    private const ORG_NAME = 'A1-Group';

    public function handle(TenantContext $tenant, FileStore $files, AttachmentUploader $uploader): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/\\');
        $manifestPath = $dir.DIRECTORY_SEPARATOR.'manifest.json';

        if (! is_file($manifestPath)) {
            $this->error("No manifest.json in {$dir}.");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $reports = is_array($manifest['reports'] ?? null) ? $manifest['reports'] : null;

        if ($reports === null || $reports === []) {
            $this->error('manifest.json has no `reports` array — refusing rather than reporting success on nothing.');

            return self::FAILURE;
        }

        $orgs = Organization::query()->where('name', self::ORG_NAME)->get();

        if ($orgs->count() !== 1) {
            $this->error('Expected exactly one organization named '.self::ORG_NAME.', found '.$orgs->count().'.');

            return self::FAILURE;
        }

        $org = $orgs->first();
        $dry = (bool) $this->option('dry-run');

        $tally = ['attached' => 0, 'already' => 0, 'held' => 0, 'failed' => 0];

        $outcome = $tenant->run($org, function () use ($reports, $dir, $org, $files, $uploader, $dry, &$tally, $manifestPath): int {
            $actor = User::query()
                ->whereHas('role', fn ($q) => $q->where('is_administrator', true))
                ->orderBy('created_at')
                ->first();

            if ($actor === null) {
                $this->error('No administrator in '.self::ORG_NAME.' to record these under.');

                return self::FAILURE;
            }

            $archiveDir = $files->directoryFor('a1-inspection-reports', $org->id);

            if (! $dry) {
                // The manifest travels with the files, so the provenance of every
                // archived report stays readable after the staging directory and
                // A1 are both gone.
                Storage::disk($files->defaultDisk())->put(
                    $archiveDir.'/manifest.json',
                    (string) file_get_contents($manifestPath),
                );
            }

            foreach ($reports as $report) {
                $this->processOne($report, $dir, $archiveDir, $files, $uploader, $actor, $dry, $tally);
            }

            return self::SUCCESS;
        });

        if ($outcome !== self::SUCCESS) {
            return $outcome;
        }

        $this->newLine();
        $this->line(sprintf(
            '%sattached %d, already attached %d, held %d, failed %d.',
            $dry ? '[dry run] ' : '',
            $tally['attached'],
            $tally['already'],
            $tally['held'],
            $tally['failed'],
        ));

        return $tally['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, int>  $tally
     */
    private function processOne(
        array $report,
        string $dir,
        string $archiveDir,
        FileStore $files,
        AttachmentUploader $uploader,
        User $actor,
        bool $dry,
        array &$tally,
    ): void {
        $id = (string) ($report['a1_report_id'] ?? '?');
        $pdf = basename((string) ($report['pdf_file'] ?? ''));
        $path = $dir.DIRECTORY_SEPARATOR.$pdf;
        $expected = strtolower((string) ($report['sha256'] ?? ''));

        if ($pdf === '' || ! is_file($path)) {
            $this->error("  report {$id}: file {$pdf} is missing.");
            $tally['failed']++;

            return;
        }

        $actual = hash_file('sha256', $path);

        if ($expected === '' || ! hash_equals($expected, $actual)) {
            $this->error("  report {$id}: sha256 does not match the manifest — nothing written for it.");
            $tally['failed']++;

            return;
        }

        $reconstructed = ($report['source'] ?? '') === 'rendered_mpdf';
        $name = sprintf('A1 inspection report %s%s.pdf', $id, $reconstructed ? ' (reconstructed)' : '');

        // 1. ARCHIVE — always, and first.
        //
        // A DETERMINISTIC path on the house disk, not FileStore::putContents,
        // which names every file at random. A random name would make each re-run
        // write another copy and make "is it already archived" unanswerable; the
        // file named by A1's own report id is both stable and self-describing.
        $archivePath = $archiveDir.'/'.$pdf;
        if (! $dry && ! $files->exists($archivePath)) {
            Storage::disk($files->defaultDisk())->put($archivePath, (string) file_get_contents($path));
        }

        // 2. ATTACH — only to a job that exists, exactly once.
        [$job, $why] = $this->jobFor($report);

        if ($job === null) {
            $this->warn("  report {$id}: held in the archive — {$why}.");
            $tally['held']++;

            return;
        }

        if ($job->attachments()->where('checksum', $actual)->exists()) {
            $this->line("  report {$id}: already on job {$job->number}.");
            $tally['already']++;

            return;
        }

        if (! $dry) {
            $uploader->upload($actor, $job, new UploadedFile($path, $name, 'application/pdf', null, true));
        }

        $this->info("  report {$id}: ".($dry ? 'would attach' : 'attached').' to job '.$job->number.'.');
        $tally['attached']++;
    }

    /**
     * The one job this report belongs to, or null and the reason.
     *
     * @param  array<string, mixed>  $report
     * @return array{0: Job|null, 1: string}
     */
    private function jobFor(array $report): array
    {
        $uuid = trim((string) ($report['workiz_job_uuid'] ?? ''));
        $serial = trim((string) ($report['workiz_job_serial'] ?? ''));

        if ($uuid === '' && $serial === '') {
            return [null, 'A1 has no job for it'];
        }

        $byUuid = $uuid === '' ? null : WorkizJob::query()->where('remote_id', $uuid)->pluck('job_id');
        $bySerial = $serial === '' ? null : WorkizJob::query()->where('serial_id', (int) $serial)->pluck('job_id');

        foreach (['UUID '.$uuid => $byUuid, 'serial '.$serial => $bySerial] as $label => $ids) {
            if ($ids !== null && $ids->unique()->count() > 1) {
                return [null, "Workiz {$label} matches more than one job, so it is ambiguous"];
            }
        }

        $a = $byUuid?->first();
        $b = $bySerial?->first();

        if ($a !== null && $b !== null && $a !== $b) {
            return [null, 'its Workiz UUID and serial name two different jobs'];
        }

        $jobId = $a ?? $b;

        if ($jobId === null) {
            return [null, 'its Workiz job is not in EzBizi yet'];
        }

        $job = Job::query()->find($jobId);

        return $job === null ? [null, 'its Workiz job points at a job that no longer exists'] : [$job, ''];
    }
}
