<?php

namespace Tests\Unit\Hr;

use App\Support\Hr\OnboardingToRecord;
use Tests\TestCase;

/**
 * How a signed contract's free-text terms are read. Anything not read for
 * certain comes back null and stays amber in Records, never a guess.
 */
class OnboardingToRecordTest extends TestCase
{
    public function test_a_day_range_and_one_time_range_become_a_week(): void
    {
        $days = OnboardingToRecord::schedule('Monday - Friday', '8:00 AM - 5:00 PM');

        $this->assertSame(['start' => '08:00', 'end' => '17:00'], $days['monday']);
        $this->assertSame(['start' => '08:00', 'end' => '17:00'], $days['friday']);
        $this->assertNull($days['saturday']);
        $this->assertNull($days['sunday']);
    }

    public function test_other_spellings_read_the_same(): void
    {
        $this->assertSame(
            ['start' => '09:00', 'end' => '17:30'],
            OnboardingToRecord::schedule('Mon thru Sat', '9 - 5:30')['saturday'],
            'A bare "5:30" after a 9 means the afternoon.',
        );

        $list = OnboardingToRecord::schedule('Monday, Wednesday and Friday', '7am to 3pm');
        $this->assertSame(['start' => '07:00', 'end' => '15:00'], $list['wednesday']);
        $this->assertNull($list['tuesday'], 'A listed set is not a range.');
    }

    public function test_what_cannot_be_read_for_certain_is_left_empty(): void
    {
        $this->assertNull(OnboardingToRecord::schedule('As needed', '8:00 AM - 5:00 PM'));
        $this->assertNull(OnboardingToRecord::schedule('Monday - Friday', 'Eight hours a day'));
        $this->assertNull(OnboardingToRecord::schedule('Monday - Friday', '8-12 and 1-5'), 'A split shift.');
        $this->assertNull(OnboardingToRecord::schedule('Friday - Monday', '8-5'), 'A range that wraps.');
    }

    public function test_pay_reads_the_basis_and_refuses_a_period_it_cannot_hold(): void
    {
        $this->assertSame(
            ['basis' => 'hourly', 'amount' => '18.50', 'period' => 'hour'],
            OnboardingToRecord::payRate(['pay_basis' => 'Hourly', 'hourly_rate' => '18.50', 'salary_amount' => '900']),
        );
        $this->assertSame(
            ['basis' => 'salary', 'amount' => '900', 'period' => 'week'],
            OnboardingToRecord::payRate(['pay_basis' => 'Salary', 'salary_amount' => '900', 'salary_period' => 'Weekly']),
        );
        $this->assertNull(
            OnboardingToRecord::payRate(['pay_basis' => 'Salary', 'salary_amount' => '1800', 'salary_period' => 'Bi-weekly']),
            'Every two weeks is not a period a pay rate holds.',
        );
        $this->assertNull(OnboardingToRecord::payRate([]));
    }
}
