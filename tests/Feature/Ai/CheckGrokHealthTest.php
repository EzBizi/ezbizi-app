<?php

namespace Tests\Feature\Ai;

use App\Console\Commands\CheckGrokHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CheckGrokHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        Cache::flush();
    }

    public function test_healthy_when_nothing_failed(): void
    {
        $this->assertNull(CheckGrokHealth::problem());
    }

    public function test_healthy_when_a_success_follows_the_failure(): void
    {
        Cache::forever('grok.last_failure', ['at' => now()->subMinutes(60)->toIso8601String(), 'why' => 'exit 1']);
        Cache::forever('grok.last_ok', now()->subMinutes(59)->toIso8601String());
        $this->assertNull(CheckGrokHealth::problem());
    }

    public function test_healthy_while_failing_for_under_30_minutes(): void
    {
        Cache::forever('grok.last_ok', now()->subMinutes(10)->toIso8601String());
        Cache::forever('grok.last_failure', ['at' => now()->toIso8601String(), 'why' => 'exit 1']);
        $this->assertNull(CheckGrokHealth::problem());
    }

    public function test_unhealthy_after_30_minutes_of_failures(): void
    {
        Cache::forever('grok.last_ok', now()->subMinutes(45)->toIso8601String());
        Cache::forever('grok.last_failure', ['at' => now()->toIso8601String(), 'why' => 'exit 1: not logged in']);
        $this->assertStringContainsString('not logged in', CheckGrokHealth::problem());
    }

    public function test_unhealthy_when_it_never_succeeded(): void
    {
        Cache::forever('grok.last_failure', ['at' => now()->toIso8601String(), 'why' => 'exit 1']);
        $this->assertStringContainsString('never', CheckGrokHealth::problem());
    }

    public function test_quiet_when_grok_is_not_configured(): void
    {
        config(['services.grok_cli.home' => null]);
        Cache::forever('grok.last_failure', ['at' => now()->toIso8601String(), 'why' => 'exit 1']);
        $this->assertNull(CheckGrokHealth::problem());
    }

    public function test_alerts_once_per_six_hours(): void
    {
        Cache::forever('grok.last_failure', ['at' => now()->toIso8601String(), 'why' => 'exit 1']);
        $this->artisan('ezbizi:check-grok')->assertFailed();
        $this->assertTrue(Cache::has('grok.alerted'));
    }
}
