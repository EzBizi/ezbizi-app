<?php

namespace App\Support\Workiz;

use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use InvalidArgumentException;

/**
 * Change a Workiz job from EzBizi: reschedule it, assign or unassign a
 * technician. Jose, 2026-09-25, on dispatching: "it should be possible on both
 * with the sync". Workiz stays the truth; this writes TO it.
 *
 * ── MEASURED, NOT READ OFF THE SPEC ─────────────────────────────
 *
 * On the test job 99QWJ0 ("EzBizi API TEST", NY account), made with Jose's
 * approval on 2026-09-25:
 *
 *  - /job/update/ with JobDateTime moves only the START; the end stays put.
 *    So a reschedule always sends both, or a job's length changes silently.
 *  - /job/assign/ and /job/unassign/ take `User` = the technician's Workiz
 *    NAME. A1 Tools probed the id forms; Workiz refuses them all.
 *  - A technician can only be assigned to a job in a service area they cover:
 *    "Cannot assign Chaim Perez to job 99QWJ0, User is not assigned to select
 *    service area." That sentence comes back to the caller as it is.
 *  - No write answers with the job. So each write is followed by a read, and
 *    the answer is landed through the sweep's own path: the caller gets
 *    Workiz's actual job back, not a guess at it.
 *
 * Every accepted write leaves one audit row, whoever the caller is.
 */
final class WorkizJobWriter
{
    public function __construct(private WorkizJobs $workiz, private AuditLogger $audit) {}

    /**
     * Both times as Workiz holds them: naive wall-clock 'Y-m-d H:i:s' in the
     * job's own zone. A Carbon or an ISO stamp could arrive hours off, so
     * nothing else passes.
     *
     * @return array{job: ?WorkizJob, refused: ?string}
     */
    public function reschedule(WorkizJob $job, string $start, string $end): array
    {
        foreach ([$start, $end] as $at) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at) !== 1) {
                throw new InvalidArgumentException('Workiz times are its own wall clock, Y-m-d H:i:s: '.$at);
            }
        }

        return $this->write($job, '/job/update/', ['JobDateTime' => $start, 'JobEndDateTime' => $end], 'workiz.job_rescheduled');
    }

    /** @return array{job: ?WorkizJob, refused: ?string} */
    public function assign(WorkizJob $job, WorkizTeamMember $member): array
    {
        return $this->write($job, '/job/assign/', ['User' => $member->name], 'workiz.job_assigned', $member);
    }

    /** @return array{job: ?WorkizJob, refused: ?string} */
    public function unassign(WorkizJob $job, WorkizTeamMember $member): array
    {
        return $this->write($job, '/job/unassign/', ['User' => $member->name], 'workiz.job_unassigned', $member);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{job: ?WorkizJob, refused: ?string}
     */
    private function write(WorkizJob $job, string $path, array $fields, string $action, ?WorkizTeamMember $member = null): array
    {
        // Each branch is its own Workiz account: another branch's technician
        // is not a name this job's account knows.
        if ($member !== null && $member->franchise_id !== $job->franchise_id) {
            return ['job' => null, 'refused' => 'That technician works in another branch\'s Workiz account.'];
        }

        $connection = SocialConnection::query()
            ->where('provider', SocialProvider::Workiz->value)
            ->where('scope_type', SocialConnection::SCOPE_FRANCHISE)
            ->where('scope_id', $job->franchise_id)
            ->first();

        if ($connection === null) {
            return ['job' => null, 'refused' => 'This branch has no Workiz connection.'];
        }

        $refused = $this->workiz->post($connection, $path, ['UUID' => (string) $job->remote_id] + $fields);

        if ($refused !== null) {
            return ['job' => null, 'refused' => $refused];
        }

        $this->audit->log($action, $job, metadata: $fields);

        // A failed read-back leaves the row as it was; the sweep catches up.
        $read = $this->workiz->find($connection, (string) $job->remote_id);

        if ($read['job'] !== null) {
            WorkizJob::land($connection, $read['job']);
        }

        return ['job' => $job->fresh(), 'refused' => null];
    }
}
