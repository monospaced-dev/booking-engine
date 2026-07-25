<?php

namespace Database\Factories;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlackoutDateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => null,
            'end_time' => null,
            'note' => 'Holiday',
        ];
    }
}
