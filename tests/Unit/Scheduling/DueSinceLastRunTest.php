<?php

namespace Tests\Unit\Scheduling;

use App\Support\Scheduling\DueSinceLastRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Desk 11-19: slow-lane runs start every ~3.5 min, so a 30-min job must fire when due since its last run, not only in minute :00/:30. */
class DueSinceLastRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function at(string $t): CarbonImmutable
    {
        return CarbonImmutable::parse($t, 'UTC');
    }

    public function test_a_run_starting_at_31_still_fires_a_30_minute_job_that_has_not_run(): void
    {
        $every30 = '0,30 * * * *';
        $this->assertTrue(DueSinceLastRun::check('parity', $every30, 'UTC', $this->at('2026-11-19 12:03')), 'first look, 12:00 is recent');
        $this->assertFalse(DueSinceLastRun::check('parity', $every30, 'UTC', $this->at('2026-11-19 12:27')), 'nothing due since');
        $this->assertTrue(DueSinceLastRun::check('parity', $every30, 'UTC', $this->at('2026-11-19 12:31')), 'started at :31: 12:30 has passed, fires');
        $this->assertFalse(DueSinceLastRun::check('parity', $every30, 'UTC', $this->at('2026-11-19 12:34')), 'once per due time');
    }

    public function test_a_nightly_job_is_not_fired_at_deploy_time_but_fires_after_its_hour(): void
    {
        $nightly = '30 4 * * *';
        $this->assertFalse(DueSinceLastRun::check('nightly', $nightly, 'America/New_York', $this->at('2026-11-19 22:00')), 'first look long after 04:30: remembered, not fired');
        $this->assertFalse(DueSinceLastRun::check('nightly', $nightly, 'America/New_York', $this->at('2026-11-20 09:20')), 'before 04:30 New York (09:30 UTC)');
        $this->assertTrue(DueSinceLastRun::check('nightly', $nightly, 'America/New_York', $this->at('2026-11-20 09:33')), 'run started at 04:33 New York');
    }
}
