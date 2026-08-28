<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
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

    public function test_activity_is_personal_data_about_its_user(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $device = $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id,
            'user_id'         => $alpha['admin']->getKey(),
        ]));

        $activity = $this->inTenant($alpha['org'], fn () => MonitoringActivity::factory()->create([
            'organization_id' => $alpha['org']->id,
            'device_id'       => $device->id,
            'user_id'         => $alpha['admin']->getKey(),
        ]));

        $about = $this->inTenant($alpha['org'],
            fn () => MonitoringActivity::personalDataAbout($alpha['admin'])->pluck('id')->all());

        $this->assertContains($activity->id, $about);

        // The instanceof User guard's other branch: a non-User subject is not answerable.
        $this->assertNull(MonitoringActivity::personalDataAbout($device));
    }
}
