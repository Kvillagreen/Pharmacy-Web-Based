<?php

namespace Database\Factories\v1;

use App\Models\v1\Transaction;
use App\Models\v1\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionType>
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
          return [
            'transaction_type_id' => TransactionType::factory(),
            'user_id' => $this->faker->numberBetween(1,10),
            'branch_id' => $this->faker->numberBetween(1,10),
            'total_amount' => $this->faker->randomFloat(2, 10, 1000),
            'payment_method' => $this->faker->randomElement(['Cash', 'Credit Card', 'Mobile Payment']),
            'sub_total' => $this->faker->randomFloat(2, 10, 1000),
            'change' => $this->faker->randomFloat(2, 0, 100),
            'discount' => $this->faker->randomFloat(2, 0, 100),
            'used_amount' => $this->faker->randomFloat(2, 0, 100),
            'discount_type' => $this->faker->randomElement(['Percentage', 'Fixed Amount']),
            'scpwd_id_number' => $this->faker->optional()->regexify('[0-9]{12}'),

        ];
    }
}
