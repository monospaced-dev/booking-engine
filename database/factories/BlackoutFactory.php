<?php

namespace Database\Factories;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlackoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'during' => '[2026-07-21T17:00:00Z,2026-07-21T18:00:00Z)',
            'note' => null,
        ];
    }
}
