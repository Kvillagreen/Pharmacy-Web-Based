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
        "first_name"=> $this->faker->firstName(),
        "last_name"=> $this->faker->lastName(),
        'contact_person' => $this->faker->firstName(),
        'contact_number' =>$this->faker->phoneNumber(),
        'address' =>$this->faker->address(),

    ];
    }
}
