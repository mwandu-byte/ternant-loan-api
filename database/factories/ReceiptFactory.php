<?php

namespace Database\Factories;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    private static int $sequence = 1;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'receipt_no' => sprintf('RC-%d-%06d', now()->year, self::$sequence++),
            'amount' => fake()->randomFloat(2, 1000, 100000),
            'receipt_date' => Carbon::now()->toDateString(),
            'payment_method' => 'cash',
            'reference_no' => null,
            'received_by' => User::factory(),
            'notes' => null,
        ];
    }
}
