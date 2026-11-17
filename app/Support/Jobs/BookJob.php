<?php

namespace App\Support\Jobs;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Numbering\RecordNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A job EzBizi books itself rather than a person typing it in. A member's
 * chosen visit is the first (Memberships, 2026-10-05: "1 month before the 1
 * year mark ... confirm the appointment date for his next visit").
 *
 * It keeps the invariants JobsController::store keeps, so another module never
 * has to: the franchise's next number and its currency, one `job.created`
 * audit row, and `job.created` (plus `job.scheduled` when dated) in the same
 * transaction.
 *
 * Status: the FIRST of the organization's own list, booked and not started,
 * as the automations' follow-up job does. EzBizi never invents a status.
 * Placed by the customer's zip, as the Workiz projection places its jobs,
 * with `service_area_chosen_at` left null because no person chose.
 *
 * It does NOT reach Workiz itself. A caller that wants the job in its branch's
 * Workiz too calls WorkizJobWriter::create() once this has committed
 * (Memberships, for a member's visit).
 */
final class BookJob
{
    public function __construct(
        private readonly RecordNumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /**
     * @param  string|null  $date  The day, `Y-m-d`, on the franchise's calendar.
     *                             Booked for nine that morning; null waits for dispatch.
     * @param  array<string, mixed>  $audit  What booked it (`source`, ids), for the audit row.
     * @param  array<string, mixed>  $fields  The Book panel's own (2026-10-27): status, scheduled_for (exact wall
     *                                       time), duration_minutes, service_area_id, job_type, job_source, description,
     *                                       external_company, custom_fields, all_day, recurrence. Each wins over the default.
     */
    public function __invoke(Customer $customer, Franchise $franchise, string $title, ?string $date, array $audit = [], array $fields = []): Job
    {
        $status = $fields['status'] ?? Job::allowedStatuses($franchise)[0]
            ?? throw new RuntimeException('This organization has no job statuses configured.');
        JobAddress::assertCustomer($customer);

        return DB::transaction(function () use ($customer, $franchise, $title, $date, $audit, $status, $fields) {
            $job = Job::create(array_merge([
                'franchise_id' => $franchise->getKey(),
                'customer_id' => $customer->getKey(),
                'number' => $this->numbers->next(RecordNumberGenerator::JOBS, $franchise),
                'title' => $title,
                'status' => $status,
                // Naive wall clock: FranchiseWallTime reads it back in the franchise's zone.
                'scheduled_for' => $date === null ? null : "{$date} 09:00:00",
                'currency' => strtoupper($franchise->currency ?: 'USD'),
                'service_area_id' => JobServiceArea::resolve($franchise, null, $customer->postal_code),
            ], array_intersect_key($fields, array_flip(['scheduled_for', 'duration_minutes', 'service_area_id', 'job_type', 'job_source',
                'description', 'external_company', 'custom_fields', 'all_day', 'recurrence'])), ['status' => $status]));
            if (isset($fields['service_area_id'])) {
                // A person picked it on the Book panel: Workiz's projection no longer moves it.
                $job->forceFill(['service_area_chosen_at' => now()])->saveQuietly();
            }

            $this->audit->log('job.created', $job, metadata: $audit + ['number' => $job->number]);

            $this->events->emit('job.created', $job, ['after' => $job->only([
                'status', 'title', 'franchise_id', 'customer_id', 'amount_minor',
            ])]);

            if ($job->scheduled_for !== null) {
                $this->events->emit('job.scheduled', $job, [
                    'after' => ['scheduled_for' => $job->scheduled_for->toIso8601String()],
                ]);
            }

            return $job;
        });
    }
}
