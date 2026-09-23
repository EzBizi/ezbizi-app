<?php

namespace Tests\Feature\Monitoring;

use App\Support\Monitoring\ScheduleSummary;
use Tests\TestCase;

/**
 * "Mon – Fri" and "8:00 AM – 5:00 PM" out of a week of hours.
 *
 * The two shapes are the point. `ops.business_hours` is flat with open/close;
 * `users.work_schedule` nests its week under `days` with start/end and carries
 * a timezone beside it. The chart printed both raw and got two different
 * paragraphs in one column, which is what this replaces — so every test here
 * runs the SAME expectation through both shapes where it can.
 */
class ScheduleSummaryTest extends TestCase
{
    /** The organization's hours: flat, open/close, null for closed. */
    private function orgHours(array $days): array
    {
        $week = [];

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $week[$day] = isset($days[$day])
                ? ['open' => $days[$day][0], 'close' => $days[$day][1]]
                : null;
        }

        return $week;
    }

    /** A person's schedule: nested under `days`, start/end, with a zone. */
    private function personSchedule(array $days): array
    {
        $week = [];

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $week[$day] = isset($days[$day])
                ? ['start' => $days[$day][0], 'end' => $days[$day][1]]
                : null;
        }

        return ['timezone' => 'Etc/GMT+5', 'days' => $week];
    }

    public function test_a_normal_week_reads_as_one_range_in_both_shapes(): void
    {
        $weekdays = [
            'monday' => ['08:00', '17:00'],
            'tuesday' => ['08:00', '17:00'],
            'wednesday' => ['08:00', '17:00'],
            'thursday' => ['08:00', '17:00'],
            'friday' => ['08:00', '17:00'],
        ];

        foreach ([$this->orgHours($weekdays), $this->personSchedule($weekdays)] as $schedule) {
            $this->assertSame('Mon – Fri', ScheduleSummary::workDays($schedule));
            $this->assertSame('8:00 AM – 5:00 PM', ScheduleSummary::shift($schedule));
        }
    }

    public function test_non_consecutive_days_are_named_rather_than_ranged(): void
    {
        // "Mon – Fri" for somebody who works three days is a claim about the
        // two they do not — the whole reason this collapses runs rather than
        // taking the first and last worked day.
        $schedule = $this->personSchedule([
            'monday' => ['09:00', '17:00'],
            'wednesday' => ['09:00', '17:00'],
            'friday' => ['09:00', '17:00'],
        ]);

        $this->assertSame('Mon, Wed, Fri', ScheduleSummary::workDays($schedule));
    }

    public function test_two_groups_keep_their_gap(): void
    {
        $schedule = $this->orgHours([
            'monday' => ['08:00', '12:00'],
            'tuesday' => ['08:00', '12:00'],
            'wednesday' => ['08:00', '12:00'],
            'saturday' => ['08:00', '12:00'],
            'sunday' => ['08:00', '12:00'],
        ]);

        // A three-day run ranges; the weekend pair is named, because "Sat – Sun"
        // is longer than "Sat, Sun" and says no more.
        $this->assertSame('Mon – Wed, Sat, Sun', ScheduleSummary::workDays($schedule));
    }

    public function test_hours_that_differ_say_so_instead_of_picking_one(): void
    {
        // THE ONE THAT MATTERS. Printing "8:00 AM – 5:00 PM" here would state a
        // shift this person never works, and it would look exactly like the
        // truth — there is nothing on the row to contradict it.
        $schedule = $this->personSchedule([
            'monday' => ['08:00', '17:00'],
            'tuesday' => ['08:00', '17:00'],
            'wednesday' => ['08:00', '12:00'],
            'thursday' => ['08:00', '17:00'],
            'friday' => ['08:00', '17:00'],
        ]);

        $this->assertSame('Mon – Fri', ScheduleSummary::workDays($schedule));
        $this->assertSame(ScheduleSummary::VARIES, ScheduleSummary::shift($schedule));

        // Control: the identical week with one window DOES produce a time, so
        // the answer above is the difference rather than this never working.
        $same = $this->personSchedule([
            'monday' => ['08:00', '17:00'],
            'tuesday' => ['08:00', '17:00'],
            'wednesday' => ['08:00', '17:00'],
            'thursday' => ['08:00', '17:00'],
            'friday' => ['08:00', '17:00'],
        ]);
        $this->assertSame('8:00 AM – 5:00 PM', ScheduleSummary::shift($same));
    }

    public function test_midnight_and_noon_are_the_two_the_clock_gets_wrong(): void
    {
        $schedule = $this->orgHours(['monday' => ['00:00', '12:00']]);

        $this->assertSame('12:00 AM – 12:00 PM', ScheduleSummary::shift($schedule));
    }

    public function test_nothing_worked_and_nothing_sent_both_answer_null(): void
    {
        $this->assertNull(ScheduleSummary::workDays(null));
        $this->assertNull(ScheduleSummary::shift(null));
        $this->assertNull(ScheduleSummary::workDays($this->orgHours([])));
        $this->assertNull(ScheduleSummary::shift($this->personSchedule([])));

        // A day present but malformed is not a day worked — it is a row the
        // chart cannot speak for, and inventing hours from it would be worse
        // than the dash the client draws for null.
        $this->assertNull(ScheduleSummary::workDays(['monday' => ['open' => '', 'close' => '']]));
    }

    public function test_a_time_it_does_not_recognise_is_shown_rather_than_reformatted(): void
    {
        // Confidently wrong is the failure to avoid: a value this reader does
        // not understand should reach the screen looking odd, not looking fine.
        $schedule = $this->orgHours(['monday' => ['8am', '5pm']]);

        $this->assertSame('8am – 5pm', ScheduleSummary::shift($schedule));
    }
}
