<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'SVC-'.bin2hex(random_bytes(4)),
            'name' => 'Chimney sweep',
            'description' => 'A standard sweep.',
            'price_minor' => 18900,
        ];
    }
}
