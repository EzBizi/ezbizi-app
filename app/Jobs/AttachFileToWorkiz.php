<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizMirror;
use App\Support\Workiz\WorkizWebSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One EzBizi file (an inspection PDF, a before/after photo) attached to the
 * job's Workiz copy (Jose, Monday list 2026-11-03). Neither Workiz API takes
 * files, so it goes through the company's Workiz web login, as Workiz's own
 * job page does: POST /ajaxc/files/uploadDocFiles/{numeric job id}/ with
 * `the_file` and docType=job (measured 2026-11-03 on the NY test job).
 *
 * The file is EzBizi's first: this runs after it is saved, and a refusal is
 * audited as `workiz.attach_failed`, never thrown back. A refusal Workiz may
 * lift (the login busy, the job not mirrored yet) is retried after 1, 5, 15
 * and 60 minutes, as MirrorToWorkiz is.
 */
final class AttachFileToWorkiz implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public const BACKOFF = [1, 5, 15, 60];

    private const NOT_MIRRORED = 'This job is not in Workiz yet.';

    public function __construct(
        public readonly string $organizationId,
        public readonly string $jobId,
        public readonly string $disk,
        public readonly string $path,
        public readonly ?string $name = null,
        public readonly int $attempt = 1,
    ) {}

    /** The one call for a module: queue $path on $disk for $job's Workiz copy. */
    public static function for(Job $job, string $disk, string $path, ?string $name = null): void
    {
        if (WorkizMirror::connection($job->franchise_id) !== null) {
            self::dispatch($job->organization_id, $job->getKey(), $disk, $path, $name);
        }
    }

    public function handle(TenantContext $tenant, AuditLogger $audit): void
    {
        $organization = Organization::query()->withoutGlobalScopes()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $tenant->run($organization, function () use ($audit) {
            $job = Job::query()->find($this->jobId);

            // Gone, or the branch has since stopped mirroring or lost Workiz.
            if ($job === null || WorkizMirror::connection($job->franchise_id) === null) {
                return;
            }

            try {
                $refused = $this->attach($job);
            } catch (Throwable $e) {
                report($e);
                $refused = 'EzBizi could not attach this file in Workiz.';
            }

            if ($refused === null) {
                return;
            }

            if (in_array($refused, [WorkizWebSession::BUSY, self::NOT_MIRRORED], true) && $this->attempt <= count(self::BACKOFF)) {
                self::dispatch($this->organizationId, $this->jobId, $this->disk, $this->path, $this->name, $this->attempt + 1)
                    ->delay(now()->addMinutes(self::BACKOFF[$this->attempt - 1]));

                return;
            }

            $audit->log('workiz.attach_failed', $job, ['file' => $this->fileName(), 'refused' => $refused, 'attempts' => $this->attempt]);
        });
    }

    /** Workiz's refusal, or null when the file is on the Workiz job. */
    private function attach(Job $job): ?string
    {
        $mirror = WorkizJob::query()->where('job_id', $job->getKey())->first(['id', 'organization_id', 'remote_id']);

        if ($mirror === null) {
            return self::NOT_MIRRORED;
        }

        $login = SocialConnection::query()->where('provider', SocialProvider::WorkizWeb->value)->where('status', 'connected')->first();

        if ($login === null) {
            return 'No Workiz web login is connected, so files cannot reach Workiz.';
        }

        // ponytail: one accounts() read per file; cache per login if photo bursts make it slow.
        $account = collect(WorkizWebSession::accounts($login))
            ->search(fn ($name, $a) => WorkizWebSession::franchiseFor($login, (string) $a, $name)?->getKey() === $job->franchise_id);
        $account = $account === false ? null : $account;

        if ($account === null) {
            return 'No Workiz account of the web login is this branch\'s.';
        }

        $disk = Storage::disk($this->disk);
        $file = ['name' => $this->fileName(), 'bytes' => $disk->get($this->path), 'mime' => $disk->mimeType($this->path) ?: 'application/octet-stream'];

        $sent = WorkizWebSession::inAccount($login, (string) $account, function () use ($login, $mirror, $file) {
            $got = WorkizWebSession::call($login, '/ajaxc/job/get/'.rawurlencode($mirror->remote_id).'/');
            $id = ((array) ($got['data']['data'] ?? $got['data'] ?? []))['id'] ?? null;

            if ($got['refused'] !== null || ! is_numeric($id)) {
                return ['data' => null, 'refused' => $got['refused'] ?? 'Workiz did not find this job.'];
            }

            return WorkizWebSession::call($login, '/ajaxc/files/uploadDocFiles/'.$id.'/', ['docType' => 'job'], '', $file);
        });

        if ($sent['refused'] !== null) {
            return $sent['refused'];
        }

        return ($sent['data']['flag'] ?? null) === true ? null : (string) ($sent['data']['msg'] ?? 'Workiz did not take the file.');
    }

    private function fileName(): string
    {
        return $this->name ?? basename($this->path);
    }
}
