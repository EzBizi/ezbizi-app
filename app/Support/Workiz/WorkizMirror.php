<?php

namespace App\Support\Workiz;

use App\Jobs\MirrorToWorkiz;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use InvalidArgumentException;

/**
 * EzBizi is the master and Workiz a mirror until it is disconnected (Jose,
 * 2026-10-27; replaces "Workiz stays the truth" of 2026-09-25). A change a
 * person or a rule made in EzBizi is queued here and written to the job's
 * Workiz copy by MirrorToWorkiz, through WorkizJobWriter. Never blocks and
 * never throws to the caller: a branch with no Workiz, a disconnected one,
 * or one whose "Mirror to Workiz" is off queues nothing.
 *
 * Callers say what changed:
 *   create      ['job_type' => ?, 'job_source' => ?]   a job Workiz does not have yet
 *   status      ['status' => ..., 'sub_status' => ?]
 *   tags        ['tags' => [...]]                      replaces Workiz's whole list
 *   reschedule  ['start' => 'Y-m-d H:i:s', 'end' => 'Y-m-d H:i:s']   branch wall clock
 *   unschedule  []
 *   assign / unassign  ['workiz_member_id' => WorkizTeamMember id]
 *   fields      ['description' => ?, 'custom_fields' => ?, 'all_day' => ?]  read from the job
 *
 * Customers are not mirrored: neither Workiz API updates a client (v2 has no
 * PUT/PATCH/POST on /clients/{id}, v1 has no client endpoint; measured
 * 2026-10-27). A new customer reaches Workiz with its first job.
 */
final class WorkizMirror
{
    public const OPS = ['create', 'status', 'tags', 'reschedule', 'unschedule', 'assign', 'unassign', 'fields'];

    public static function queue(Job $job, string $op, array $args = []): void
    {
        if (! in_array($op, self::OPS, true)) {
            throw new InvalidArgumentException('Unknown Workiz mirror step: '.$op);
        }

        if (self::connection($job->franchise_id) !== null) {
            MirrorToWorkiz::dispatch($job->organization_id, $job->getKey(), $op, $args)->afterCommit();
        }
    }

    /**
     * The branch's Workiz connection when EzBizi should write to it: on v2,
     * connected, and the branch's "Mirror to Workiz" on. Null otherwise.
     */
    public static function connection(?string $franchiseId): ?SocialConnection
    {
        if ($franchiseId === null) {
            return null;
        }

        $connection = SocialConnection::query()->where('provider', SocialProvider::Workiz->value)
            ->where('scope_type', SocialConnection::SCOPE_FRANCHISE)->where('scope_id', $franchiseId)->first();

        if ($connection === null || $connection->status !== SocialConnection::STATUS_CONNECTED || ! WorkizV2::uses($connection)) {
            return null;
        }

        $franchise = \App\Models\Franchise::query()->find($franchiseId);

        return $franchise !== null && app(SettingsResolver::class)->get('workiz.mirror', $franchise) ? $connection : null;
    }
}
