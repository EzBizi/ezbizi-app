<?php

namespace Tests\Feature;

use App\Support\Scheduling\DueSinceLastRun;
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
        'ezbizi:workiz-web-estimate-links', 'ezbizi:project-workiz', 'ezbizi:gbp-sync', 'ezbizi:review-backlog',
        // 2026-11-16: ~4 min of a 5-min slot; slot 1 timed out at 14:15 UTC.
        'ezbizi:monday-sync'];

    protected function tearDown(): void
    {
        DueSinceLastRun::$fake = null;
        parent::tearDown();
    }

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
        DueSinceLastRun::$fake = true; // lane membership here; timing in DueSinceLastRunTest
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
        // The outbox senders (desk 11-18: four parallel shard jobs) stay in the fast slots.
        putenv('EZBIZI_SCHEDULE_LANE');
        $shards = array_filter($this->app->make(Schedule::class)->events(), fn ($e) => str_starts_with((string) $e->description, 'monday-push-shard-'));
        $this->assertCount(4, $shards);
        foreach ($shards as $e) {
            $this->assertTrue($e->filtersPass($this->app), 'the one-minute outbox stays fast');
        }
        $this->assertTrue($fast['ezbizi:sync-workiz-records'] ?? true);
        $this->assertFalse($slow['ezbizi:sync-workiz-records'] ?? false);
    }
}
