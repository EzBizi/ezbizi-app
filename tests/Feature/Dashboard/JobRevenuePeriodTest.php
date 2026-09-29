<?php

namespace Tests\Feature\Dashboard;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Organization;
use App\Support\Dashboard\Widgets\Revenue;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Revenue answers from `earned_at` and from the organization's setting (§8.1, §8.2).
 *
 * Two defects, one commit, because they are the same mistake at two levels.
 *
 * Revenue used to filter on the status a job holds TODAY and bucket it on the
 * date it was BOOKED. Both are read-time answers to a question about the past:
 * a job booked in June and finished in September counted in June, and re-reading
 * a closed month returned a different number every time somebody moved a job
 * along. `earned_at` is stamped once, at the transition, and never re-stamped —
 * so it is the only column that can say what a past month actually was.
 *
 * And the list of statuses that MEANS earned moved to `jobs.earned_statuses`
 * (an organization setting) on 2026-08-28, while the dashboard went on reading
 * each person's own dashboard layout. Two people could disagree about whether a
 * job was revenue, and `earned_at` — stamped from the SETTING — has room for
 * only one answer, so the widget and the stamping could not both be right.
 *
 * The setting tests go through `Revenue` rather than a unit test on
 * `EarnedStatuses` (they went through `JobsGlance` until the dashboard stopped
 * showing money, Jose 2026-09-29): `JobEarnedStatusesTest` was green
 * for three weeks against a class nothing in production called. It proved the
 * class worked and proved nothing about the feature.
 */
class JobRevenuePeriodTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The window that tells the two rules apart.
     *
     * `Revenue` takes a `since` and no upper bound, so "since June" cannot be
     * asked to EXCLUDE September — the first draft of this test asserted that
     * and was wrong about the API rather than about the code. A window opening
     * in JULY is the discriminator: it is after the booking and before the
     * earning, so the old rule (bucket on `created_at`) answers 0 and the new
     * rule (bucket on `earned_at`) answers the full amount.
     */
    public function test_a_job_counts_from_when_it_was_earned_not_from_when_it_was_booked(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        // Booked in June for 300.00, and still not finished.
        Carbon::setTestNow('2026-06-10 09:00:00');
        $job = $this->aJobWorth($alpha, 30000);

        // Finished in September. This is the write that stamps earned_at, so it
        // goes through the endpoint rather than the model — the stamp is the
        // behaviour under test, not a fixture detail.
        Carbon::setTestNow('2026-09-14 16:00:00');
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['status' => 'completed'])
            ->assertOk();

        Carbon::setTestNow('2026-09-30 12:00:00');

        $this->assertSame(
            30000,
            $this->revenueSince($alpha, '2026-07-01'),
            'Work finished in September is September revenue, whenever it was booked.',
        );
    }

    /**
     * §8.1 — a closed month stays closed.
     *
     * The guard this pins is `JobsController::update`'s `earned_at === null`:
     * without it, any later save would re-stamp the job into the current month
     * and a past period would report a different number every time somebody
     * corrected a typo. The failure is silent in both directions — money
     * appearing in a month that is already reconciled, and leaving the one that
     * was right.
     */
    public function test_a_later_correction_does_not_move_the_month_the_money_was_earned(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        Carbon::setTestNow('2026-09-14 16:00:00');
        $job = $this->aJobWorth($alpha, 30000);
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['status' => 'completed'])
            ->assertOk();

        // October: somebody fixes the title. September must keep the money and
        // October must not gain it.
        Carbon::setTestNow('2026-10-05 11:00:00');
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['title' => 'Corrected title'])
            ->assertOk();

        Carbon::setTestNow('2026-10-31 12:00:00');

        $this->assertSame(30000, $this->revenueSince($alpha, '2026-09-01'));
        $this->assertSame(0, $this->revenueSince($alpha, '2026-10-01'),
            'Correcting a job in October does not make it October revenue.');
    }

    /**
     * The half a "does it read earned_at" test cannot see.
     *
     * Switching the filter to `earned_at` alone would pass the test above while
     * still reading each person's private list, because that test never sets a
     * personal one. This gives the two sources DIFFERENT answers and asserts
     * which one wins — the only fixture that can tell them apart.
     */
    public function test_the_glance_reads_the_organization_setting_not_a_personal_dashboard_choice(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->earnedStatuses($alpha, ['completed']);

        $job = $this->aJobWorth($alpha, 12500);
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['status' => 'completed'])
            ->assertOk();

        $admin = $alpha['admin']->fresh();

        // The caller names a status this job never held. Under the old rule it
        // decided the answer, and the figure was 0.
        $result = app(TenantContext::class)->run($admin->organization, fn () => app(Revenue::class)->resolve(
            $admin,
            ['statuses' => ['cancelled'], 'since' => Carbon::now()->subYear()],
        ));

        $this->assertSame(
            [12500],
            array_column($result['rows'], 'amount_minor'),
            'The organization setting decides what is earned, not the caller.',
        );
    }

    /**
     * An organization that has named no earned statuses still gets the prompt.
     *
     * The guard has to survive the move to the setting. Without it the same
     * organization gets a silent zero, which reads as "you earned nothing" —
     * and that is the one revenue answer nobody questions.
     */
    public function test_an_organization_that_has_named_nothing_is_asked_rather_than_told_zero(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $job = $this->aJobWorth($alpha, 9900);
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['status' => 'completed'])
            ->assertOk();

        $admin = $alpha['admin']->fresh();

        $result = app(TenantContext::class)->run($admin->organization, fn () => app(Revenue::class)->resolve(
            $admin,
            ['statuses' => ['completed'], 'since' => Carbon::now()->subYear()],
        ));

        $this->assertTrue($result['needs_configuring']);
        $this->assertSame([], $result['rows']);
    }

    /** @param  list<string>  $statuses */
    private function earnedStatuses(array $tenant, array $statuses): void
    {
        $this->inTenant($tenant['org'], fn () => app(SettingsResolver::class)
            ->set('jobs.earned_statuses', $tenant['org'], $statuses));
    }

    /** A job with one service line worth $minor, so the total is the derived one. */
    private function aJobWorth(array $tenant, int $minor): Job
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $minor) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name'   => 'Revenue Customer',
            ]);

            $job = Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id'  => $customer->id,
                'number'   => 'J-'.bin2hex(random_bytes(3)),
                'title'    => 'Work',
                'status'   => 'scheduled',
                'currency' => 'USD',
            ]);

            $job->lines()->create([
                'organization_id'  => $tenant['org']->id,
                'name'             => 'Sweep',
                'unit_price_minor' => $minor,
                'qty'              => 1,
                'amount_minor'     => $minor,
                'position'         => 0,
            ]);

            $job->recomputeTotal();

            return $job->fresh();
        });
    }

    /**
     * What Revenue reports for the window opening on $date, in minor units.
     *
     * `statuses` is passed deliberately, and it is the same list the setting
     * holds. Under the OLD rule it is what made the job count at all, so
     * omitting it would have produced 0 for both months — a red that says
     * "unconfigured" rather than "wrong month", and one that would have stayed
     * red for the wrong reason after the fix. Under the new rule it is ignored.
     */
    private function revenueSince(array $tenant, string $date): int
    {
        $admin = $tenant['admin']->fresh();

        $rows = app(TenantContext::class)->run(
            Organization::find($tenant['org']->id),
            fn () => app(Revenue::class)->resolve($admin, [
                'statuses' => ['completed'],
                'since'    => Carbon::parse($date),
            ]),
        );

        return (int) ($rows['rows'][0]['amount_minor'] ?? 0);
    }
}
