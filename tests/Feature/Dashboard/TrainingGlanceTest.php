<?php

namespace Tests\Feature\Dashboard;

use App\Models\TrainingAttempt;
use App\Models\TrainingTest;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\TrainingGlance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Training dashboard glance: one count, how many people are due to retake.
 *
 * UI/UX's ruling (2026-09-09) is that the drift objection which kept Training
 * off the home screen is about a SUMMARY and not about a COUNT. These pin the
 * two things that make the count safe to show — it counts PEOPLE rather than
 * assignments, and it is bounded by reach over the person, not merely over
 * the content.
 */
class TrainingGlanceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function resolveFor(User $user): array
    {
        return app(TrainingGlance::class)->resolve($user, DashboardPeriod::for($user), [])->data;
    }

    /**
     * The trap `DashboardController`'s docblock names by name: one person
     * lapsed on TWO tests is ONE person, and a box that summed the in-tab
     * dashboard's per-row counts would say 2 and send somebody looking for
     * a person who does not exist.
     */
    public function test_one_person_lapsed_on_two_tests_counts_once(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $this->aLapsedPass($tenant, $learner, $this->aPublishedTest($tenant, 'Ladders'));
        $this->aLapsedPass($tenant, $learner, $this->aPublishedTest($tenant, 'Roofs'));

        $data = $this->resolveFor($monitor);

        $this->assertFalse($data['empty']);
        $this->assertSame('1 person is due to retake a test.', $data['sentence']);
    }

    /**
     * Two people, so the plural is exercised too — and, with the test above,
     * a count that simply reported the number of lapsed ATTEMPTS could not
     * pass both.
     */
    public function test_two_people_read_as_two(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $test = $this->aPublishedTest($tenant, 'Ladders');

        $this->aLapsedPass($tenant, $this->userWithRole($tenant, 'One', ['training.view']), $test);
        $this->aLapsedPass($tenant, $this->userWithRole($tenant, 'Two', ['training.view']), $test);

        $this->assertSame('2 people are due to retake a test.', $this->resolveFor($monitor)['sentence']);
    }

    /**
     * THE DISCLOSURE CASE. The test is organization-wide, so content scope
     * constrains nothing at all — everybody can see the test. Only reach over
     * the PERSON keeps a Northgate learner's lapse out of a Riverside-only
     * monitor's count, and "4 people are due" is a disclosure about people
     * just as a list of names is. This module has already shipped two
     * cross-franchise disclosures by conflating the two scopes.
     */
    public function test_a_sibling_franchises_person_is_not_counted(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $monitor = $this->userWithRole($tenant, 'RiversideMonitor', ['training.monitor'], onlyAt: $riverside);
        $sibling = $this->userWithRole($tenant, 'NorthgateLearner', ['training.view'], onlyAt: $northgate);

        $this->aLapsedPass($tenant, $sibling, $this->anOrgWideTest($tenant, 'Everyone'));

        $this->assertSame(
            'Nobody is due to retake a test.',
            $this->resolveFor($monitor)['sentence'],
            "A sibling franchise's lapsed learner must not be counted, even on a test the monitor can see.",
        );
    }

    /**
     * The positive control for the test above: the SAME org-wide test and the
     * same monitor, with the lapsed learner in the monitor's own franchise.
     * Without this, a glance that always returned zero would pass the
     * disclosure test perfectly.
     */
    public function test_the_same_org_wide_test_does_count_a_person_in_reach(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $riverside = $tenant['franchise'];
        $this->extraFranchise($tenant, 'Northgate');

        $monitor = $this->userWithRole($tenant, 'RiversideMonitor', ['training.monitor'], onlyAt: $riverside);
        $ours = $this->userWithRole($tenant, 'RiversideLearner', ['training.view'], onlyAt: $riverside);

        $this->aLapsedPass($tenant, $ours, $this->anOrgWideTest($tenant, 'Everyone'));

        $this->assertSame('1 person is due to retake a test.', $this->resolveFor($monitor)['sentence']);
    }

    /**
     * Zero is not empty once tests exist. "Nobody is due" is the good news
     * this box is for, and a box that emptied at zero would move the whole
     * board's layout every time the last person retook something.
     */
    public function test_a_test_with_nobody_due_says_so_rather_than_reading_empty(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $this->aPublishedTest($tenant, 'Ladders');

        $data = $this->resolveFor($monitor);

        $this->assertFalse($data['empty']);
        $this->assertSame('Nobody is due to retake a test.', $data['sentence']);
    }

    /**
     * The headline is the same distinct-PEOPLE count as the sentence: one
     * person lapsed on two of three tests is 1, not 2. Tests tracked follows.
     */
    public function test_figures_are_people_due_then_tests_tracked(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $this->aLapsedPass($tenant, $learner, $this->aPublishedTest($tenant, 'Ladders'));
        $this->aLapsedPass($tenant, $learner, $this->aPublishedTest($tenant, 'Roofs'));
        $this->aPublishedTest($tenant, 'Gutters');

        $this->assertSame([
            ['key' => 'due', 'label' => 'due to retake', 'value' => 1, 'previous' => null],
            ['key' => 'tests', 'label' => 'tests tracked', 'value' => 3, 'previous' => null],
        ], $this->resolveFor($monitor)['figures']);
    }

    /** Somebody due to retake needs somebody; nobody due needs nobody. */
    public function test_people_due_to_retake_are_the_attention_row_and_only_when_there_are_some(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);
        $test = $this->aPublishedTest($tenant, 'Ladders');

        $this->assertArrayNotHasKey('attention', $this->resolveFor($monitor));

        $this->aLapsedPass($tenant, $learner, $test);

        $this->assertSame(
            [['tone' => 'bad', 'text' => '1 person is due to retake a test.']],
            $this->resolveFor($monitor)['attention'],
        );
    }

    public function test_no_tests_in_reach_reads_empty(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);

        $data = $this->resolveFor($monitor);

        $this->assertTrue($data['empty']);
        $this->assertSame('No tests to track.', $data['sentence']);
    }

    /**
     * A still-valid pass is not "due for retaking". Without this, a glance
     * that counted every pass rather than every LAPSED pass would pass every
     * test above.
     */
    public function test_a_pass_that_has_not_lapsed_is_not_counted(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);
        $test = $this->aPublishedTest($tenant, 'Ladders');

        $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => now()->subDay(),
            'expires_on' => now()->addDays(60)->toDateString(),
        ]));

        $this->assertSame('Nobody is due to retake a test.', $this->resolveFor($monitor)['sentence']);
    }

    /**
     * The one word this module never says. HR's own panel counts licence
     * dates the EMPLOYEE renews; this counts our test passes lapsing, which
     * is ours to re-assign — and the two boxes sit on the same screen.
     */
    public function test_the_glance_never_says_certification(): void
    {
        $tenant = $this->provisionTenant('Glance-'.Str::random(8));
        $monitor = $this->userWithRole($tenant, 'Monitor', ['training.monitor']);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $this->aLapsedPass($tenant, $learner, $this->aPublishedTest($tenant, 'Ladders'));

        $this->assertStringNotContainsStringIgnoringCase(
            'certification',
            $this->resolveFor($monitor)['sentence'],
        );
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function twoQuestions(): array
    {
        return [
            ['prompt' => 'One?', 'options' => ['a', 'b'], 'correct' => 0],
            ['prompt' => 'Two?', 'options' => ['a', 'b'], 'correct' => 0],
        ];
    }

    private function aPublishedTest(array $tenant, string $title): TrainingTest
    {
        return $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'title' => $title,
            'status' => TrainingTest::PUBLISHED,
            'questions' => $this->twoQuestions(),
            'valid_for_days' => 30,
        ]));
    }

    /** `company_id`/`franchise_id` both null — organization-wide, visible to everybody (§3). */
    private function anOrgWideTest(array $tenant, string $title): TrainingTest
    {
        return $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => null,
            'franchise_id' => null,
            'title' => $title,
            'status' => TrainingTest::PUBLISHED,
            'questions' => $this->twoQuestions(),
            'valid_for_days' => 30,
        ]));
    }

    private function aLapsedPass(array $tenant, User $learner, TrainingTest $test): TrainingAttempt
    {
        return $this->inTenant($tenant['org'], fn () => TrainingAttempt::create([
            'user_id' => $learner->id,
            'test_id' => $test->id,
            'answers' => [1, 0],
            'score' => 90,
            'passed' => true,
            'taken_at' => now()->subDays(40),
            'expires_on' => now()->subDays(10)->toDateString(),
        ]));
    }
}
