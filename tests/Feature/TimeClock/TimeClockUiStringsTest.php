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
            'Clock in–out',
            UiString::query()->where('key', 'time_clock.tab')->where('locale', 'en')->value('text'),
        );
        $this->assertSame(
            'Entrada y salida',
            UiString::query()->where('key', 'time_clock.tab')->where('locale', 'es')->value('text'),
        );
    }

    public function test_schedule_reminder_and_lock_copy_ships_in_english_and_spanish(): void
    {
        foreach ([
            'time_clock.reminder_title', 'time_clock.reminder_body', 'time_clock.extend',
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
}
