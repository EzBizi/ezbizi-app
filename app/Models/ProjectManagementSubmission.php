<?php

namespace App\Models;

use App\Http\Controllers\Api\ProjectManagement2Controller;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A job's move from done pending approval to Submitted, by Project
 * Management - 2's Submit or in Workiz (Jose, 2026-11-02: the Submitted tab
 * keeps its jobs for good; the events it was read from expire in 3 months).
 */
#[Fillable(['job_id', 'automation_event_id', 'submitted_at'])]
class ProjectManagementSubmission extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A submission is part of its job, and goes with it.');
    }

    /**
     * Copy the moves recorded since the last one (a day's overlap), or all of
     * history into an empty table, every organization at once. Returns how many
     * were new; a move already recorded is skipped by its event's id.
     */
    public static function record(): int
    {
        $since = DB::table('project_management_submissions')->max('submitted_at');
        $recorded = 0;

        // Each organization's own two statuses (jobs.pm2_*_status).
        foreach (Organization::query()->get() as $organization) {
            [$pending, $submitted] = app(\App\Support\Tenancy\TenantContext::class)
                ->run($organization, fn () => ProjectManagement2Controller::statuses($organization));
            $recorded += DB::affectingStatement(
                'INSERT IGNORE INTO project_management_submissions (id, organization_id, job_id, automation_event_id, submitted_at)
                 SELECT UUID(), e.organization_id, e.subject_id, e.id, e.occurred_at
                 FROM automation_events e JOIN jobs j ON j.id = e.subject_id
                 WHERE e.organization_id = ? AND e.event_key = ? AND e.subject_type = ?
                   AND LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.before.status\')))) = ?
                   AND LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(e.payload, \'$.after.status\')))) = ?'
                .($since === null ? '' : ' AND e.occurred_at >= DATE_SUB(?, INTERVAL 1 DAY)'),
                array_merge([$organization->id, 'job.status_changed', Job::class, $pending, $submitted],
                    $since === null ? [] : [$since]),
            );
        }

        return $recorded;
    }
}
