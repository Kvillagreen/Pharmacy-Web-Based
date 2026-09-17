<?php

namespace Database\Factories\v1;

use App\Models\v1\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionItem>
 */
class TransactionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
    */
    public function definition(): array
    {
        return [
            'medicine_id' => \App\Models\v1\Medicine::factory(),
            'transaction_id' => \App\Models\v1\Transaction::factory(),
            'quantity' => $this->faker->numberBetween(1, 10),
        ];
    }
}
