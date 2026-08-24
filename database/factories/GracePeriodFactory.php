<?php

namespace Database\Factories;

use App\Models\GracePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GracePeriod>
 */
class GracePeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'duration' => 7,
            'unit' => 'days',
            'status' => 'active',
        ];
    }
}
