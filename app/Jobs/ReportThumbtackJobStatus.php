<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\ThumbtackJobStatus;
use App\Support\Social\ThumbtackNegotiations;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Tell Thumbtack what happened to work that came through them.
 *
 * Thumbtack made this part of the integration and EzBizi's own Help Center
 * article — the one they published — says we do it. It went unwired until
 * 2026-09-09 because the other half did not exist: nothing turned a lead into
 * a job until booking shipped in 0.0.0163.
 *
 * ── Queued, and why it cannot be inline ──────────────────────────
 *
 * The status change happens inside `DB::transaction` in JobsController. A
 * network call in there holds the transaction open for the length of somebody
 * else's API, and worse, a Thumbtack failure would roll back a job update the
 * person already saw succeed. What EzBizi records about its own work must not
 * depend on whether a third party answered.
 *
 * Dispatched `afterCommit()` explicitly, because `after_commit` is false for
 * every queue connection here — without it the worker can pick the job up
 * before the row it reads is committed and report the OLD status.
 *
 * ── tries = 3, unlike the publisher ──────────────────────────────
 *
 * PublishSocialTarget uses one try because a retry could post twice to a real
 * audience. This is the opposite shape: `job-status` is idempotent — the same
 * status with the same `partnerStatusChangedAt` is the same fact stated twice —
 * and the cost of NOT retrying is a Pro's completion record quietly disagreeing
 * with reality. Repeating a true statement is cheap; failing to make it is not.
 */
final class ReportThumbtackJobStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 300];

    /**
     * @param  string  $changedAt  when the status actually changed, ISO-8601.
     *                             Carried rather than re-read: Thumbtack asks
     *                             for the moment it happened, and by the time a
     *                             retry runs an hour later `now()` would be a
     *                             different and untrue answer.
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $organizationId,
        public readonly string $changedAt,
    ) {}

    public function handle(TenantContext $tenant): void
    {
        $organization = Organization::query()->withoutGlobalScopes()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $tenant->run($organization, function () {
            $job = Job::query()->find($this->jobId);

            if ($job === null) {
                return; // Deleted while this waited.
            }

            $lead = ThumbtackJobStatus::leadFor($job);

            if ($lead === null) {
                return; // Not a Thumbtack job. The common case.
            }

            $status = ThumbtackJobStatus::forJob($job);

            if ($status === null) {
                // Unmapped, or mapped to something that is not theirs. Silence
                // is the configured answer, not a failure — see
                // ThumbtackJobStatus for why the default map is empty.
                return;
            }

            $connection = SocialConnection::query()->find($lead->connection_id);

            if ($connection === null) {
                // The account was disconnected after the lead arrived. The
                // enquiry is still ours to work; there is simply nobody left to
                // report it to, and no token to do it with.
                return;
            }

            try {
                ThumbtackNegotiations::for($connection)->setJobStatus(
                    $lead->remote_id,
                    $status,
                    new \DateTimeImmutable($this->changedAt),
                    ThumbtackJobStatus::invoiceCentsFor($job, $status),
                );
            } catch (RuntimeException $e) {
                /*
                 * A negotiation Thumbtack no longer has is final, and retrying
                 * it three times to fail three times only delays the log line.
                 * Everything else — an expired token, their outage — is worth
                 * another attempt, so it is re-thrown for the queue.
                 */
                if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                    Log::warning('Thumbtack no longer has that enquiry, so its job status was not reported.', [
                        'lead'   => $lead->getKey(),
                        'job'    => $job->getKey(),
                        'status' => $status,
                    ]);

                    return;
                }

                throw $e;
            }
        });
    }
}
