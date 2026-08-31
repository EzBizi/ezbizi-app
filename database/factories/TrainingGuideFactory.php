<?php

namespace Database\Factories;

use App\Models\TrainingGuide;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingGuide>
 */
class TrainingGuideFactory extends Factory
{
    protected $model = TrainingGuide::class;

    public function definition(): array
    {
        return [
            'title' => 'Ladder Safety',
            'status' => TrainingGuide::PUBLISHED,
            'screens' => [
                ['title' => 'Why this exists', 'body' => 'Because falls are preventable.', 'media_kind' => 'none', 'media_url' => null],
            ],
        ];
    }
}
