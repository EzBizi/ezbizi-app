<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * 2026-11-12: slot 0 was killed by its 300 s timeout with the slow Workiz and
 * lead pulls lined up in it. They run only in the slow lane now, and every
 * other command only in the fast slots.
 */
class SchedulerLanesTest extends TestCase
{
    private const SLOW = ['ezbizi:sync-leads', 'ezbizi:sync-workiz', 'ezbizi:workiz-web-sales',
        'ezbizi:workiz-web-estimate-links', 'ezbizi:project-workiz'];

    /** @return array<string, bool> command => would its filters pass */
    private function lane(?string $lane): array
    {
        putenv($lane === null ? 'EZBIZI_SCHEDULE_LANE' : "EZBIZI_SCHEDULE_LANE={$lane}");
        $out = [];
        foreach ($this->app->make(Schedule::class)->events() as $e) {
            preg_match('/(ezbizi:[a-z-]+)/', (string) $e->command, $m);
            $out[$m[1] ?? (string) $e->command] = $e->filtersPass($this->app);
        }
        putenv('EZBIZI_SCHEDULE_LANE');

        return $out;
    }

    public function test_flag_off_the_fast_slots_run_everything_and_the_slow_lane_nothing(): void
    {
        config(['ezbizi.scheduler.slow_lane' => false]);
        $fast = $this->lane(null);
        $slow = $this->lane('slow');

        foreach (self::SLOW as $cmd) {
            $this->assertTrue($fast[$cmd], "{$cmd} still runs in the fast slots before the flip");
        }
        $this->assertTrue($fast['ezbizi:leads-reply-overdue']);
        $this->assertSame([], array_keys(array_filter($slow)), 'the slow lane runs nothing before the flip');
    }

    public function test_flag_on_slow_commands_run_only_in_the_slow_lane_and_everything_else_only_in_the_fast_slots(): void
    {
        config(['ezbizi.scheduler.slow_lane' => true]);
        $fast = $this->lane(null);
        $slow = $this->lane('slow');

        foreach (self::SLOW as $cmd) {
            $this->assertArrayHasKey($cmd, $fast, "{$cmd} is scheduled");
            $this->assertFalse($fast[$cmd], "{$cmd} must not run in a fast slot");
            $this->assertTrue($slow[$cmd], "{$cmd} runs in the slow lane");
        }

        // The control: a fast command, and a near-name that must stay fast.
        $this->assertTrue($fast['ezbizi:leads-reply-overdue']);
        $this->assertFalse($slow['ezbizi:leads-reply-overdue']);
        $this->assertTrue($fast['ezbizi:sync-workiz-records'] ?? true);
        $this->assertFalse($slow['ezbizi:sync-workiz-records'] ?? false);
    }
}
