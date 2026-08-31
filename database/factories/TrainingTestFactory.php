<?php

namespace Database\Factories;

use App\Models\TrainingTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingTest>
 */
class TrainingTestFactory extends Factory
{
    protected $model = TrainingTest::class;

    public function definition(): array
    {
        return [
            'title' => 'Ladder Safety Quiz',
            'status' => TrainingTest::PUBLISHED,
            'questions' => [
                [
                    'prompt' => 'What is the four-to-one rule?',
                    'choices' => [
                        ['text' => 'One foot out for every four feet of height', 'correct' => true],
                        ['text' => 'Four rungs per minute', 'correct' => false],
                    ],
                ],
            ],
        ];
    }
}
