<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'phone' => '+255'.fake()->unique()->numerify('7########'),
            'email' => fake()->unique()->safeEmail(),
            'identification_type' => fake()->randomElement(['NIDA', 'Passport', 'Voter ID', 'Driver License']),
            'identification_number' => fake()->unique()->numerify('##########'),
            'gender' => fake()->randomElement(['male', 'female']),
            'address' => fake()->address(),
            'photo' => null,
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the customer is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }
}
