<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\Membership;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizJobWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A member's confirmed visit, created in their branch's Workiz too, because
 * A1 dispatches there (Jobs and Workiz, 2026-10-05). Queued: create() can wait
 * up to two minutes on the Workiz sync's lock, and the customer is on a page.
 *
 * A branch with no Workiz answers {null, null} and nothing happens. A refusal
 * (a job type the branch's list lacks, say) raises membership.visit_not_in_workiz
 * with Workiz's own sentence, so a person enters it by hand.
 */
final class SendMembershipVisitToWorkiz implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Workiz checks JobSource against each branch's own list and "Membership"
     * is in none of them (measured 2026-10-05: 400). Jose, offered "Recurring
     * Client" or adding "Membership" to every branch: "Leave blank".
     */
    public const JOB_SOURCE = null;

    public function __construct(public readonly string $membershipId) {}

    public function handle(TenantContext $tenant, WorkizJobWriter $writer, EventEmitter $events): void
    {
        $membership = Membership::withoutGlobalScopes()->find($this->membershipId);
        $organization = $membership ? Organization::query()->withoutGlobalScopes()->find($membership->organization_id) : null;

        if ($membership === null || $organization === null || $membership->visit_job_id === null) {
            return;
        }

        $tenant->run($organization, function () use ($membership, $writer, $events) {
            $job = Job::query()->with('customer')->find($membership->visit_job_id);
            if ($job === null) {
                return;
            }

            $refused = $writer->create($job, $membership->plan?->workiz_job_type, self::JOB_SOURCE)['refused'];

            if ($refused !== null) {
                $events->emit('membership.visit_not_in_workiz', $membership, ['after' => [
                    'job_number' => $job->number,
                    'refused'    => $refused,
                ]]);
            }
        });
    }
}
