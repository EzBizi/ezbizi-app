<?php

namespace App\Support\Workiz;

use App\Console\Commands\ProjectWorkizRecords;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Models\WorkizTeamMember;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

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
    /** The one refusal that means "nothing happened, try again": a caller may retry it rather than hand it to a person. */
    public const BUSY = 'The Workiz sync is busy, so nothing was sent to Workiz.';

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

        // A queued caller runs under the worker's default 60-second timeout
        // (production: queue:work with no --timeout, --tries=1), and a job
        // killed there leaves no refusal for anyone to act on. So the wait plus
        // Workiz's own 30 seconds stays inside it, and the hold expires soon
        // after a killed run rather than keeping the projection off.
        $lock = Cache::lock(ProjectWorkizRecords::LOCK, 60);

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            return ['job' => null, 'refused' => self::BUSY];
        }

        try {
            // Twice is once: a retried call finds the job it already made.
            $made = WorkizJob::query()->where('job_id', $job->getKey())->first();

            if ($made !== null) {
                return ['job' => $made, 'refused' => null];
            }

            $sent = WorkizV2::uses($connection)
                ? $this->createV2($connection, $job, $body, $jobType, $jobSource)
                : $this->workiz->send($connection, '/job/create/', $body);

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

    /**
     * Add one line to the end of a Workiz job's description, once: when the
     * description already holds `$marker` nothing is written, so a retried rule
     * cannot add it twice. First caller: a finished inspection's report link
     * (Jose via Inspection, 2026-10-06) -- no Workiz API, v1 or v2, takes a
     * file, so the link is what goes up.
     *
     * v2 only: v1 has no description write. A v2 description is plain text,
     * newlines and no markup (measured on 40 DMV jobs, 2026-10-06), and a
     * write replaces the whole of it, so it is read and written under a lock.
     *
     * @return array{job: ?WorkizJob, refused: ?string, written: bool}
     */
    public function appendLine(WorkizJob $job, string $line, string $marker): array
    {
        $connection = $this->connection($job->franchise_id);
        $id = $job->payload['v2']['id'] ?? null;

        $refused = match (true) {
            $connection === null => 'This branch has no Workiz connection.',
            ! WorkizV2::uses($connection) => 'This branch is not on its Workiz v2 key.',
            ! is_string($id) || $id === '' => 'EzBizi has not read this job through Workiz v2 yet; try again after the next sync.',
            default => null,
        };

        if ($refused !== null) {
            return ['job' => null, 'refused' => $refused, 'written' => false];
        }

        $lock = Cache::lock('workiz-description:'.$id, 30);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            return ['job' => null, 'refused' => self::BUSY, 'written' => false];
        }

        try {
            $read = WorkizV2::send($connection, 'GET', '/jobs/'.rawurlencode($id));

            if ($read['refused'] !== null) {
                return ['job' => null, 'refused' => $read['refused'], 'written' => false];
            }

            $current = $read['data']['data'] ?? $read['data'];
            $current = rtrim((string) ((is_array($current) && array_is_list($current) ? ($current[0] ?? []) : $current)['description'] ?? ''));

            if (str_contains($current, $marker)) {
                return ['job' => $job, 'refused' => null, 'written' => false];
            }

            $sent = WorkizV2::send($connection, 'PUT', '/jobs/'.rawurlencode($id), ['description' => $current === '' ? $line : $current."\n".$line]);

            if ($sent['refused'] !== null) {
                return ['job' => null, 'refused' => $sent['refused'], 'written' => false];
            }

            // Not the line itself: it can carry a link that opens without signing in.
            $this->audit->log('workiz.job_description_added', $job, metadata: ['characters' => mb_strlen($line)]);

            // An update answers with the whole job (writeV2).
            if (is_array($sent['data'])) {
                WorkizJob::land($connection, WorkizV2::job($sent['data']));
            }

            return ['job' => $job->fresh(), 'refused' => null, 'written' => true];
        } finally {
            $lock->release();
        }
    }

    /**
     * Put a job in one of the branch's Workiz service areas, by name. A create
     * sets none (measured 2026-10-05); v1's /job/update/ takes `ServiceArea`.
     * v2 lists no service areas (GET /serviceareas, /serviceAreas and
     * /service-areas all 404, 2026-10-27), so a v2 branch writes this through
     * the v1 pair it keeps under credentials['v1'].
     *
     * @return array{job: ?WorkizJob, refused: ?string}
     */
    public function setServiceArea(WorkizJob $job, string $area): array
    {
        $connection = $this->connection($job->franchise_id);

        if ($connection === null) {
            return ['job' => null, 'refused' => 'This branch has no Workiz connection.'];
        }

        $v1 = $connection->credentials['v1'] ?? null;
        // An unsaved copy holding the kept v1 pair (FillWorkizCreators). Never saved.
        $through = ! WorkizV2::uses($connection) ? $connection
            : (is_array($v1) && ($v1['api_token'] ?? '') !== '' ? $connection->replicate()->forceFill(['credentials' => $v1]) : null);

        if ($through === null) {
            return ['job' => null, 'refused' => 'This branch keeps no Workiz v1 key, the only way EzBizi can set a service area.'];
        }

        // A request trims "Sacramento " to "Sacramento"; Workiz's own spelling goes back.
        $area = collect($this->areaNames($job->franchise_id, null))->first(fn (string $a) => trim($a) === trim($area)) ?? $area;
        $refused = $this->workiz->post($through, '/job/update/', ['UUID' => (string) $job->remote_id, 'ServiceArea' => $area]);

        if ($refused !== null) {
            return ['job' => null, 'refused' => $refused];
        }

        $this->audit->log('workiz.job_service_area_set', $job, metadata: ['service_area' => $area]);

        $read = $this->workiz->find($connection, (string) $job->remote_id);

        if ($read['job'] !== null) {
            WorkizJob::land($connection, $read['job']);
        }

        return ['job' => $job->fresh(), 'refused' => null];
    }

    /**
     * What booking a job into this branch's Workiz asks for (Leads ▸ Book,
     * Jose 2026-10-27): its job types and sources, from v2's own lists, and
     * its service areas, which no API lists, as named on the jobs EzBizi has
     * mirrored, those seen at `$zip` first. Null when the branch has no Workiz.
     *
     * @return array{job_types: list<string>, job_sources: list<string>, areas: list<string>, zip_areas: list<string>}|null
     */
    public function choices(string $franchiseId, ?string $zip): ?array
    {
        $connection = $this->connection($franchiseId);

        if ($connection === null) {
            return null;
        }

        $names = fn (string $list) => ! WorkizV2::uses($connection) ? [] : collect(
            WorkizV2::send($connection, 'GET', $list, ['pageSize' => 300, 'disabled' => 'false'])['data']['data'] ?? []
        )->filter(fn ($row) => is_array($row) && ! ($row['disabled'] ?? false) && trim((string) ($row['name'] ?? '')) !== '')
            ->pluck('name')->map(fn ($n) => (string) $n)->values()->all();

        $zip = substr((string) preg_replace('/\D/', '', (string) $zip), 0, 5);

        return [
            'job_types' => $names('/jobtypes'),
            'job_sources' => $names('/adgroups'),
            'areas' => $this->areaNames($franchiseId, null),
            'zip_areas' => strlen($zip) === 5 ? $this->areaNames($franchiseId, $zip) : [],
        ];
    }

    /**
     * A branch's service areas as named on its mirrored jobs, most used first,
     * exactly as Workiz holds them, trailing space and all ("Sacramento ").
     *
     * @return list<string>
     */
    private function areaNames(string $franchiseId, ?string $zip): array
    {
        return WorkizJob::query()->where('franchise_id', $franchiseId)
            ->when($zip !== null, fn ($q) => $q->where('postal_code', 'like', $zip.'%'))
            ->whereNotNull('payload->ServiceArea')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.ServiceArea')) as area, COUNT(*) as n")
            ->groupBy('area')->orderByDesc('n')->pluck('area')
            ->reject(fn ($a) => $a === null || trim((string) $a) === '' || $a === 'null')->values()->all();
    }

    /**
     * The branch's own Workiz statuses, as its account says them: the parents
     * of its mirrored sub-statuses and every status its mirrored jobs carry
     * (Jose, 2026-11-04: no business value in code; "done pending approval"
     * is A-1's own). Matched without case, so EzBizi's word goes up as Workiz spells it.
     *
     * @return list<string>
     */
    private function statuses(string $franchiseId): array
    {
        $parents = WorkizRecord::query()->where('franchise_id', $franchiseId)->where('type', 'sub_status')->get(['payload'])
            ->toBase()->map(fn (WorkizRecord $r) => $r->payload['parentName'] ?? null);

        return $parents->merge(WorkizJob::query()->where('franchise_id', $franchiseId)->distinct()->pluck('status'))
            ->filter(fn ($s) => is_string($s) && trim($s) !== '')->map(fn ($s) => trim($s))->unique()->values()->all();
    }

    /**
     * Move a job to a status, and a sub-status by its name in that account
     * (GET /jobSubStatus). Workiz's refusal is thrown as it reads: "Must
     * assign technician to perform this action" for a Done Pending Approval
     * with no tech, a Canceled with no sub-status (measured on NY 900018655).
     */
    public function setStatus(WorkizJob $job, string $status, ?string $subStatusName = null): WorkizJob
    {
        [$connection, $id] = $this->v2Job($job);
        // One the branch never used goes as typed: Workiz's own refusal is the answer.
        $word = collect($this->statuses($job->franchise_id))->first(fn (string $s) => strcasecmp($s, trim($status)) === 0) ?? trim($status);
        $body = ['status' => $word];

        if ($subStatusName !== null && trim($subStatusName) !== '') {
            $body['subStatusId'] = $this->named($connection, '/jobSubStatus', $subStatusName, 'sub-status');
        }

        return $this->put($connection, $job, $id, $body, 'workiz.job_status_set', $body + ['sub_status' => $subStatusName]);
    }

    /**
     * The job's source, by the branch's own name (GET /adgroups), as create()
     * sends it (Automations, 2026-11-16: a call to "NJ - PPC" booked as "LS Ads"
     * becomes PPC). A name the branch lacks is refused, never created. The
     * mirror's job_source is set too, since the reports read it.
     */
    public function setSource(WorkizJob $mirror, string $source): WorkizJob
    {
        [$connection, $id] = $this->v2Job($mirror);
        $adGroupId = $this->v2Id($connection, '/adgroups', $source)
            ?? throw new RuntimeException('This branch\'s Workiz has no job source "'.$source.'".');
        $from = $mirror->job_source;

        $this->put($connection, $mirror, $id, ['adGroupId' => $adGroupId], 'workiz.job_source_set', ['from' => $from, 'to' => $source]);
        $mirror->refresh()->forceFill(['job_source' => $source])->save();

        return $mirror;
    }

    /**
     * The job's description, custom fields and all-day, as EzBizi holds them,
     * onto its Workiz copy (EzBizi master, 2026-10-27). The same three create()
     * sends; each measured on 2026-10-27 (all-day needs its end on a later day).
     */
    public function setFields(WorkizJob $mirror, Job $job): WorkizJob
    {
        [$connection, $id] = $this->v2Job($mirror);
        $start = $job->getRawOriginal('scheduled_for');
        $day = $job->all_day && $start !== null ? Carbon::parse($start)->startOfDay() : null;

        return $this->put($connection, $mirror, $id, array_filter([
            'description' => (string) ($job->description ?? ''),
            'customFields' => $this->customFields($job),
            'isAllDay' => $day !== null ? true : null,
            'date' => $day?->toDateString(),
            'endDate' => $day?->copy()->addDay()->toDateString(),
        ], fn ($v) => $v !== null && $v !== []), 'workiz.job_fields_set', []);
    }

    /**
     * Take the job off Workiz's schedule (Jose's Action 1,
     * 2026-10-22: payment in, time removed). Measured on NY's test job
     * 900018655: PUT {isScheduled: false} clears date and endDate; a null or
     * empty date is refused.
     */
    public function unschedule(WorkizJob $job): WorkizJob
    {
        [$connection, $id] = $this->v2Job($job);

        return $this->put($connection, $job, $id, ['isScheduled' => false], 'workiz.job_unscheduled', []);
    }

    /**
     * Make the job's tags exactly these names (Jose, 2026-10-21: "existing
     * tags should be removed"). Each name is the account's own tag (GET
     * /tags), matched without case and spacing, or by one close spelling,
     * since accounts spell them differently. A tag the account lacks is
     * refused, never created. v2 only adds, so the tags to drop are taken off
     * through the web login (WorkizWebSession::removeJobTags); the audit says
     * which came off (`removed`) and which Workiz still holds (`not_removed`).
     *
     * @param  list<string>  $names
     */
    public function setTags(WorkizJob $job, array $names): WorkizJob
    {
        [$connection, $id] = $this->v2Job($job);
        // Resolved first: an unknown name refuses before anything is read or removed.
        $ids = array_values(array_unique(array_map(fn (string $name) => $this->named($connection, '/tags', $name, 'tag'), $names)));
        $read = WorkizV2::send($connection, 'GET', '/jobs/'.rawurlencode($id));

        if ($read['refused'] !== null) {
            throw new RuntimeException('Workiz did not answer for the job: '.$read['refused']);
        }

        $current = $read['data']['data'] ?? $read['data'];
        $current = is_array($current) && array_is_list($current) ? ($current[0] ?? []) : (array) $current;
        $removed = collect($current['tags'] ?? [])->reject(fn ($t) => in_array($t['id'] ?? null, $ids, true))->pluck('name')->values()->all();

        $dropped = $removed === [] ? ['removed' => [], 'refused' => null] : WorkizWebSession::removeJobTags($job->franchise_id, (string) $job->remote_id, $removed);

        return $this->put($connection, $job, $id, ['tags' => $ids], 'workiz.job_tags_set', ['tags' => $names, 'removed' => $dropped['removed'],
            'not_removed' => array_values(array_diff($removed, $dropped['removed'])), 'not_removed_because' => $dropped['refused']]);
    }

    /** @return array{0: SocialConnection, 1: string} */
    private function v2Job(WorkizJob $job): array
    {
        $connection = $this->connection($job->franchise_id);
        $id = $job->payload['v2']['id'] ?? null;

        return match (true) {
            $connection === null => throw new RuntimeException('This branch has no Workiz connection.'),
            ! WorkizV2::uses($connection) => throw new RuntimeException('This branch is not on its Workiz v2 key.'),
            ! is_string($id) || $id === '' => throw new RuntimeException('EzBizi has not read this job through Workiz v2 yet; try again after the next sync.'),
            default => [$connection, $id],
        };
    }

    /** The account's own id for a sub-status or tag named $name. */
    private function named(SocialConnection $connection, string $path, string $name, string $what): string
    {
        $read = WorkizV2::send($connection, 'GET', $path);

        if ($read['refused'] !== null) {
            throw new RuntimeException('Workiz did not list its '.$what.'s: '.$read['refused']);
        }

        $norm = fn ($v) => strtoupper((string) preg_replace('/\s+/', ' ', trim((string) $v)));
        $rows = collect($read['data']['data'] ?? $read['data'] ?? [])->filter(fn ($r) => is_array($r) && isset($r['id'], $r['name']));
        $hit = $rows->first(fn ($r) => $norm($r['name']) === $norm($name));
        $close = $rows->filter(fn ($r) => levenshtein($norm($r['name']), $norm($name)) <= 2);
        $hit ??= $close->count() === 1 ? $close->first() : null;

        return (string) ($hit['id'] ?? throw new RuntimeException('Workiz has no '.$what.' "'.$name.'" in this account.'));
    }

    private function put(SocialConnection $connection, WorkizJob $job, string $id, array $body, string $action, array $metadata): WorkizJob
    {
        $sent = WorkizV2::send($connection, 'PUT', '/jobs/'.rawurlencode($id), $body);

        if ($sent['refused'] !== null) {
            throw new RuntimeException($sent['refused']);
        }

        $this->audit->log($action, $job, metadata: $metadata);

        if (is_array($sent['data'])) {
            WorkizJob::land($connection, WorkizV2::job($sent['data']['data'][0] ?? $sent['data']['data'] ?? $sent['data']));
        }

        return $job->fresh();
    }

    public function connection(string $franchiseId): ?SocialConnection
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

        if (WorkizV2::uses($connection)) {
            return $this->writeV2($connection, $job, $path, $fields, $action, $member);
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

    /**
     * The same three writes through v2: the job by its JOB- id, a technician
     * by their USR- id. An update answers with the whole job; the team calls
     * answer with the technician, so those are re-read.
     *
     * @param  array<string, string>  $fields
     * @return array{job: ?WorkizJob, refused: ?string}
     */
    private function writeV2(SocialConnection $connection, WorkizJob $job, string $path, array $fields, string $action, ?WorkizTeamMember $member): array
    {
        $id = $job->payload['v2']['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return ['job' => null, 'refused' => 'EzBizi has not read this job through Workiz v2 yet; try again after the next sync.'];
        }

        [$method, $call, $body] = match ($path) {
            '/job/update/' => ['PUT', '/jobs/'.$id, ['date' => $fields['JobDateTime'], 'endDate' => $fields['JobEndDateTime']]],
            '/job/assign/' => ['POST', '/jobs/'.$id.'/team', ['technitionId' => (string) $member?->remote_id]],
            '/job/unassign/' => ['DELETE', '/jobs/'.$id.'/team/'.rawurlencode((string) $member?->remote_id), []],
        };

        $sent = WorkizV2::send($connection, $method, $call, $body);

        if ($sent['refused'] !== null) {
            return ['job' => null, 'refused' => $sent['refused']];
        }

        $this->audit->log($action, $job, metadata: $fields);

        $read = $method === 'PUT' && is_array($sent['data'])
            ? WorkizV2::job($sent['data'])
            : $this->workiz->find($connection, $id)['job'];

        if ($read !== null) {
            WorkizJob::land($connection, $read);
        }

        return ['job' => $job->fresh(), 'refused' => null];
    }

    /**
     * POST /v2/jobs: v1's create body in v2's words. v2 requires a job type BY
     * ID, so the plan's type name is looked up in the branch's own list; the
     * member's Workiz client goes by its CL- id when the mirror holds it, and
     * by name, phone and email otherwise.
     *
     * @param  array<string, mixed>  $body  v1's create body
     * @return array{data: mixed, refused: ?string}
     */
    private function createV2(SocialConnection $connection, Job $job, array $body, ?string $jobType, ?string $jobSource): array
    {
        $typeId = $this->v2Id($connection, '/jobtypes', $jobType);

        if ($typeId === null) {
            return ['data' => null, 'refused' => $jobType === null
                ? 'Workiz needs a job type for this branch: set one on the plan.'
                : 'This branch\'s Workiz has no job type "'.$jobType.'".'];
        }

        $clientId = isset($body['ClientId']) ? WorkizJob::query()->where('franchise_id', $job->franchise_id)
            ->where('client_id', $body['ClientId'])->whereNotNull('payload->v2->client_id')->latest('synced_at')
            ->first(['payload'])?->payload['v2']['client_id'] ?? null : null;

        // All-day: Workiz takes it only with an end on a later day (measured 2026-10-27).
        $day = $job->all_day && isset($body['JobDateTime']) ? Carbon::parse($body['JobDateTime'])->startOfDay() : null;

        $sent = WorkizV2::send($connection, 'POST', '/jobs', array_filter([
            'jobTypeId' => $typeId,
            'adGroupId' => $this->v2Id($connection, '/adgroups', $jobSource),
            'date' => $day?->toDateString() ?? $body['JobDateTime'] ?? null,
            'endDate' => $day?->copy()->addDay()->toDateString() ?? $body['JobEndDateTime'] ?? null,
            'isAllDay' => $day !== null ? true : null,
            // A booking with no date goes up unscheduled; v2 otherwise demands one (JOB-58682, 11-12).
            'isScheduled' => isset($body['JobDateTime']) ? null : false,
            'description' => filled($job->description) ? (string) $job->description : null,
            'customFields' => $this->customFields($job),
            'clientInfo' => is_string($clientId) ? ['clientId' => $clientId] : array_filter([
                'firstName' => $body['FirstName'] ?? null,
                'lastName' => $body['LastName'] ?? null,
                'email' => $body['Email'] ?? null,
                'primaryPhone' => $body['Phone'] ?? null,
                'addressDetails' => array_filter([
                    'address' => $body['Address'] ?? null,
                    'unit' => $body['Unit'] ?? null,
                    'city' => $body['City'] ?? null,
                    'state' => $body['State'] ?? null,
                    'zipCode' => $body['PostalCode'] ?? null,
                ]),
            ]),
        ], fn ($value) => $value !== null && $value !== []));

        return $sent['refused'] !== null
            ? ['data' => null, 'refused' => $sent['refused']]
            : ['data' => is_array($sent['data']) ? WorkizV2::job($sent['data']) : null, 'refused' => null];
    }

    /**
     * The job's {name: value} custom fields as Workiz's [{id, value}], by the
     * branch's mirrored custom_field list (names matched without case and
     * edge spaces: Workiz keeps "Pricing "). A name the branch lacks is left out.
     *
     * @return list<array{id: string, value: string}>
     */
    private function customFields(Job $job): array
    {
        $values = array_filter((array) ($job->custom_fields ?? []), fn ($v) => $v !== null && $v !== '');

        if ($values === []) {
            return [];
        }

        $ids = WorkizRecord::query()->where('franchise_id', $job->franchise_id)->where('type', 'custom_field')->get()
            ->mapWithKeys(fn (WorkizRecord $r) => [mb_strtolower(trim((string) ($r->payload['name'] ?? ''))) => $r->remote_id]);
        $out = [];

        foreach ($values as $name => $value) {
            if ($id = $ids[mb_strtolower(trim((string) $name))] ?? null) {
                $out[] = ['id' => $id, 'value' => is_array($value) ? implode(', ', $value) : (string) $value];
            }
        }

        return $out;
    }

    /** A job type's or source's id by its name, from the branch's own v2 list; null when it has none by that name. */
    private function v2Id(SocialConnection $connection, string $list, ?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $read = WorkizV2::send($connection, 'GET', $list, ['pageSize' => 300, 'disabled' => 'false']);

        foreach ($read['data']['data'] ?? [] as $row) {
            if (is_array($row) && mb_strtolower(trim((string) ($row['name'] ?? ''))) === mb_strtolower(trim($name))) {
                return (string) $row['id'];
            }
        }

        return null;
    }
}
