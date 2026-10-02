<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneHoursSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Saving opening hours.
 *
 * Both cases here were reported live from the same screen: it refused a
 * perfectly good timezone, and it accepted times that were not times.
 */
class PhoneHoursValidationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: \App\Models\User} */
    private function ready(): array
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.settings.view', 'phone.edit']);

        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        return [$t, $connection, $editor];
    }

    /**
     * The screen offers whole-hour offsets rather than a city list, so it
     * sends Etc/GMT+5 — a real zone the rest of the module already stores.
     *
     * Laravel's `timezone` rule refuses it: that rule checks the CANONICAL
     * identifier group and the Etc zones live in the backwards-compatible
     * set. `timezone:all` refuses it too. So saving ANY hours failed, with a
     * message insisting a valid timezone was invalid.
     */
    public function test_a_whole_hour_offset_is_a_timezone_this_module_accepts(): void
    {
        [$t, $connection, $editor] = $this->ready();

        foreach (['Etc/GMT+5', 'Etc/GMT-14', 'UTC'] as $zone) {
            $this->signedInAs($editor)->postJson('/api/v1/phone/hours', [
                'connection_id' => $connection->id,
                'name' => 'Office hours '.$zone,
                'timezone' => $zone,
                'weeks' => ['mon' => [['08:00', '17:00']]],
            ])->assertCreated();
        }

        $this->inTenant($t['org'], fn () => $this->assertSame(
            3,
            PhoneHoursSchedule::query()->count(),
        ));

        // And something that genuinely is not a clock is still refused.
        $this->signedInAs($editor)->postJson('/api/v1/phone/hours', [
            'connection_id' => $connection->id,
            'name' => 'Nonsense',
            'timezone' => 'UTC-5',
            'weeks' => ['mon' => [['08:00', '17:00']]],
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    /**
     * The times were free text and nothing checked them, so "08:00 sd asdasd"
     * saved and HoursClock then compared it as a string — a schedule that
     * looks set and answers wrongly for every call, which is worse than one
     * that refuses to save.
     */
    public function test_a_time_that_is_not_a_time_is_refused(): void
    {
        [$t, $connection, $editor] = $this->ready();

        $cases = [
            'free text' => [['08:00 sd asdasd', '17:00']],
            'not 24 hour' => [['25:00', '26:00']],
            'missing a zero' => [['8:00', '17:00']],
            'backwards' => [['17:00', '08:00']],

            // Opens and shuts in the same instant: reads as open, behaves as
            // closed, and nothing on the screen would ever say why.
            'zero length' => [['09:00', '09:00']],
        ];

        foreach ($cases as $why => $periods) {
            $this->signedInAs($editor)->postJson('/api/v1/phone/hours', [
                'connection_id' => $connection->id,
                'name' => 'Bad — '.$why,
                'timezone' => 'Etc/GMT+5',
                'weeks' => ['mon' => $periods],
            ])->assertStatus(422, "accepted {$why}")->assertJsonValidationErrors('weeks');
        }

        $this->inTenant($t['org'], fn () => $this->assertSame(
            0,
            PhoneHoursSchedule::query()->count(),
            'a schedule with impossible times was stored',
        ));
    }

    /**
     * The list says whether each schedule is open today, which needs today's
     * date where the phone is, not where the reader is (Mia, 2026-10-02).
     * 02:30 UTC on Oct 3 is still Oct 2 in New York.
     */
    public function test_the_list_carries_today_on_the_schedules_own_clock(): void
    {
        [$t, $connection, $editor] = $this->ready();

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-03 02:30:00', 'UTC'));

        try {
            foreach (['America/New_York', 'UTC'] as $zone) {
                $this->signedInAs($editor)->postJson('/api/v1/phone/hours', [
                    'connection_id' => $connection->id,
                    'name' => $zone,
                    'timezone' => $zone,
                    'weeks' => ['fri' => [['09:00', '17:00']]],
                ])->assertCreated();
            }

            $rows = collect($this->signedInAs($editor)->getJson('/api/v1/phone/hours')
                ->assertOk()
                ->json('data'))->keyBy('name');

            $this->assertSame('2026-10-02', $rows['America/New_York']['today']);
            $this->assertSame('2026-10-03', $rows['UTC']['today']);
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }
}
