<?php

namespace Database\Factories;

use App\Models\MonitoringScreenshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringScreenshot>
 */
class MonitoringScreenshotFactory extends Factory
{
    protected $model = MonitoringScreenshot::class;

    public function definition(): array
    {
        return [
            'captured_at' => now(),
            'path'        => 'monitoring/screenshots/'.$this->faker->uuid().'.jpg',
            'bytes'       => 20480,
            'width'       => 1920,
            'height'      => 1080,
        ];
    }
}
