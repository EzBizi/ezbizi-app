<?php

namespace Database\Factories;

use App\Models\SavedReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedReport> */
class SavedReportFactory extends Factory
{
    protected $model = SavedReport::class;

    public function definition(): array
    {
        return [
            'name'        => 'Report '.fake()->unique()->numberBetween(1, 1000000),
            'description' => null,
            'definition'  => [
                'root'    => 'jobs',
                'columns' => [['resource' => 'jobs', 'column' => 'number']],
                'filters' => [],
            ],
        ];
    }
}
