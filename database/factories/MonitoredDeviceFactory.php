<?php

namespace Database\Factories;

use App\Models\MonitoredDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoredDevice>
 */
class MonitoredDeviceFactory extends Factory
{
    protected $model = MonitoredDevice::class;

    public function definition(): array
    {
        return [
            'device_key'     => $this->faker->uuid(),
            'label'          => 'Shop desktop',
            'platform'       => 'web',
            'capabilities'   => ['activity'],
            'user_agent'     => 'Mozilla/5.0',
            'ip_address'     => $this->faker->ipv4(),
            'last_seen_at'   => now(),
            'current_module' => 'jobs',
        ];
    }
}
