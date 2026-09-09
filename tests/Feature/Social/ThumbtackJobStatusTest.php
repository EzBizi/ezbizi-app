<?php

namespace Tests\Feature\Social;

use App\Jobs\ReportThumbtackJobStatus;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Telling Thumbtack what happened to work that came through them.
 *
 * `setJobStatus()` was written, documented, unit-tested and called by NOTHING
 * for six days, while the Help Center article Thumbtack published for EzBizi
 * told Pros we did it. So the tests that matter most here are the CALLER ones:
 * that changing a job's status reaches the wire at all, down both paths a
 * status can change.
 *
 * The mapping is a tenant setting rather than a table, because Thumbtack's six
 * statuses are fixed and EzBizi's are whatever each organization typed. An
 * unconfigured map reports nothing, which is the honest answer rather than a
 * guess at what "Closed out" means.
 */
class ThumbtackJobStatusTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_changing_a_job_status_queues_a_report_to_thumbtack(): void
    {
        Bus::fake();

        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $job = $this->thumbtackJob($t);

            $job->forceFill(['status' => 'complete'])->save();
        });

        // The caller test. This is the assertion whose absence let setJobStatus
        // sit unreached while its own unit tests passed.
        Bus::assertDispatched(ReportThumbtackJobStatus::class);
    }

    public function test_a_job_saved_without_a_status_change_queues_nothing(): void
    {
        Bus::fake();

        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $job = $this->thumbtackJob($t);

            // A different column. The observer must not treat every save as a
            // status change, or every edit becomes a call to a third party.
            $job->forceFill(['duration_minutes' => 90])->save();
        });

        Bus::assertNotDispatched(ReportThumbtackJobStatus::class);
    }

    public function test_an_unmapped_status_reports_nothing_rather_than_guessing(): void
    {
        $t = $this->provisionTenant('A1-Group');

        Http::fake();

        $this->inTenant($t['org'], function () use ($t) {
            $job = $this->thumbtackJob($t);
            $job->forceFill(['status' => 'complete'])->save();

            // No jobs.thumbtack_status_map set at all — the default.
            $this->runReport($job, $t);
        });

        // Silence, not a guess. "complete" looks obvious to a human and means
        // nothing to a program: another tenant's "complete" is a quote stage.
        Http::assertNothingSent();
    }

    public function test_a_mapped_status_is_sent_with_the_moment_it_changed(): void
    {
        $t = $this->provisionTenant('A1-Group');

        Http::fake(['*/job-status' => Http::response([])]);

        $this->inTenant($t['org'], function () use ($t) {
            $this->mapStatuses($t, ['complete' => 'job_complete']);

            $job = $this->thumbtackJob($t);
            $job->forceFill(['status' => 'complete'])->save();

            $this->runReport($job, $t, changedAt: '2026-09-09T10:00:00+00:00');
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v4/negotiations/neg-1/job-status')
                && $request->data()['status'] === 'job_complete'
                // THEIR clock from OUR record. A retry an hour later must still
                // report when it happened, not when the retry ran.
                && str_starts_with((string) $request->data()['partnerStatusChangedAt'], '2026-09-09T10:00')
                // No money on a status that is not about money.
                && ! isset($request->data()['invoiceAmountInCents']);
        });
    }

    public function test_the_invoice_total_rides_only_with_invoice_paid(): void
    {
        $t = $this->provisionTenant('A1-Group');

        Http::fake(['*/job-status' => Http::response([])]);

        $this->inTenant($t['org'], function () use ($t) {
            $this->mapStatuses($t, ['paid' => 'invoice_paid']);

            $job = $this->thumbtackJob($t);
            $job->forceFill(['status' => 'paid', 'amount_minor' => 24500])->save();

            $this->runReport($job, $t);
        });

        Http::assertSent(fn ($r) => str_contains($r->url(), '/job-status')
            && $r->data()['status'] === 'invoice_paid'
            && $r->data()['invoiceAmountInCents'] === 24500);
    }

    public function test_a_status_mapped_to_something_thumbtack_does_not_have_is_refused_here(): void
    {
        $t = $this->provisionTenant('A1-Group');

        Http::fake();

        $this->inTenant($t['org'], function () use ($t) {
            // Somebody typed a status that is not one of their six.
            $this->mapStatuses($t, ['complete' => 'finished']);

            $job = $this->thumbtackJob($t);
            $job->forceFill(['status' => 'complete'])->save();

            $this->runReport($job, $t);
        });

        // Refused before the wire. Their own refusal names the field and not
        // the value, so it would read as our bug with no clue which status to
        // fix.
        Http::assertNothingSent();
    }

    public function test_an_ordinary_job_that_never_came_from_thumbtack_sends_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');

        Http::fake();

        $this->inTenant($t['org'], function () use ($t) {
            $this->mapStatuses($t, ['complete' => 'job_complete']);

            // A job with no Thumbtack lead behind it — the common case, and the
            // one that must cost nothing.
            $job = $this->bareJob($t);
            $job->forceFill(['status' => 'complete'])->save();

            $this->runReport($job, $t);
        });

        Http::assertNothingSent();
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * Publish the statuses, THEN map them.
     *
     * Both halves are needed and that is the point: ThumbtackJobStatus refuses
     * a mapping for a status the organization does not publish in
     * `jobs.statuses`, so that renaming or removing a status stops it meaning
     * anything to Thumbtack rather than leaving a stale key reporting on. The
     * first draft of these tests set only the map and correctly sent nothing.
     *
     * @param  array<string, string>  $map
     */
    private function mapStatuses(array $t, array $map): void
    {
        $settings = app(\App\Support\Settings\SettingsResolver::class);

        $settings->set('jobs.statuses', $t['org'], array_map(
            fn (string $value) => ['value' => $value, 'label' => ucfirst($value)],
            array_keys($map),
        ));

        $settings->set('jobs.thumbtack_status_map', $t['org'], $map);
    }

    private function runReport(Job $job, array $t, string $changedAt = '2026-09-09T12:00:00+00:00'): void
    {
        (new ReportThumbtackJobStatus(
            (string) $job->getKey(),
            (string) $t['org']->getKey(),
            $changedAt,
        ))->handle(app(\App\Support\Tenancy\TenantContext::class));
    }

    private function bareJob(array $t): Job
    {
        // Built the way LeadsController::book() builds them, numbers and all,
        // rather than with values invented here. A fixture that diverges from
        // the real writer is how a test passes on a shape production never
        // produces — which is the mistake this whole integration already made
        // once with an invented webhook payload.
        $numbers = app(RecordNumberGenerator::class);

        $customer = new Customer;
        $customer->forceFill([
            'organization_id'     => $t['org']->getKey(),
            'owning_franchise_id' => $t['franchise']->getKey(),
            'number'              => $numbers->next(RecordNumberGenerator::CUSTOMERS, $t['franchise']),
            'name'                => 'Ada Sweep',
        ]);
        $customer->save();

        $job = new Job;
        $job->forceFill([
            'organization_id' => $t['org']->getKey(),
            'franchise_id'    => $t['franchise']->getKey(),
            'customer_id'     => $customer->getKey(),
            'number'          => $numbers->next(RecordNumberGenerator::JOBS, $t['franchise']),
            'title'           => 'Chimney sweep',
            'status'          => 'new',
            'currency'        => 'USD',
        ]);
        $job->save();

        return $job;
    }

    private function thumbtackJob(array $t): Job
    {
        $job = $this->bareJob($t);

        $connection = new SocialConnection;
        $connection->forceFill([
            'organization_id' => $t['org']->getKey(),
            'scope_type'      => SocialConnection::SCOPE_COMPANY,
            'scope_id'        => $t['company']->getKey(),
            'provider'        => SocialProvider::Thumbtack->value,
            'remote_id'       => 'biz-1',
            'display_name'    => 'A-1 Chimney on Thumbtack',
        ]);
        $connection->connect(['access_token' => 'a-token', 'refresh_token' => 'r-token']);
        $connection->save();

        $lead = new Lead;
        $lead->forceFill([
            'organization_id' => $t['org']->getKey(),
            'connection_id'   => $connection->getKey(),
            'job_id'          => $job->getKey(),
            'source'          => Lead::SOURCE_THUMBTACK,
            'remote_id'       => 'neg-1',
            'contact_name'    => 'Ada Sweep',
            'status'          => Lead::STATUS_NEW,
            'received_at'     => now(),
        ]);
        $lead->save();

        return $job;
    }
}
