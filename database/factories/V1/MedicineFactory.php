<?php

namespace Database\Factories\v1;

use App\Models\v1\Medicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medicine>
 */
class MedicineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = ['Analgesic', 'Biogesic', 'Paracetamol'];

    return [
        "medicine_name"=> $this->faker->colorName(),
        "generic_name"=> $this->faker->firstNameFemale(),
        'category' => $this->faker->randomElement($category),
        'price' =>$this->faker->numberBetween(500,700),
        'reorder_level' =>$this->faker->numberBetween(1,30),
        "is_dangerous"=> $this->faker->numberBetween(0,1),
        "needs_protection"=> $this->faker->numberBetween(0,1),

    ];
}
}
