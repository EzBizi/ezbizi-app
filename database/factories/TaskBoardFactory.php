<?php

namespace Database\Factories;

use App\Models\TaskBoard;
use App\Support\Tasks\BoardVisibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaskBoard> */
class TaskBoardFactory extends Factory
{
    protected $model = TaskBoard::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'visibility' => BoardVisibility::Scope,
        ];
    }
}
