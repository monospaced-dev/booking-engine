<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::where('name', 'default')->value('id'),
            'external_type' => 'inspector',
            'external_id' => fake()->unique()->numberBetween(1, 100000),
        ];
    }
}
