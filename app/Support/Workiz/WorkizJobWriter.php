<?php

namespace App\Support\Workiz;

use App\Console\Commands\ProjectWorkizRecords;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Change a Workiz job from EzBizi: reschedule it, assign or unassign a
 * technician, or make one for a job EzBizi booked. Jose, 2026-09-25, on
 * dispatching: "it should be possible on both with the sync". Workiz stays the
 * truth; this writes TO it.
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
     * Make a job EzBizi booked in its branch's Workiz too, and link the two in
     * the same moment. The first caller is a member's confirmed visit
     * (Memberships, 2026-10-05; JobType per plan, Jose: "Type per plan").
     * Type and source are sent only when given, and must be in the branch's
     * Workiz lists.
     *
     * ── WHY THE LINK IS MADE HERE, UNDER THE PROJECTION'S LOCK ──────
     *
     * The minute sweep will land the new Workiz job, and the projection makes
     * an EzBizi job, and a customer, for every mirrored job and client nobody
     * claims. So the mirror row naming THIS job, and the member's Workiz client
     * key, are written before the lock is let go -- or the visit shows twice.
     * A1 Tools found the same: "Every A1-created job would have silently
     * doubled". Once linked it follows Workiz like any Workiz job: status,
     * schedule, price and technician.
     *
     * The row is landed from Workiz's answer (UUID, SerialId, ClientId) and the
     * fields it accepted; the sweep replaces it with Workiz's whole job.
     *
     * ── MEASURED ────────────────────────────────────────────────────
     *
     * On the NY test client 1196 (job Y7TCXI, made and cancelled 2026-10-05
     * with Jose's go-ahead), and by A1 Tools on 2026-07-22 (DETL68):
     *
     *  - The answer is 201 with `data` a LIST of one {UUID, SerialId, ClientId}.
     *  - `ClientId` files the job under that client; no second one is made.
     *    The contact fields land on the new job only: the client's other jobs
     *    kept theirs.
     *  - Times come back exactly as sent: Workiz's own wall clock.
     *  - JobSource is checked against the branch's own list: "Membership",
     *    in none, was refused ("Could not find matching value for JobSource").
     *    Left out, it is accepted and blank. JobType is a list too.
     *  - It arrives Submitted, with NO service area, so dispatch sets one
     *    before a technician can be assigned; CreatedBy is the token's user.
     *  - `Comments` is refused (A1 Tools). Workiz has no client endpoint: the
     *    person rides on the job.
     *
     * Both null when the branch has no Workiz: there is nothing to enter.
     *
     * @return array{job: ?WorkizJob, refused: ?string}
     */
    public function create(Job $job, ?string $jobType, ?string $jobSource = null): array
    {
        $connection = $this->connection($job->franchise_id);

        if ($connection === null) {
            return ['job' => null, 'refused' => null];
        }

        $customer = $job->customer;
        $name = trim((string) $customer->name);
        $cut = strrpos($name, ' ');
        $start = $job->getRawOriginal('scheduled_for');

        $body = array_filter([
            // ponytail: the newest of the member's Workiz records in this
            // branch; when Workiz holds duplicates of a person, any one files
            // the visit under them rather than minting another.
            'ClientId' => WorkizClient::query()->where('franchise_id', $job->franchise_id)
                ->where('customer_id', $job->customer_id)->max('client_id'),
            'FirstName' => $cut === false ? $name : substr($name, 0, $cut),
            'LastName' => $cut === false ? null : substr($name, $cut + 1),
            // E.164 here; ten national digits on every captured Workiz payload.
            'Phone' => preg_replace('/^\+1(?=\d{10}$)/', '', (string) Customer::realPhone($customer->phone)),
            'Email' => Customer::realEmail($customer->email),
            'Address' => $customer->address_line1,
            'Unit' => $customer->address_line2,
            'City' => $customer->city,
            'State' => $customer->state,
            'PostalCode' => $customer->postal_code,
            // Workiz's own wall clock, which is what the cast stores.
            'JobDateTime' => $start,
            'JobEndDateTime' => $start !== null && $job->duration_minutes
                ? Carbon::parse($start)->addMinutes($job->duration_minutes)->format('Y-m-d H:i:s') : null,
            'JobType' => $jobType,
            'JobSource' => $jobSource,
        ], fn ($value) => $value !== null && $value !== '');

        $lock = Cache::lock(ProjectWorkizRecords::LOCK, 120);

        try {
            $lock->block(120);
        } catch (LockTimeoutException) {
            return ['job' => null, 'refused' => 'The Workiz sync is busy, so nothing was sent to Workiz.'];
        }

        try {
            // Twice is once: a retried call finds the job it already made.
            $made = WorkizJob::query()->where('job_id', $job->getKey())->first();

            if ($made !== null) {
                return ['job' => $made, 'refused' => null];
            }

            $sent = $this->workiz->send($connection, '/job/create/', $body);

            if ($sent['refused'] === WorkizJobs::UNANSWERED) {
                return ['job' => null, 'refused' => 'Workiz did not answer, so it may have made the job anyway: look for it in Workiz before entering it.'];
            }

            if ($sent['refused'] !== null) {
                return ['job' => null, 'refused' => $sent['refused']];
            }

            $answer = is_array($sent['data']) && array_is_list($sent['data']) ? ($sent['data'][0] ?? null) : $sent['data'];

            if (! is_array($answer) || ($answer['UUID'] ?? '') === '') {
                return ['job' => null, 'refused' => 'Workiz made the job but did not say which one, so EzBizi could not link it: find it in Workiz, and expect it twice in EzBizi.'];
            }

            $row = DB::transaction(function () use ($connection, $job, $body, $answer) {
                WorkizJob::land($connection, $answer + $body);

                $row = WorkizJob::query()->where('franchise_id', $job->franchise_id)->where('remote_id', $answer['UUID'])->firstOrFail();
                $row->forceFill(['job_id' => $job->getKey(), 'projected' => ProjectWorkizRecords::followedValues($job)])->save();

                // Ours unless EzBizi already knows that client as someone else.
                if ($row->client_id !== null) {
                    $key = WorkizClient::query()->firstOrNew(['franchise_id' => $job->franchise_id, 'client_id' => $row->client_id]);

                    if ($key->customer_id === null) {
                        $key->forceFill(['customer_id' => $job->customer_id, 'synced_at' => $key->synced_at ?? now()])->save();
                    }
                }

                return $row;
            });

            $this->audit->log('workiz.job_created', $row, metadata: [
                'job_id' => $job->getKey(), 'serial_id' => $row->serial_id, 'job_type' => $jobType, 'job_source' => $jobSource,
            ]);

            return ['job' => $row, 'refused' => null];
        } finally {
            $lock->release();
        }
    }

    private function connection(string $franchiseId): ?SocialConnection
    {
        return SocialConnection::query()
            ->where('provider', SocialProvider::Workiz->value)
            ->where('scope_type', SocialConnection::SCOPE_FRANCHISE)
            ->where('scope_id', $franchiseId)
            ->first();
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

        $connection = $this->connection($job->franchise_id);

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
