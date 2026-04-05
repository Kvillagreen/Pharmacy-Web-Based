<?php

namespace Database\Factories\v1;


use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\v1\Supplier;
/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
         return [
        "supplier_first_name"=> $this->faker->firstName(),
        "supplier_last_name"=> $this->faker->lastName(),
        'supplier_name' => $this->faker->company(),
        'contact_number' =>$this->faker->phoneNumber(),
        'address' =>$this->faker->address(),

    ];
    }
}
