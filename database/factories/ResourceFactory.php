<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ResourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'external_type' => 'inspector',
            'external_id' => fake()->unique()->numberBetween(1, 100000),
        ];
    }
}
