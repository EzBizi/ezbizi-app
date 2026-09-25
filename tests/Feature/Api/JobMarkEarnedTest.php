<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Job::markEarnedIfDue() — the one place `earned_at` is decided.
 *
 * ── Why it moved out of JobsController ───────────────────────────
 *
 * `earned_at` had exactly one writer, inline in JobsController::update, so it
 * was stamped only when a person changed a status through the API. Revenue
 * reads `whereNotNull('earned_at')` with no status clause (b885d666), so any
 * job whose status changed by another route — the Workiz projection following
 * a job to Done — could never reach revenue at all, and a second inlined copy
 * of the rule would let the API and the projection disagree about when money
 * was earned. One method, called by both.
 *
 * ── What the method decides, and what its CALLER decides ─────────
 *
 * The method owns three things: stamp at most once, only for an earned status,
 * and at the moment given. It does NOT decide whether the status just changed —
 * the caller does, because only the caller knows. JobsController calls it only
 * when `status` is dirty, which is what stops a title correction in October
 * from stamping an old completed job into October (§8.1).
 *
 * That call-site check is pinned by
 * test_editing_the_title_of_an_earned_but_unstamped_job_does_not_stamp_it
 * below, and ONLY by it. This docblock first said JobRevenuePeriodTest pinned
 * it; measured, it does not — with the check removed that test stays green,
 * because its job is already stamped and this method's own once-guard keeps
 * it. Only an earned-but-UNSTAMPED job can tell the two guards apart.
 */
class JobMarkEarnedTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_earned_status_is_stamped_at_the_moment_given(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        $job = $this->aJob($alpha, 'completed');
        $at = Carbon::parse('2026-08-14 16:30:00', 'UTC');

        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue($at));

        $this->assertSame('2026-08-14 16:30:00', $job->earned_at?->utc()->toDateTimeString());
    }

    /**
     * A moment given in the franchise's zone is stored as the SAME INSTANT.
     *
     * The test above passes a UTC Carbon, which is the one zone where "stored as
     * the zone it holds" and "stored as UTC" coincide -- so it could not see the
     * defect Workiz hit on 2026-09-24. Laravel formats a Carbon in the zone it
     * is HOLDING, and the column is UTC, so 12:30 in New York went in as 12:30
     * UTC: four hours early, and at the end of a month, in the wrong month. And
     * this method's own docblock told callers to pass exactly that --
     * `Carbon::parse($wall, $this->franchise->timezone)`.
     *
     * 12:30 EDT on 14 August is 16:30 UTC. That is the only value this asserts.
     */
    public function test_a_moment_in_the_franchise_zone_is_stored_as_the_same_instant(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        $job = $this->aJob($alpha, 'completed');
        $inNewYork = Carbon::parse('2026-08-14 12:30:00', 'America/New_York');

        $this->inTenant($alpha['org'], function () use ($job, $inNewYork) {
            $job->markEarnedIfDue($inNewYork);
            $job->save();
        });

        $this->assertSame('2026-08-14 16:30:00',
            $job->fresh()->earned_at?->utc()->toDateTimeString(),
            'The stored instant must be 16:30 UTC, not the New York wall clock read as UTC.');
    }

    public function test_without_a_moment_it_is_stamped_now(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        Carbon::setTestNow('2026-09-24 10:00:00');
        $job = $this->aJob($alpha, 'completed');

        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue());

        $this->assertSame('2026-09-24 10:00:00', $job->earned_at?->utc()->toDateTimeString());
        Carbon::setTestNow();
    }

    /** §8.1: earned once, never re-stamped — a closed month stays closed. */
    public function test_a_job_already_earned_keeps_its_first_moment(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        $job = $this->aJob($alpha, 'completed');
        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue(Carbon::parse('2026-06-01 09:00:00', 'UTC')));
        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue(Carbon::parse('2026-10-01 09:00:00', 'UTC')));

        $this->assertSame('2026-06-01 09:00:00', $job->earned_at?->utc()->toDateTimeString());
    }

    /**
     * A status the organization does not count as earned is never stamped.
     *
     * The subject is a status that EXISTS in the vocabulary and is simply not
     * earned — not an unknown word — so this cannot pass merely because the
     * status failed to resolve.
     */
    public function test_a_status_that_is_not_earned_is_not_stamped(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        $job = $this->aJob($alpha, 'scheduled');

        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue(Carbon::parse('2026-08-14 16:30:00', 'UTC')));

        $this->assertNull($job->earned_at);
    }

    /**
     * Editing anything BUT the status never stamps — the caller's half.
     *
     * This is the case the rest of the suite cannot see, and it is live: 2,256
     * Workiz jobs arrived already `Done` with `earned_at` NULL. If the API
     * stamped on every save rather than only when `status` changes, correcting
     * the title of one would stamp it NOW, and that job's money would jump into
     * the current month. The "a later correction does not move the month" test
     * in JobRevenuePeriodTest does not guard this: its job is already stamped,
     * so markEarnedIfDue()'s own once-guard keeps it with or without the
     * caller's check. Only a job that is earned-but-unstamped can tell them
     * apart.
     */
    public function test_editing_the_title_of_an_earned_but_unstamped_job_does_not_stamp_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        // Already in an earned status, never stamped — an imported Done job.
        $job = $this->aJob($alpha, 'completed');
        $this->assertNull($job->earned_at, 'The fixture: earned status, no stamp yet.');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['title' => 'Corrected title'])
            ->assertOk();

        $this->assertNull($job->fresh()->earned_at,
            'A title edit must not decide when the money was earned.');
    }

    /**
     * Leaving EVERY earned status clears the stamp -- earned_at is not null
     * exactly when the job is earned.
     *
     * The case is real and imminent: Workiz re-reads reaching old closed jobs
     * (147e02fb) will move jobs the stale A1 Tools copy had as Done back to
     * "done pending approval", which Jose ruled is not earned. The backfill
     * stamped them while they read Done. Kept, they would count as revenue that
     * was never earned; Revenue reads whereNotNull('earned_at') with no status
     * clause, so only clearing the stamp takes them out.
     */
    public function test_leaving_every_earned_status_clears_the_stamp(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        $job = $this->aJob($alpha, 'completed');
        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue(Carbon::parse('2026-05-10 14:00:00', 'UTC')));
        $this->assertNotNull($job->earned_at, 'The control: it was earned.');

        $job->status = 'scheduled';
        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue());

        $this->assertNull($job->earned_at);
    }

    /**
     * Coming back into an earned status stamps the NEW moment, not the old one.
     *
     * This is why the old stamp is cleared rather than parked: the moment Workiz
     * really reports Done is the one revenue should date, not the stale copy's.
     */
    public function test_re_entering_an_earned_status_stamps_the_new_moment(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        $job = $this->aJob($alpha, 'completed');
        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue(Carbon::parse('2026-05-10 14:00:00', 'UTC')));

        $job->status = 'scheduled';
        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue());

        $job->status = 'completed';
        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue(Carbon::parse('2026-09-20 11:00:00', 'UTC')));

        $this->assertSame('2026-09-20 11:00:00', $job->earned_at?->utc()->toDateTimeString());
    }

    /**
     * Moving BETWEEN two earned statuses keeps the first moment -- §8.1 still
     * holds for everything that stays earned. Only leaving them all clears.
     */
    public function test_moving_between_two_earned_statuses_keeps_the_first_moment(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed', 'in_progress']);

        $job = $this->aJob($alpha, 'completed');
        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue(Carbon::parse('2026-06-01 09:00:00', 'UTC')));

        $job->status = 'in_progress';
        $this->inTenant($alpha['org'], fn () => $job->markEarnedIfDue(Carbon::parse('2026-10-01 09:00:00', 'UTC')));

        $this->assertSame('2026-06-01 09:00:00', $job->earned_at?->utc()->toDateTimeString());
    }

    /**
     * End to end through the API, and the figure a person reads moves with it.
     *
     * A person moving an earned job out of every earned status through the
     * panel takes it off revenue. The subject here is what Revenue REPORTS, not
     * the column, because a cleared column the widget still counted would be
     * the same bug one layer up.
     */
    public function test_moving_a_job_off_done_through_the_api_takes_it_off_revenue(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        $job = $this->aJob($alpha, 'scheduled');
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['status' => 'completed'])->assertOk();
        $this->assertNotNull($job->fresh()->earned_at, 'The control: completing it stamped it.');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['status' => 'scheduled'])->assertOk();

        $this->assertNull($job->fresh()->earned_at);
    }

    /** @param  list<string>  $statuses */
    private function earnedStatuses(array $tenant, array $statuses): void
    {
        $this->inTenant($tenant['org'], fn () => app(SettingsResolver::class)
            ->set('jobs.earned_statuses', $tenant['org'], $statuses));
    }

    private function aJob(array $tenant, string $status): Job
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $status) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name'   => 'Earned Customer',
            ]);

            return Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id'  => $customer->id,
                'number'   => 'J-'.bin2hex(random_bytes(3)),
                'title'    => 'Sweep',
                'status'   => $status,
                'currency' => 'USD',
            ]);
        });
    }
}
