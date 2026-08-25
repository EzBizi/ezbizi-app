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
}
