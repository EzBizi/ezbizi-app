<?php

namespace Tests\Feature\TimeClock;

use App\Models\UiString;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeClockUiStringsTest extends TestCase
{
    use RefreshDatabase;

    public function test_clock_in_copy_ships_in_english_and_spanish(): void
    {
        $this->assertSame(2, UiString::query()->where('key', 'time_clock.tab')->count());

        $this->assertSame(
            'Clock In',
            UiString::query()->where('key', 'time_clock.clock_in')->where('locale', 'en')->value('text'),
        );
        $this->assertSame(
            'Clock Out',
            UiString::query()->where('key', 'time_clock.clock_out')->where('locale', 'en')->value('text'),
        );
        $this->assertSame(
            'Work Hours',
            UiString::query()->where('key', 'time_clock.tab')->where('locale', 'en')->value('text'),
        );
        $this->assertSame(
            'Horas de trabajo',
            UiString::query()->where('key', 'time_clock.tab')->where('locale', 'es')->value('text'),
        );
    }

    public function test_schedule_reminder_and_lock_copy_ships_in_english_and_spanish(): void
    {
        foreach ([
            'time_clock.reminder_title', 'time_clock.reminder_body', 'time_clock.extend',
            'time_clock.previous_week', 'time_clock.next_week', 'time_clock.still_in',
            // Day tags, the lock screen's after-hours sentence and the Work
            // Hours menus (2026-09-24).
            'time_clock.tag.office', 'time_clock.tag.home', 'time_clock.tag.absent',
            'time_clock.tag.sick', 'time_clock.tag.holiday', 'time_clock.tag.vacation',
            'time_clock.after_hours',
            'time_clock.action.clock_out_now', 'time_clock.action.edit', 'time_clock.action.delete',
            'time_clock.action.tag', 'time_clock.action.extra', 'time_clock.action.note',
            'time_clock.shift_actions', 'time_clock.day_actions', 'time_clock.delete_confirm',
            'time_clock.extra_time', 'time_clock.no_tag', 'time_clock.hours', 'time_clock.minutes',
            'time_clock.edited', 'time_clock.ended_by_hr',
            'time_clock.lock_sentence', 'time_clock.sign_out', 'time_clock.auto',
            'time_clock.outside', 'time_clock.day_off', 'time_clock.work_schedule',
            'time_clock.not_scheduled', 'time_clock.overnight', 'time_clock.exempt_on',
            'time_clock.exempt_off',
        ] as $key) {
            $this->assertSame(2, UiString::query()->where('key', $key)->count(), $key);
        }

        $this->assertSame(
            'Extend {minutes} min',
            UiString::query()->where('key', 'time_clock.extend')->where('locale', 'en')->value('text'),
        );
    }

    /**
     * What the one-day list took with it, and what survived it (2026-09-17).
     *
     * The second half is the half worth having: four refusal sentences are
     * unreferenced by any call site and are KEPT, because they are the only
     * Spanish those sentences have. A later tidy-up reading only unused
     * would delete them, and this says no.
     */
    public function test_the_day_lists_copy_is_retired_and_the_refusals_are_not(): void
    {
        foreach (['time_clock.empty', 'time_clock.times_in', 'time_clock.in'] as $gone) {
            $this->assertSame(0, UiString::query()->where('key', $gone)->count(), $gone);
        }

        foreach ([
            'time_clock.already_in', 'time_clock.not_in', 'time_clock.off',
            'time_clock.not_scheduled', 'time_clock.auto', 'time_clock.outside',
        ] as $kept) {
            $this->assertSame(2, UiString::query()->where('key', $kept)->count(), $kept);
        }
    }
}
