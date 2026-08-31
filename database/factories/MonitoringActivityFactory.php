<?php

namespace Database\Factories;

use App\Models\MonitoringActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringActivity>
 */
class MonitoringActivityFactory extends Factory
{
    protected $model = MonitoringActivity::class;

    public function definition(): array
    {
        return [
            'module_key'    => 'jobs',
            'dwell_seconds' => 1320,
            'occurred_at'   => now(),
        ];
    }
}
