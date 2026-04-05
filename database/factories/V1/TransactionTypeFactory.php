<?php

namespace Database\Factories\v1;

use App\Models\v1\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionType>
 */
class TransactionTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = ['Regular', 'HMO', 'Philhealth'];
         return [
        "transaction_type_name"=> $this->faker->word(),
        "customer_full_name"=> $this->faker->name(),
        "customer_id_number"=> $this->faker->unique()->regexify('[0-9]{12}'),
        "coverage_type"=> $this ->faker->randomElement($type),

    ];
    }
}
