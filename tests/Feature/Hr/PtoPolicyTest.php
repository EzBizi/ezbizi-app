<?php

namespace Tests\Feature\Hr;

use App\Models\EmploymentEvent;
use App\Support\Hr\PtoPolicy;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What somebody is allowed, and the day it opens.
 *
 * The suggestion this answers asked two things — "how much paid pto we all
 * have AND when it can be used" — and the second is the one nothing in the
 * product could answer. These tests are mostly about that half.
 */
class PtoPolicyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function policy(): PtoPolicy
    {
        return app(PtoPolicy::class);
    }

    private function set(array $tenant, array $values): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $values) {
            foreach ($values as $key => $value) {
                app(SettingsResolver::class)->set($key, $tenant['org'], $value);
            }
        });
    }

    private function started(array $tenant, string $on): void
    {
        $this->inTenant($tenant['org'], fn () => EmploymentEvent::create([
            'organization_id' => $tenant['org']->id,
            'user_id' => $tenant['admin']->id,
            'kind' => EmploymentEvent::STARTED,
            'occurred_on' => $on,
        ]));
    }

    /**
     * Off, and zero days, until a company says otherwise.
     *
     * The defaults are the safe ones rather than the common ones: a company
     * with no written policy must not be shown an allowance it never agreed
     * to, and six days is somebody else's contract.
     */
    public function test_a_company_that_has_said_nothing_is_offered_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertFalse($answer['enabled']);
            $this->assertSame(0, $answer['days_per_year']);
            $this->assertSame(0, $answer['waiting_days']);
            $this->assertFalse($answer['carry_over']);
        });
    }

    /**
     * The waiting period is counted from the day they started.
     *
     * Ninety days from a real start date, which is what a contract means by
     * "after three months of continuous employment".
     */
    public function test_the_eligible_date_is_the_start_date_plus_the_waiting_period(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.days_per_year' => 6, 'pto.waiting_days' => 90]);
        $this->started($t, '2026-08-01');

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertTrue($answer['enabled']);
            $this->assertSame(6, $answer['days_per_year']);
            $this->assertSame('2026-08-01', $answer['started_on']);
            $this->assertSame('2026-10-30', $answer['eligible_from']);

            // Started six weeks ago against a ninety-day wait, so not yet —
            // and this is the assertion that would pass anyway if the date
            // were being invented, which is why the one below exists too.
            $this->assertFalse($answer['eligible']);
        });
    }

    /** And once the date has passed, it is open. */
    public function test_somebody_past_the_waiting_period_may_use_it(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.days_per_year' => 6, 'pto.waiting_days' => 90]);
        $this->started($t, '2026-01-06');

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertSame('2026-04-06', $answer['eligible_from']);
            $this->assertTrue($answer['eligible']);
        });
    }

    /**
     * No start date means the question is unanswerable, and it says so.
     *
     * Everybody on the books before `employment_events` existed has no
     * `started` row, because that migration deliberately refused to invent
     * joining dates from `updated_at`. Computing from an invented date would
     * make somebody eligible three months early or late with nothing ever
     * saying why, so the answer is null and the refusal is to guess.
     */
    public function test_with_no_start_date_the_answer_is_unknown_rather_than_invented(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.days_per_year' => 6, 'pto.waiting_days' => 90]);

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertNull($answer['started_on']);
            $this->assertNull($answer['eligible_from']);

            // NOT eligible, which is the safe direction: being told to wait
            // when you could have booked costs a conversation; being told you
            // may book when the contract says otherwise costs an argument the
            // company has already written down.
            $this->assertFalse($answer['eligible']);
        });
    }

    /**
     * The earliest start wins, not the latest.
     *
     * Somebody who left and was rehired has two `started` rows, and a waiting
     * period counted from the second would make a returning employee of six
     * years wait three months again.
     */
    public function test_a_rehired_person_counts_from_their_first_start(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.waiting_days' => 90]);
        $this->started($t, '2020-03-02');
        $this->started($t, '2026-09-01');

        $this->inTenant($t['org'], function () use ($t) {
            $this->assertSame('2020-03-02', $this->policy()->for($t['admin'])['started_on']);
            $this->assertTrue($this->policy()->for($t['admin'])['eligible']);
        });
    }

    /** A zero wait opens on the first day rather than never. */
    public function test_a_zero_waiting_period_is_open_immediately(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->set($t, ['pto.enabled' => true, 'pto.waiting_days' => 0]);
        $this->started($t, '2026-09-17');

        $this->inTenant($t['org'], function () use ($t) {
            $answer = $this->policy()->for($t['admin']);

            $this->assertSame('2026-09-17', $answer['eligible_from']);
            $this->assertTrue($answer['eligible']);
        });
    }
}
