<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MonitoredDeviceTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_a_device_is_a_single_overwritten_row_kept_forever(): void
    {
        $this->assertSame(RetentionPolicy::FOREVER, MonitoredDevice::retention()->kind);
    }

    public function test_a_device_is_personal_data_about_its_user(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $device = $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id,
            'user_id'         => $alpha['admin']->getKey(),
        ]));

        $about = $this->inTenant($alpha['org'],
            fn () => MonitoredDevice::personalDataAbout($alpha['admin'])->pluck('id')->all());

        $this->assertContains($device->id, $about);
        // The purpose sentence is the §9 answer, not a colleague note.
        $this->assertNotEmpty(MonitoredDevice::personalDataPurpose());
    }
}
