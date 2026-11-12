<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\Organization;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizJobWriter;
use App\Support\Workiz\WorkizMirror;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * One EzBizi change written to the job's Workiz copy (WorkizMirror). The
 * worker runs with --tries=1, so a retry is a re-dispatch: a refusal Workiz
 * may lift later (the sync holding its lock, a rate limit, no answer) is
 * tried again after 1, 5, 15 and 60 minutes; anything else, and the last
 * try, is recorded as `workiz.mirror_failed` with Workiz's own words. It
 * never reaches the failed-jobs table.
 */
final class MirrorToWorkiz implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Minutes before each retry. */
    public const BACKOFF = [1, 5, 15, 60];

    public function __construct(
        public readonly string $organizationId,
        public readonly string $jobId,
        public readonly string $op,
        public readonly array $args = [],
        public readonly int $attempt = 1,
    ) {}

    /** A booking the sync lock turned away: retried on BACKOFF, `workiz.mirror_failed` if it never lands (Desk, 11-12). */
    public static function later(Job $job, ?string $jobType, ?string $jobSource): string
    {
        self::dispatch($job->organization_id, $job->getKey(), 'create', ['job_type' => $jobType, 'job_source' => $jobSource])
            ->delay(now()->addMinutes(self::BACKOFF[0]));

        return 'The Workiz sync is busy: EzBizi will send job '.$job->number.' to Workiz in a minute, and flags it if Workiz never takes it.';
    }

    public function handle(TenantContext $tenant, WorkizJobWriter $writer, AuditLogger $audit): void
    {
        $organization = Organization::query()->withoutGlobalScopes()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $tenant->run($organization, function () use ($writer, $audit) {
            $job = Job::query()->with('customer')->find($this->jobId);

            // Gone, or the branch has since stopped mirroring or lost Workiz: nothing to write.
            if ($job === null || WorkizMirror::connection($job->franchise_id) === null) {
                return;
            }

            try {
                $refused = $this->write($writer, $job);
            } catch (RuntimeException $e) {
                $refused = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $refused = 'EzBizi could not write this to Workiz.';
            }

            if ($refused === null) {
                return;
            }

            $passing = $refused === WorkizJobWriter::BUSY || str_contains(strtolower($refused), 'rate limit')
                || str_contains($refused, 'did not answer');

            if ($passing && $this->attempt <= count(self::BACKOFF)) {
                self::dispatch($this->organizationId, $this->jobId, $this->op, $this->args, $this->attempt + 1)
                    ->delay(now()->addMinutes(self::BACKOFF[$this->attempt - 1]));

                return;
            }

            $audit->log('workiz.mirror_failed', $job, ['step' => $this->op, 'refused' => $refused, 'attempts' => $this->attempt]);
        });
    }

    /** Workiz's refusal, or null when it took the change. */
    private function write(WorkizJobWriter $writer, Job $job): ?string
    {
        $mirror = WorkizJob::query()->where('job_id', $job->getKey())->first();

        if ($this->op === 'create') {
            return $mirror !== null ? null
                : $writer->create($job, $this->args['job_type'] ?? $job->job_type, $this->args['job_source'] ?? $job->job_source)['refused'];
        }

        // Not in Workiz (yet): there is nothing there to change.
        if ($mirror === null) {
            return null;
        }

        $member = fn () => WorkizTeamMember::query()->find($this->args['workiz_member_id'] ?? null)
            ?? throw new RuntimeException('That technician has no Workiz account in this branch.');

        return match ($this->op) {
            'status' => [$writer->setStatus($mirror, (string) $this->args['status'], $this->args['sub_status'] ?? null), null][1],
            'tags' => [$writer->setTags($mirror, (array) ($this->args['tags'] ?? [])), null][1],
            'unschedule' => [$writer->unschedule($mirror), null][1],
            'reschedule' => $writer->reschedule($mirror, (string) $this->args['start'], (string) $this->args['end'])['refused'],
            'assign' => $writer->assign($mirror, $member())['refused'],
            'unassign' => $writer->unassign($mirror, $member())['refused'],
            'fields' => [$writer->setFields($mirror, $job), null][1],
        };
    }
}
