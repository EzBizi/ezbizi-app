<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoringActivity;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MonitoringActivityTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_activity_is_swept_after_three_months_measured_from_occurred_at(): void
    {
        $policy = MonitoringActivity::retention();
        $this->assertSame(RetentionPolicy::WINDOW, $policy->kind);
        $this->assertSame(3, $policy->months);
        $this->assertSame('occurred_at', $policy->column);
    }
}
